<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * A deploy ('deploy': server's commit → new commit, by git diff) or a match
 * ('sync': upload the files a compare found different). Steps:
 *
 *   checks → lint → download (backups) → review (waits for you)
 *   → upload → delete → verify → record → site_check → sql (waits for you) → done
 *
 * Nothing on the server changes before you approve the review.
 */
final class DeployRun
{
    public const PHASES = [
        'checks' => 'Safety checks',
        'lint' => 'PHP syntax check',
        'download' => 'Back up server files',
        'db_backup' => 'Back up the database',
        'review' => 'Your review',
        'upload' => 'Upload',
        'delete' => 'Delete removed files',
        'verify' => 'Verify uploads',
        'record' => 'Record version',
        'commands' => 'Commands after deploy',
        'site_check' => 'Site check',
        'sql' => 'SQL',
    ];

    /** Server copy vs. what we expected: [label, badge style]. */
    public const SERVER_STATES = [
        'missing' => ['Not on server', 'neutral'],
        'present' => ['Different on server', 'neutral'],
        'match' => ['Matches expected', 'success'],
        'line_endings' => ['Only line endings differ', 'neutral'],
        'unexpected' => ['Already on server', 'warning'],
        'edited' => ['Edited on server', 'warning'],
        'not_found' => ['Missing on server', 'warning'],
        'extra' => ['On server', 'neutral'],
        'partial' => ['Sent by a partial match', 'success'],
    ];

    public static function create(array $site, string $type, ?string $baseline = null, array $syncFiles = [], ?string $syncTo = null, ?string $compareId = null): array
    {
        if (($open = RunStore::activeRunFor($site['key'])) !== null) {
            throw new \RuntimeException("{$site['name']} already has an open run. Finish or cancel it first.");
        }
        $run = Runner::newRun($type, $site, 'checks') + [
            'baseline' => $baseline,
            'from_recorded' => false,
            'prev_state_raw' => null,
            'new_state_raw' => null,
            'lint_errors' => [],
            'compare_id' => $compareId,
            'delete_approved' => false,
            'reviewed_at' => null,
            'approved_at' => null,
            'finished_at' => null,
            'site_check' => null,
            'rolled_back_by' => null,
            'checkout_warning' => null,
        ];
        $run['to'] = $syncTo;
        $run['files'] = $syncFiles;
        // Speed-ups "Commands after a deploy" and "Database backup": noted now, so the review
        // shows exactly what will run, whatever is changed on the site's page meanwhile.
        $runsCommands = in_array($site['type'], ['ssh', 'folder'], true);
        $run['commands'] = $runsCommands && Settings::on('feature_post_commands') && trim((string) ($site['post_commands'] ?? '')) !== ''
            ? explode("\n", trim((string) $site['post_commands'])) : [];
        $run['commands_done'] = 0;
        $run['db_backup'] = $runsCommands && Settings::on('feature_db_backup') && trim((string) ($site['db_name'] ?? '')) !== ''
            ? ['name' => $site['db_name'], 'size' => null, 'error' => null] : null;
        RunStore::save($run);
        return $run;
    }

    /** The steps a deploy or match goes through: without the database backup or commands when it has none. */
    public static function phases(array $run): array
    {
        $phases = self::PHASES;
        if (empty($run['db_backup'])) {
            unset($phases['db_backup']);
        }
        if (empty($run['commands'])) {
            unset($phases['commands']);
        }
        if (!empty($run['partial'])) {
            $phases['record'] = 'Remember the sent files'; // a partial match keeps the recorded version
        }
        return $phases;
    }

    /**
     * What a deploy to this site would do, read-only (the Deploy page and the assistant):
     * the commit it would deploy, the server's recorded version, and the commits and files
     * in between. Problems are reported in sourceError / stateError, never thrown.
     */
    public static function preview(array $site): array
    {
        $git = Sites::git($site);
        $data = [
            'open' => RunStore::activeRunFor($site['key']),
            'source' => null,
            'sourceError' => null,
            'state' => null,
            'stateError' => null,
            'commits' => [],
            'changes' => null,
        ];
        try {
            $data['source'] = $git->commitInfo(self::sourceCommit($site, $git));
        } catch (\RuntimeException $e) {
            $data['sourceError'] = $e->getMessage();
        }
        try {
            $data['state'] = ServerState::read(Sites::transport($site));
        } catch (\RuntimeException $e) {
            $data['stateError'] = $e->getMessage();
        }
        if ($data['source'] !== null && $data['stateError'] === null) {
            if ($data['state'] === null) {
                $data['commits'] = $git->recentCommits($data['source']['sha'], 30);
            } elseif ($git->resolve($data['state']['commit']) !== null) {
                $commits = $git->recentCommits($data['state']['commit'] . '..' . $data['source']['sha'], 50);
                $data['changes'] = [
                    'commits' => count($commits) < 50 ? count($commits) : $git->countBetween($data['state']['commit'], $data['source']['sha']),
                    'list' => $commits,
                    'files' => $git->changedFiles($data['state']['commit'], $data['source']['sha']),
                    'protected' => ProtectedPaths::regexes(Sites::rootSource($site)),
                ];
            }
        }
        return $data;
    }

    /** The commit a site should get: its local's branch, or what its source site runs. */
    public static function sourceCommit(array $site, Git $git): string
    {
        if (str_starts_with($site['source'], 'local:')) {
            $local = Sources::get(substr($site['source'], 6)) ?? throw new \RuntimeException('The local this site gets its code from no longer exists.');
            return $git->resolve($local['branch'])
                ?? throw new \RuntimeException("Branch {$local['branch']} was not found in {$local['name']}.");
        }
        $source = Sites::get(substr($site['source'], 5))
            ?? throw new \RuntimeException('The site this one gets its code from no longer exists.');
        $state = ServerState::read(Sites::transport($source));
        return $state['commit'] ?? throw new \RuntimeException("{$source['name']} has no recorded version yet. Deploy to it first.");
    }

    public static function advance(array &$run, RunContext $context, float $deadline): void
    {
        match ($run['phase']) {
            'checks' => self::checks($run, $context),
            'lint' => self::lint($run, $context, $deadline),
            'download' => self::download($run, $context, $deadline),
            'upload' => self::upload($run, $context, $deadline),
            'delete' => self::deleteRemoved($run, $context, $deadline),
            'verify' => self::verify($run, $context, $deadline),
            'db_backup' => self::databaseBackup($run, $context),
            'record' => self::record($run, $context),
            'commands' => self::commands($run, $context),
            'site_check' => self::siteCheck($run, $context),
        };
    }

    /**
     * Speed-up "Database backup": a mysqldump of the site's database next to the file backup,
     * before the review (so before anything changes). The login goes to mysqldump as an option
     * file on its stdin (SSH) or in a private temporary file (folder sites), never on the
     * command line where other users of the server could see it.
     */
    private static function databaseBackup(array &$run, RunContext $context): void
    {
        $site = $context->site;
        $runner = $context->transport();
        if (!$runner instanceof CommandRunner) {
            throw new \RuntimeException('This site can no longer run a database backup.');
        }
        $options = "[client]\nuser=" . self::optionValue((string) $site['db_user']) . "\npassword=" . self::optionValue((string) $site['db_password'])
            . "\nhost=" . self::optionValue((string) $site['db_host']) . "\n";
        $raw = Backup::databaseFile($run['id']) . '.part';
        @mkdir(dirname($raw), 0700, true);
        $optionFile = null;
        try {
            if ($runner instanceof SshTransport) {
                $optionArg = '--defaults-extra-file=/dev/stdin';
                $input = $options;
            } else {
                $optionFile = tempnam(sys_get_temp_dir(), 'dtmy');
                file_put_contents($optionFile, $options);
                @chmod($optionFile, 0600);
                $optionArg = '--defaults-extra-file=' . $runner->quote($optionFile);
                $input = '';
            }
            $command = $site['db_dump'] . ' ' . $optionArg . ' --single-transaction --quick --no-tablespaces --routines --triggers -- ' . $runner->quote((string) $site['db_name']);
            [$code, , $stderr] = $runner->exec($command, $input, $raw);
            if ($code !== 0) {
                throw new \RuntimeException('The database backup failed (mysqldump exit code ' . $code . ')' . ($stderr !== '' ? ': ' . mb_substr($stderr, 0, 400) : '.') . ' Nothing was changed on the server. Fix the database settings on the site\'s page, or switch the backup off in Settings → Speed-ups.');
            }
            $size = Backup::compressDatabase($raw, $run['id']);
        } catch (\RuntimeException $e) {
            @unlink($raw);
            $run['db_backup']['error'] = $e->getMessage();
            self::fail($run, $e->getMessage());
            return;
        } finally {
            if ($optionFile !== null) {
                @unlink($optionFile);
            }
        }
        $run['db_backup']['size'] = $size;
        Runner::log($run, 'info', 'Database ' . $site['db_name'] . ' backed up (' . number_format($size / 1024, 1) . ' KB compressed).');
        $run['status'] = 'review';
        $run['phase'] = 'review';
        $run['reviewed_at'] = time();
        Runner::log($run, 'info', 'Waiting for your review.');
    }

    /** A value for a MySQL option file, quoted. */
    private static function optionValue(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * Speed-up "Commands after a deploy": the site's commands, one by one, in its folder on the
     * server, after the files are uploaded and the version recorded. Output goes to the log; a
     * failed command stops the run (the files stay deployed; Roll back undoes them).
     */
    private static function commands(array &$run, RunContext $context): void
    {
        $runner = $context->transport();
        if (!$runner instanceof CommandRunner) {
            throw new \RuntimeException('This site can no longer run commands.');
        }
        while ($run['commands_done'] < count($run['commands'])) {
            $command = $run['commands'][$run['commands_done']];
            Runner::log($run, 'info', "Running: $command");
            [$code, $stdout, $stderr] = $runner->exec($command);
            $output = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
            if ($output !== '') {
                Runner::log($run, $code === 0 ? 'info' : 'error', mb_strlen($output) > 2000 ? '…' . mb_substr($output, -2000) : $output);
            }
            if ($code !== 0) {
                self::fail($run, "The command \"$command\" failed (exit code $code). The files are deployed; fix it and Retry, or Roll back.");
                return;
            }
            $run['commands_done']++;
            RunStore::save($run); // a retry starts after the commands that already ran
        }
        Runner::log($run, 'info', count($run['commands']) . ' command(s) ran.');
        $run['phase'] = 'site_check';
    }

    /** Files whose server copy needs a second look before approving. */
    public static function warnings(array $run): array
    {
        return array_values(array_filter($run['files'], static fn (array $file): bool => in_array($file['server'], ['edited', 'unexpected', 'not_found'], true)
            && !($file['action'] === 'delete' && $file['server'] === 'not_found')));
    }

    /** Returns an error message, or null when approved. */
    public static function approve(array &$run, array $input): ?string
    {
        if ($run['status'] !== 'review') {
            return 'This run is not waiting for your review.';
        }
        if (time() - (int) $run['reviewed_at'] > Settings::reviewMinutes() * 60) {
            return 'This review is more than ' . Settings::reviewMinutes() . ' minutes old, so the backups may be out of date. Cancel it and prepare the deploy again.';
        }
        $warnings = count(self::warnings($run));
        if ($warnings > 0 && empty($input['ack_warnings'])) {
            return "Tick the box to confirm you checked the $warnings warning(s).";
        }
        if ($run['production'] && trim((string) ($input['confirm'] ?? '')) !== $run['site']) {
            return "Type {$run['site']} to confirm the deploy to {$run['site_name']}.";
        }
        $site = Sites::get($run['site']);
        if ($site === null) {
            return 'This site no longer exists.';
        }
        if (ServerState::readRaw(Sites::transport($site)) !== $run['prev_state_raw']) {
            return "The server's recorded version changed since you prepared this (did someone else deploy?). Cancel it and prepare again.";
        }

        $run['delete_approved'] = !$run['production'] && !empty($input['approve_deletes']);
        $run['status'] = 'running';
        $run['phase'] = 'upload';
        $run['approved_at'] = time();
        Runner::log($run, 'info', 'Approved by ' . Settings::get('deployer_name') . '.');
        return null;
    }

    public static function sqlDone(array &$run): void
    {
        $run['status'] = 'done';
        $run['phase'] = 'done';
        $run['finished_at'] = time();
        Runner::log($run, 'info', 'SQL step confirmed. Deploy finished.');
    }

    private static function checks(array &$run, RunContext $context): void
    {
        $git = $context->git();
        $site = $context->site;
        $run['checks'] = [];
        $check = static function (bool $ok, string $text) use (&$run): void {
            $run['checks'][] = [$ok, $text];
        };

        $check(Settings::get('deployer_name') !== '', 'Your name is set in Settings');

        if ($run['type'] === 'deploy') {
            $run['to'] = self::sourceCommit($site, $git);
        }
        $toKnown = $git->resolve($run['to']) !== null;
        $check($toKnown, 'Commit ' . short_sha($run['to']) . ' is in your local repository');

        if ($toKnown) {
            if ($run['type'] === 'deploy' && str_starts_with($site['source'], 'local:')) {
                self::localBranchChecks($git, $context->source(), $run['to'], $check);
            }
            $malware = Safety::malwareFindings($git, $run['to']);
            $check($malware === [], $malware === [] ? 'No malware markers in the commit' : 'Malware markers found: ' . implode('; ', $malware));
        }

        if (Sites::isInsideOwnLocal($site)) {
            $check(false, 'The folder is inside the repository it gets code from. Deploying would overwrite your working files.');
        } elseif (($warning = Sites::checkoutWarning($site)) !== null) {
            $run['checkout_warning'] = $warning;
            Runner::log($run, 'warning', $warning);
        }

        $raw = ServerState::readRaw($context->transport());
        $state = ServerState::parse($raw);
        $run['prev_state_raw'] = $raw;
        // Speed-up "Smarter checks after a deploy": the check pages as they are before anything
        // changes, to compare with afterwards. Only a measurement: never stops the deploy.
        $run['site_check_before'] = SiteCheck::measure($site);
        if ($run['type'] === 'deploy') {
            $run['from'] = $state['commit'] ?? $run['baseline'];
            $run['from_recorded'] = $state !== null;
            if ($run['from'] === null) {
                $check(false, 'The server has no recorded version. Choose the commit it is on, or run a compare first.');
            } else {
                $check($git->resolve($run['from']) !== null, 'The server\'s commit ' . short_sha($run['from']) . ($state !== null ? '' : ' (chosen by you)') . ' is in your local repository');
            }
        }

        if (array_filter($run['checks'], static fn (array $item): bool => !$item[0])) {
            self::fail($run, 'Safety checks failed. Nothing was changed on the server.');
            return;
        }

        if ($run['type'] === 'deploy') {
            if ($run['from'] === $run['to'] && $run['from_recorded']) {
                $run['status'] = 'done';
                $run['phase'] = 'done';
                $run['finished_at'] = time();
                Runner::log($run, 'info', 'The site is already on this commit. Nothing to do.');
                return;
            }
            if ($run['from'] !== $run['to'] && !$git->isAncestor($run['from'], $run['to'])) {
                Runner::log($run, 'warning', 'The new commit does not build on the server\'s commit (going back, or a different line). Files are still compared one by one.');
            }
            $entries = [];
            foreach ($run['from'] === $run['to'] ? [] : $git->changedFiles($run['from'], $run['to']) as [$status, $path]) {
                $entries[] = ['path' => $path, 'change' => match ($status) { 'A' => 'new', 'D' => 'deleted', default => 'changed' }];
            }
        } else {
            $entries = $run['files'];
        }

        $regexes = $context->protectedRegexes();
        $run['files'] = [];
        $run['skipped'] = [];
        foreach ($entries as $entry) {
            if (ProtectedPaths::matches($entry['path'], $regexes)) {
                $run['skipped'][] = ['path' => $entry['path'], 'change' => $entry['change']];
                continue;
            }
            $run['files'][] = [
                'path' => $entry['path'],
                'change' => $entry['change'],
                'action' => $entry['change'] === 'deleted' ? 'delete' : 'upload',
                'server' => null,
                'existed' => null,
                'linted' => false,
                'downloaded' => false,
                'sent' => false,
                'done' => false,
                'verified' => false,
            ];
        }
        Runner::log($run, 'info', sprintf('Safety checks passed. %d file(s) to deploy, %d protected file(s) skipped.', count($run['files']), count($run['skipped'])));
        $run['phase'] = 'lint';
    }

    private static function localBranchChecks(Git $git, array $source, string $to, callable $check): void
    {
        $branch = $source['branch'];
        $remote = $source['remote'];
        $remoteSha = $git->resolve("refs/remotes/$remote/$branch");
        if ($remoteSha === null) {
            $check(false, "$remote/$branch is not known yet. Fetch from GitHub first.");
        } elseif ($remoteSha === $to) {
            $check(true, "Your $branch is the same as GitHub's");
        } else {
            $ahead = $git->countBetween($remoteSha, $to);
            $behind = $git->countBetween($to, $remoteSha);
            if ($ahead > 0) {
                $check(false, "$ahead commit(s) on your $branch are not on GitHub. Push first.");
            }
            if ($behind > 0) {
                $check(false, "GitHub's $branch has $behind commit(s) you don't have. Fetch and check them first.");
            }
        }
        $dirty = $git->currentBranch() === $branch ? count($git->uncommittedFiles()) : 0;
        $check($dirty === 0, $dirty === 0 ? 'No uncommitted changes' : "$dirty file(s) have uncommitted changes. Commit them first.");
    }

    private static function lint(array &$run, RunContext $context, float $deadline): void
    {
        while ($batch = Runner::pick($run['files'], static fn (array $file): bool => !$file['linted'] && $file['action'] === 'upload', 300)) {
            if (microtime(true) >= $deadline) {
                return;
            }
            $phpPaths = [];
            foreach ($batch as $i) {
                if (strtolower(pathinfo($run['files'][$i]['path'], PATHINFO_EXTENSION)) === 'php') {
                    $phpPaths[] = $run['files'][$i]['path'];
                }
            }
            foreach (Linter::checkMany($context->blobs($run['to'], $phpPaths)) as $path => $error) {
                $run['lint_errors'][] = ['path' => (string) $path, 'error' => $error];
            }
            foreach ($batch as $i) {
                $run['files'][$i]['linted'] = true;
            }
        }
        if ($run['lint_errors']) {
            self::fail($run, count($run['lint_errors']) . ' PHP file(s) have syntax errors. Nothing was changed on the server.');
            return;
        }
        Runner::log($run, 'info', 'PHP syntax check passed.');
        $run['phase'] = 'download';
    }

    /** Downloads the server copy of every file the deploy touches: that is the backup. */
    private static function download(array &$run, RunContext $context, float $deadline): void
    {
        $transport = $context->transport();
        $count = 0;
        while ($batch = Runner::pick($run['files'], static fn (array $file): bool => !$file['downloaded'], $transport->batchSize())) {
            if (microtime(true) >= $deadline) {
                self::logCount($run, 'Backed up', $count, 'download');
                return;
            }
            $servers = $transport->getMany(array_map(static fn (int $i): string => $run['files'][$i]['path'], $batch));
            $expected = [];
            if ($run['type'] === 'deploy') {
                $old = array_filter($batch, static fn (int $i): bool => $run['files'][$i]['change'] !== 'new');
                $expected = $context->git()->filesAt($run['from'], array_map(static fn (int $i): string => $run['files'][$i]['path'], $old));
            }
            foreach ($batch as $i) {
                $file = $run['files'][$i];
                $server = $servers[$file['path']];
                if ($server !== null) {
                    Backup::save($run['id'], $file['path'], $server);
                }
                $state = self::serverState($run['type'], $file['change'], $server, $expected[$file['path']] ?? null);
                // A file a partial match sent earlier (speed-up "Compare only some commits") is as the
                // tool left it, not a surprise: the warning would only be noise.
                if (in_array($state, ['edited', 'unexpected', 'not_found', 'line_endings'], true) && $run['type'] === 'deploy'
                    && Partials::expects($run['site'], $file['path'], $server === null ? null : Fingerprint::blobSha($server))) {
                    $state = 'partial';
                }
                $run['files'][$i]['server'] = $state;
                $run['files'][$i]['existed'] = $server !== null;
                $run['files'][$i]['downloaded'] = true;
                $count++;
            }
        }
        self::logCount($run, 'Backed up', $count, 'download');
        $packed = Backup::pack($run);
        Runner::log($run, 'info', $packed > 0
            ? "Backup saved: {$run['id']}.zip ($packed file(s), " . number_format((Backup::size($run['id']) ?? 0) / 1024, 1) . ' KB).'
            : 'No backup needed: none of these files exist on the server yet.');
        if (!empty($run['db_backup'])) {
            $run['phase'] = 'db_backup'; // then the review
            return;
        }
        $run['status'] = 'review';
        $run['phase'] = 'review';
        $run['reviewed_at'] = time();
        Runner::log($run, 'info', 'Waiting for your review.');
    }

    private static function upload(array &$run, RunContext $context, float $deadline): void
    {
        $run['touched'] = true;
        $transport = $context->transport();
        self::uploadInOneGo($run, $context);
        $count = 0;
        while ($batch = Runner::pick($run['files'], static fn (array $file): bool => $file['action'] === 'upload' && !$file['done'], $transport->batchSize())) {
            if (microtime(true) >= $deadline) {
                self::logCount($run, 'Uploaded', $count, 'upload');
                return;
            }
            $errors = $transport->putMany($context->blobs($run['to'], array_map(static fn (int $i): string => $run['files'][$i]['path'], $batch)));
            foreach ($batch as $i) {
                $run['files'][$i]['sent'] = true; // even when it failed: the server copy may have changed, so a rollback restores it
                if (!isset($errors[$run['files'][$i]['path']])) {
                    $run['files'][$i]['done'] = true;
                    $count++;
                }
            }
            if ($errors !== []) {
                self::logCount($run, 'Uploaded', $count, 'upload');
                Runner::failOnErrors($errors, 'uploaded');
            }
        }
        self::logCount($run, 'Uploaded', $count, 'upload');
        $run['phase'] = 'delete';
    }

    /**
     * Speed-up "Upload big FTP deploys in one go" (BundleUpload): tried once per run. Files it
     * couldn't write, or everything when it can't be used, go up file by file afterwards.
     */
    private static function uploadInOneGo(array &$run, RunContext $context): void
    {
        $pending = array_keys(array_filter($run['files'], static fn (array $f): bool => $f['action'] === 'upload' && !$f['done']));
        if (!empty($run['bundle_tried']) || !BundleUpload::suits($context->site, count($pending))) {
            return;
        }
        $run['bundle_tried'] = true;
        foreach ($pending as $i) {
            $run['files'][$i]['sent'] = true; // the server copies may change from here on, so a rollback restores them
        }
        try {
            $errors = BundleUpload::upload($context->transport(), $context->site['url'], $context->blobs($run['to'], array_map(static fn (int $i): string => $run['files'][$i]['path'], $pending)));
        } catch (\RuntimeException $e) {
            Runner::log($run, 'warning', 'Uploading in one go didn\'t work (' . $e->getMessage() . '), so the files go up one by one.');
            return;
        }
        foreach ($pending as $i) {
            if (!isset($errors[$run['files'][$i]['path']])) {
                $run['files'][$i]['done'] = true;
            }
        }
        Runner::log($run, 'info', 'Uploaded ' . (count($pending) - count($errors)) . ' file(s) in one go' . ($errors ? '; ' . count($errors) . ' go up one by one.' : '.'));
    }

    private static function deleteRemoved(array &$run, RunContext $context, float $deadline): void
    {
        $deletes = count(array_filter($run['files'], static fn (array $file): bool => $file['action'] === 'delete'));
        if ($deletes > 0 && !$run['delete_approved']) {
            Runner::log($run, 'info', $run['production']
                ? "$deletes file(s) removed in git were NOT deleted: prod deletes are never automatic. They are in the export list."
                : "$deletes file(s) removed in git were kept: deleting was not approved.");
        }
        if ($deletes > 0 && $run['delete_approved']) {
            $transport = $context->transport();
            $count = 0;
            while ($batch = Runner::pick($run['files'], static fn (array $file): bool => $file['action'] === 'delete' && !$file['done'], $transport->batchSize())) {
                if (microtime(true) >= $deadline) {
                    self::logCount($run, 'Deleted', $count, 'delete');
                    return;
                }
                $errors = $transport->deleteMany(array_map(static fn (int $i): string => $run['files'][$i]['path'], $batch));
                foreach ($batch as $i) {
                    $run['files'][$i]['sent'] = true;
                    if (!isset($errors[$run['files'][$i]['path']])) {
                        $run['files'][$i]['done'] = true;
                        $count++;
                    }
                }
                if ($errors !== []) {
                    self::logCount($run, 'Deleted', $count, 'delete');
                    Runner::failOnErrors($errors, 'deleted');
                }
            }
            self::logCount($run, 'Deleted', $count, 'delete');
        }
        $run['phase'] = 'verify';
    }

    /**
     * Checks every changed file on the server against the commit: downloads it again and
     * compares it byte for byte, or, with the speed-up "Verify SSH uploads by fingerprint",
     * compares git's blob hash the server sends (exact all the same, far less to transfer).
     */
    private static function verify(array &$run, RunContext $context, float $deadline): void
    {
        $transport = $context->transport();
        $byHash = $transport instanceof SshTransport && Settings::on('feature_fast_verify');
        $verb = $byHash ? 'Verified by fingerprint' : 'Verified';
        $count = 0;
        while ($batch = Runner::pick($run['files'], static fn (array $file): bool => $file['done'] && !$file['verified'], $transport->batchSize())) {
            if (microtime(true) >= $deadline) {
                self::logCount($run, $verb, $count, 'verify');
                return;
            }
            $paths = array_map(static fn (int $i): string => $run['files'][$i]['path'], $batch);
            $uploads = array_filter($batch, static fn (int $i): bool => $run['files'][$i]['action'] === 'upload');
            $blobs = $context->blobs($run['to'], array_map(static fn (int $i): string => $run['files'][$i]['path'], $uploads));
            $servers = $byHash ? $transport->hashMany($paths) : $transport->getMany($paths);
            foreach ($batch as $i) {
                $file = $run['files'][$i];
                $server = $servers[$file['path']];
                $expected = $file['action'] === 'upload' ? ($byHash ? Fingerprint::blobSha($blobs[$file['path']]) : $blobs[$file['path']]) : null;
                $ok = $server === $expected;
                if ($ok) {
                    $run['files'][$i]['verified'] = true;
                } else {
                    $run['files'][$i]['done'] = false;
                    Runner::log($run, 'error', "{$file['path']} did not match after the upload.");
                }
                $count++;
            }
        }
        self::logCount($run, $verb, $count, 'verify');

        $pending = count(array_filter($run['files'], static fn (array $file): bool => !$file['done']
            && ($file['action'] === 'upload' || $run['delete_approved'])));
        if ($pending > 0) {
            $run['phase'] = 'upload';
            self::fail($run, "$pending file(s) did not match after upload. Press Retry to upload them again.");
            return;
        }
        Runner::log($run, 'info', 'Every file on the server matches the commit.');
        $run['phase'] = 'record';
    }

    private static function record(array &$run, RunContext $context): void
    {
        if (!empty($run['partial'])) {
            // Speed-up "Compare only some commits": only some commits' files went up, so the site
            // isn't on one commit. Its recorded version stays; the next deploy knows these files.
            Partials::remember($run['site'], $run, $context->git());
            Runner::log($run, 'info', 'Partial match (commits ' . implode(', ', array_map('short_sha', $run['partial']['commits'])) . '): the server\'s recorded version stays as it was, so the next deploy brings the rest.');
            $run['phase'] = !empty($run['commands']) ? 'commands' : 'site_check';
            return;
        }
        $note = $run['type'] === 'sync' ? 'Matched to this commit after a compare' : '';
        $run['new_state_raw'] = ServerState::write($context->transport(), $run['to'], $run['id'], $note);
        Partials::clear($run['site']); // the site is on one commit again
        Runner::log($run, 'info', 'Recorded commit ' . short_sha($run['to']) . ' on the server.');
        $run['phase'] = !empty($run['commands']) ? 'commands' : 'site_check';
    }

    private static function siteCheck(array &$run, RunContext $context): void
    {
        $run['site_check'] = SiteCheck::run($context->site, $run['site_check_before'] ?? []);
        Runner::log($run, $run['site_check']['ok'] ? 'info' : 'error', 'Site check: ' . $run['site_check']['message']);
        $run['status'] = 'sql';
        $run['phase'] = 'sql';
    }

    private static function serverState(string $type, string $change, ?string $server, ?string $expected): string
    {
        if ($type === 'sync' && $change === 'deleted') { // an extra file ticked to delete
            return $server === null ? 'not_found' : 'extra';
        }
        if ($type === 'sync') {
            return $server === null ? 'missing' : 'present';
        }
        if ($change === 'new') {
            return $server === null ? 'missing' : 'unexpected';
        }
        if ($server === null) {
            return 'not_found';
        }
        if ($server === $expected) {
            return 'match';
        }
        if ($expected !== null && str_replace("\r\n", "\n", $server) === str_replace("\r\n", "\n", $expected)) {
            return 'line_endings';
        }
        return 'edited';
    }

    private static function logCount(array &$run, string $verb, int $count, string $phase): void
    {
        if ($count > 0) {
            [$done, $total] = RunView::progress($run, $phase);
            Runner::log($run, 'info', "$verb $count file(s) ($done of $total).");
        }
    }

    private static function fail(array &$run, string $message): void
    {
        $run['status'] = 'failed';
        $run['error'] = $message;
        Runner::log($run, 'error', $message);
    }
}

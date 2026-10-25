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
    public const REVIEW_MINUTES = 60;

    public const PHASES = [
        'checks' => 'Safety checks',
        'lint' => 'PHP syntax check',
        'download' => 'Back up server files',
        'review' => 'Your review',
        'upload' => 'Upload',
        'delete' => 'Delete removed files',
        'verify' => 'Verify uploads',
        'record' => 'Record version',
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
        RunStore::save($run);
        return $run;
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
            'record' => self::record($run, $context),
            'site_check' => self::siteCheck($run, $context),
        };
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
        if (time() - (int) $run['reviewed_at'] > self::REVIEW_MINUTES * 60) {
            return 'This review is more than ' . self::REVIEW_MINUTES . ' minutes old, so the backups may be out of date. Cancel it and prepare the deploy again.';
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
        foreach ($run['files'] as $i => $file) {
            if ($file['linted'] || $file['action'] !== 'upload') {
                continue;
            }
            if (microtime(true) >= $deadline) {
                return;
            }
            if (strtolower(pathinfo($file['path'], PATHINFO_EXTENSION)) === 'php') {
                $error = Linter::check($context->blob($run['to'], $file['path']), $file['path']);
                if ($error !== null) {
                    $run['lint_errors'][] = ['path' => $file['path'], 'error' => $error];
                }
            }
            $run['files'][$i]['linted'] = true;
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
        foreach ($run['files'] as $i => $file) {
            if ($file['downloaded']) {
                continue;
            }
            if (microtime(true) >= $deadline) {
                self::logCount($run, 'Backed up', $count, 'download');
                return;
            }
            $server = $transport->get($file['path']);
            if ($server !== null) {
                Backup::save($run['id'], $file['path'], $server);
            }
            $expected = $run['type'] === 'deploy' && $file['change'] !== 'new' ? $context->git()->fileAt($run['from'], $file['path']) : null;
            $run['files'][$i]['server'] = self::serverState($run['type'], $file['change'], $server, $expected);
            $run['files'][$i]['existed'] = $server !== null;
            $run['files'][$i]['downloaded'] = true;
            $count++;
        }
        self::logCount($run, 'Backed up', $count, 'download');
        $packed = Backup::pack($run);
        Runner::log($run, 'info', $packed > 0
            ? "Backup saved: {$run['id']}.zip ($packed file(s), " . number_format((Backup::size($run['id']) ?? 0) / 1024, 1) . ' KB).'
            : 'No backup needed: none of these files exist on the server yet.');
        $run['status'] = 'review';
        $run['phase'] = 'review';
        $run['reviewed_at'] = time();
        Runner::log($run, 'info', 'Waiting for your review.');
    }

    private static function upload(array &$run, RunContext $context, float $deadline): void
    {
        $run['touched'] = true;
        $transport = $context->transport();
        $count = 0;
        foreach ($run['files'] as $i => $file) {
            if ($file['action'] !== 'upload' || $file['done']) {
                continue;
            }
            if (microtime(true) >= $deadline) {
                self::logCount($run, 'Uploaded', $count, 'upload');
                return;
            }
            $transport->put($file['path'], $context->blob($run['to'], $file['path']));
            $run['files'][$i]['sent'] = true;
            $run['files'][$i]['done'] = true;
            $count++;
        }
        self::logCount($run, 'Uploaded', $count, 'upload');
        $run['phase'] = 'delete';
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
            foreach ($run['files'] as $i => $file) {
                if ($file['action'] !== 'delete' || $file['done']) {
                    continue;
                }
                if (microtime(true) >= $deadline) {
                    self::logCount($run, 'Deleted', $count, 'delete');
                    return;
                }
                $transport->delete($file['path']);
                $run['files'][$i]['sent'] = true;
                $run['files'][$i]['done'] = true;
                $count++;
            }
            self::logCount($run, 'Deleted', $count, 'delete');
        }
        $run['phase'] = 'verify';
    }

    /** Downloads every changed file again and compares it byte for byte. */
    private static function verify(array &$run, RunContext $context, float $deadline): void
    {
        $transport = $context->transport();
        $count = 0;
        foreach ($run['files'] as $i => $file) {
            if (!$file['done'] || $file['verified']) {
                continue;
            }
            if (microtime(true) >= $deadline) {
                self::logCount($run, 'Verified', $count, 'verify');
                return;
            }
            $server = $transport->get($file['path']);
            $ok = $file['action'] === 'upload' ? $server === $context->blob($run['to'], $file['path']) : $server === null;
            if ($ok) {
                $run['files'][$i]['verified'] = true;
            } else {
                $run['files'][$i]['done'] = false;
                Runner::log($run, 'error', "{$file['path']} did not match after the upload.");
            }
            $count++;
        }
        self::logCount($run, 'Verified', $count, 'verify');

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
        $note = $run['type'] === 'sync' ? 'Matched to this commit after a compare' : '';
        $run['new_state_raw'] = ServerState::write($context->transport(), $run['to'], $run['id'], $note);
        Runner::log($run, 'info', 'Recorded commit ' . short_sha($run['to']) . ' on the server.');
        $run['phase'] = 'site_check';
    }

    private static function siteCheck(array &$run, RunContext $context): void
    {
        $run['site_check'] = SiteCheck::run($context->site);
        Runner::log($run, $run['site_check']['ok'] ? 'info' : 'error', 'Site check: ' . $run['site_check']['message']);
        $run['status'] = 'sql';
        $run['phase'] = 'sql';
    }

    private static function serverState(string $type, string $change, ?string $server, ?string $expected): string
    {
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

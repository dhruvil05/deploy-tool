<?php

declare(strict_types=1);

namespace DeployTool\Ai;

use DeployTool\Ai;
use DeployTool\CompareRun;
use DeployTool\DeployRun;
use DeployTool\DiffView;
use DeployTool\LocalStatus;
use DeployTool\ProtectedPaths;
use DeployTool\RollbackRun;
use DeployTool\Runner;
use DeployTool\RunStore;
use DeployTool\RunView;
use DeployTool\ServerState;
use DeployTool\Settings;
use DeployTool\Sites;
use DeployTool\SiteStatus;
use DeployTool\Sources;
use DeployTool\Worker;

/**
 * The actions the assistant may run. Each wraps a feature the tool already has and
 * answers with plain data for the model. Most only read. The few that start work (a
 * compare, preparing a deploy or a match) stop where the tool always stops: a deploy waits
 * at the review, and only the user can approve it. Nothing here approves, rolls back,
 * records a version or changes settings.
 */
final class Tools
{
    /** Longest result handed to the model, so one big answer can't eat the whole budget. */
    private const MAX_RESULT = 8000;

    /** @return list<array{name: string, description: string, parameters: array}> */
    public static function definitions(): array
    {
        $site = ['type' => 'string', 'description' => 'Site key, as list_locals_and_sites shows it.'];
        return [
            ['name' => 'list_locals_and_sites', 'description' => "Lists this chat's local (a git repository with a branch) and its sites, with where each site gets its code from. Other locals and their sites are out of reach in this chat.", 'parameters' => []],
            ['name' => 'local_status', 'description' => "A local's latest commit, how it compares with GitHub, and warnings such as uncommitted changes.", 'parameters' => ['properties' => ['local' => ['type' => 'string', 'description' => 'Local key.']], 'required' => ['local']]],
            ['name' => 'site_status', 'description' => 'Connects to a site (read-only) and reports the commit it runs and how far behind the code it gets is. Takes a few seconds.', 'parameters' => ['properties' => ['site' => $site], 'required' => ['site']]],
            ['name' => 'deploy_preview', 'description' => 'What a deploy to a site would do now: the commit it would deploy, the version on the server, the commits and the changed files in between, and whether a run is already open. Changes nothing.', 'parameters' => ['properties' => ['site' => $site], 'required' => ['site']]],
            ['name' => 'list_runs', 'description' => 'Recent runs (deploys, compares, rollbacks, matches to a commit), newest first.', 'parameters' => ['properties' => [
                'site' => $site,
                'type' => ['type' => 'string', 'enum' => ['deploy', 'sync', 'compare', 'rollback'], 'description' => 'Only this kind of run.'],
                'limit' => ['type' => 'integer', 'description' => 'How many, 1 to 30. Default 10.'],
            ]]],
            ['name' => 'get_run', 'description' => "One run's details: status, commits, files and their state, a compare's result lists, its error and the end of its log.", 'parameters' => ['properties' => ['run_id' => ['type' => 'string']], 'required' => ['run_id']]],
            ['name' => 'file_changes', 'description' => 'The line-by-line changes (unified diff) of one file in a deploy, match or rollback run.', 'parameters' => ['properties' => ['run_id' => ['type' => 'string'], 'path' => ['type' => 'string', 'description' => 'The file path as get_run lists it.']], 'required' => ['run_id', 'path']]],
            ['name' => 'start_compare', 'description' => 'Starts a compare: which server files match a commit, differ, or are missing or extra. Changes no site file (on FTP sites the fingerprint method briefly uploads, calls and deletes a small helper file). Runs in the background: follow with wait_for_run.', 'parameters' => ['properties' => [
                'site' => $site,
                'commit' => ['type' => 'string', 'description' => 'Commit, branch or tag. Default: the commit this site gets from its local or source site.'],
                'method' => ['type' => 'string', 'enum' => ['fingerprint', 'sizes'], 'description' => 'FTP sites only. Default: the one set in Settings.'],
            ], 'required' => ['site']]],
            ['name' => 'prepare_deploy', 'description' => "Prepares a deploy of the commit a site gets: safety checks, a PHP syntax check and a backup of the server's files, then it stops at the review. Nothing on the server changes until the user approves the review on its page. Ask the user first for a production site. Follow with wait_for_run.", 'parameters' => ['properties' => ['site' => $site], 'required' => ['site']]],
            ['name' => 'prepare_match', 'description' => 'From a finished compare, prepares uploading the files that differ or are missing on the server ("Make it match"), or only the paths given. Like a deploy it stops at the review for the user to approve. Ask the user first for a production site. Follow with wait_for_run.', 'parameters' => ['properties' => [
                'compare_run_id' => ['type' => 'string'],
                'paths' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only these files. Default: every different and missing file.'],
                'include_line_endings' => ['type' => 'boolean', 'description' => 'Also upload files that differ only in line endings. Default false.'],
            ], 'required' => ['compare_run_id']]],
            ['name' => 'set_review_files', 'description' => 'Changes which files a deploy or match waiting for review will upload or delete: "exclude" leaves files out (they stay as they are on the server), "include" puts left-out files back. Paths as get_run lists them. Only the files in the review can be changed.', 'parameters' => ['properties' => [
                'run_id' => ['type' => 'string'],
                'exclude' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Paths to leave out.'],
                'include' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Left-out paths to put back.'],
            ], 'required' => ['run_id']]],
            ['name' => 'approve_run', 'description' => "Approves a deploy, match or rollback that is waiting for review, so it runs. Only when the user's latest message clearly approves this run, after you showed them its files and warnings. Never for production sites (those are approved on their page, by typing the site key). Follow with wait_for_run.", 'parameters' => ['properties' => [
                'run_id' => ['type' => 'string'],
                'warnings_checked' => ['type' => 'boolean', 'description' => 'True only if the user saw the run\'s warnings ("Edited on server" and similar) and still wants it.'],
                'delete_files' => ['type' => 'boolean', 'description' => 'Also delete the files removed in git, when the run has any. Default true, as on the review page.'],
            ], 'required' => ['run_id']]],
            ['name' => 'wait_for_run', 'description' => 'Waits for a run to finish its current work (a compare to finish, a deploy or match to reach the review) for up to the given seconds, then returns its details like get_run.', 'parameters' => ['properties' => [
                'run_id' => ['type' => 'string'],
                'seconds' => ['type' => 'integer', 'description' => '5 to 90. Default 60.'],
            ], 'required' => ['run_id']]],
            ['name' => 'fetch_local', 'description' => "Fetches the latest commits of this chat's local from GitHub, then reports how it compares. The user's branches and files don't change.", 'parameters' => []],
            ['name' => 'confirm_sql_done', 'description' => 'Finishes a deploy that is waiting at the SQL step, once the user says the database changes are done or not needed.', 'parameters' => ['properties' => ['run_id' => ['type' => 'string']], 'required' => ['run_id']]],
            ['name' => 'retry_run', 'description' => 'Runs a failed deploy, match, rollback or compare again from where it stopped. Not for production sites. Follow with wait_for_run.', 'parameters' => ['properties' => ['run_id' => ['type' => 'string']], 'required' => ['run_id']]],
            ['name' => 'prepare_rollback', 'description' => "Prepares a rollback of a finished deploy or match: it puts back the server's files from that run's backup. It stops at the review like a deploy. Follow with wait_for_run.", 'parameters' => ['properties' => ['run_id' => ['type' => 'string', 'description' => 'The deploy or match to undo.']], 'required' => ['run_id']]],
            ['name' => 'record_version', 'description' => "Writes a finished compare's commit as the server's version, so later deploys start from it. Not for production sites.", 'parameters' => ['properties' => [
                'compare_run_id' => ['type' => 'string'],
                'accept_differences' => ['type' => 'boolean', 'description' => 'Needed when the compare found different or missing files: true only if the user accepts them as they are.'],
            ], 'required' => ['compare_run_id']]],
            ['name' => 'cancel_run', 'description' => 'Cancels a run waiting for review, or one that failed before changing anything on the server.', 'parameters' => ['properties' => ['run_id' => ['type' => 'string']], 'required' => ['run_id']]],
            ['name' => 'protect_paths', 'description' => "Adds paths to this chat's local's protected paths, so they are never uploaded, overwritten, deleted or read on its sites. End folders with /; * stands for one folder name.", 'parameters' => ['properties' => ['paths' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['paths']]],
            ['name' => 'site_settings', 'description' => "A site's settings (address, login name, folder, where it gets its code from…). Passwords are never shown, only whether one is saved.", 'parameters' => ['properties' => ['site' => $site], 'required' => ['site']]],
            ['name' => 'add_site', 'description' => "Adds a site to this chat's local's pipeline. Never ask for or pass a password: an FTP site is saved without one, and the user adds it on the site's page.", 'parameters' => ['properties' => [
                'key' => ['type' => 'string', 'description' => 'Short id, 2 to 40 lowercase letters, numbers or dashes, e.g. nova-staging. It can\'t change later.'],
            ] + self::SITE_FIELDS, 'required' => ['key', 'name', 'type']]],
            ['name' => 'edit_site', 'description' => "Changes some settings of one of this chat's sites; fields not given stay as they are. It can't set a password, unmark production, or change the key.", 'parameters' => ['properties' => [
                'site' => $site,
                'changes' => ['type' => 'object', 'properties' => self::SITE_FIELDS, 'description' => 'Only the fields to change.'],
            ], 'required' => ['site', 'changes']]],
            ['name' => 'add_local', 'description' => 'Adds a git repository on this computer as a new local. The user then starts a new chat with it picked to work with it.', 'parameters' => ['properties' => [
                'key' => ['type' => 'string', 'description' => 'Short id, 2 to 40 lowercase letters, numbers or dashes. It can\'t change later.'],
                'name' => ['type' => 'string'],
                'repo_path' => ['type' => 'string', 'description' => 'The repository folder, e.g. C:\\laragon\\www\\shop.'],
                'branch' => ['type' => 'string', 'description' => 'The branch its first site gets.'],
                'remote' => ['type' => 'string', 'description' => 'Default origin.'],
            ], 'required' => ['key', 'name', 'repo_path', 'branch']]],
            ['name' => 'edit_local', 'description' => "Changes this chat's local: its name, repository folder, branch or remote. Fields not given stay as they are.", 'parameters' => ['properties' => [
                'changes' => ['type' => 'object', 'properties' => [
                    'name' => ['type' => 'string'],
                    'repo_path' => ['type' => 'string'],
                    'branch' => ['type' => 'string'],
                    'remote' => ['type' => 'string'],
                ]],
            ], 'required' => ['changes']]],
        ];
    }

    /** The site settings the assistant may set. The password is deliberately missing. */
    private const SITE_FIELDS = [
        'name' => ['type' => 'string'],
        'type' => ['type' => 'string', 'enum' => ['ftp', 'ssh', 'folder'], 'description' => 'ftp or ssh for a server, folder for a folder on this computer (testing).'],
        'gets_code_from' => ['type' => 'string', 'description' => "local:<key> for this chat's local, or site:<key> for one of its sites (e.g. site:staging for a prod site). Default: the local."],
        'production' => ['type' => 'boolean', 'description' => 'A production site: deploys need the typed site key and never delete files.'],
        'url' => ['type' => 'string', 'description' => 'Site address, https://…'],
        'check_path' => ['type' => 'string', 'description' => 'Page loaded after every deploy, e.g. /login. Empty: no check.'],
        'host' => ['type' => 'string', 'description' => 'FTP host.'],
        'port' => ['type' => 'integer', 'description' => 'FTP port, usually 21.'],
        'username' => ['type' => 'string', 'description' => 'FTP username.'],
        'root' => ['type' => 'string', 'description' => 'FTP folder holding index.php, from where the login lands. / when it lands there.'],
        'connections' => ['type' => 'integer', 'description' => 'FTP connections at once, 1 to 8.'],
        'verify_tls' => ['type' => 'boolean', 'description' => "Check the FTP server's certificate (off for IP addresses)."],
        'ssh_host' => ['type' => 'string'],
        'ssh_port' => ['type' => 'integer'],
        'ssh_user' => ['type' => 'string'],
        'ssh_key' => ['type' => 'string', 'description' => 'Path of the private key file on this computer; empty for the default key.'],
        'ssh_root' => ['type' => 'string', 'description' => 'Folder holding index.php, starting with / or ~/.'],
        'ssh_php' => ['type' => 'string', 'description' => 'PHP command on the server, usually php.'],
        'path' => ['type' => 'string', 'description' => 'Folder sites: the folder on this computer.'],
    ];

    /** action => the permission it needs (Ai::PERMISSIONS); actions not listed only read. */
    public const NEEDS = [
        'fetch_local' => 'fetch',
        'start_compare' => 'compare',
        'prepare_deploy' => 'prepare',
        'prepare_match' => 'prepare',
        'set_review_files' => 'review_files',
        'approve_run' => 'approve',
        'confirm_sql_done' => 'sql',
        'retry_run' => 'retry',
        'prepare_rollback' => 'rollback',
        'record_version' => 'record',
        'cancel_run' => 'cancel',
        'protect_paths' => 'protect',
        'add_site' => 'add_site',
        'edit_site' => 'edit_site',
        'add_local' => 'add_local',
        'edit_local' => 'edit_local',
    ];

    /** The actions offered to the model: every read, and the rest only when allowed. */
    public static function allowedDefinitions(): array
    {
        return array_values(array_filter(self::definitions(), static fn (array $tool): bool => !isset(self::NEEDS[$tool['name']]) || Ai::can(self::NEEDS[$tool['name']])));
    }

    /**
     * The local the current chat is about. Every action sees only it, its sites and their
     * runs, so a chat about Nova can't touch Backoffice's sites. Null: no limit.
     */
    private static ?string $local = null;

    /**
     * Runs one action for a chat about $local. Mistakes (unknown site, missing run, a site
     * of another local) come back as an error for the model to read, never as an exception.
     *
     * @return array{result: string, ok: bool, label: string, link: ?string, run: ?string} run: the run it is about, if any
     */
    public static function run(string $name, array $arguments, ?string $local = null): array
    {
        self::$local = $local;
        $label = self::label($name, $arguments);
        try {
            // Checked here too, not only by leaving the action out of the list.
            if (isset(self::NEEDS[$name]) && !Ai::can(self::NEEDS[$name])) {
                throw new \RuntimeException('Not allowed: the user has not allowed "' . Ai::PERMISSIONS[self::NEEDS[$name]][0] . '" in Settings → AI permissions. Tell them where to do it themselves instead.');
            }
            [$data, $link] = match ($name) {
                'fetch_local' => [self::fetchLocal(), 'index.php'],
                'confirm_sql_done' => self::confirmSqlDone(self::text($arguments, 'run_id')),
                'retry_run' => self::retryRun(self::text($arguments, 'run_id')),
                'prepare_rollback' => self::prepareRollback(self::text($arguments, 'run_id')),
                'record_version' => self::recordVersion(self::text($arguments, 'compare_run_id'), !empty($arguments['accept_differences'])),
                'cancel_run' => self::cancelRun(self::text($arguments, 'run_id')),
                'protect_paths' => [self::protectPaths($arguments), self::$local !== null ? 'index.php?r=source-edit&key=' . urlencode(self::$local) : null],
                'site_settings' => [self::siteSettings(self::site($arguments)), 'index.php?r=site-edit&key=' . urlencode(self::text($arguments, 'site'))],
                'add_site' => self::saveSite(null, $arguments),
                'edit_site' => self::saveSite(self::site($arguments), (array) ($arguments['changes'] ?? [])),
                'add_local' => self::saveLocal(null, $arguments),
                'edit_local' => self::saveLocal(self::$local, (array) ($arguments['changes'] ?? [])),
                'start_compare' => self::startCompare(self::site($arguments), $arguments),
                'prepare_deploy' => self::prepareDeploy(self::site($arguments)),
                'prepare_match' => self::prepareMatch(self::text($arguments, 'compare_run_id'), $arguments),
                'wait_for_run' => self::waitForRun(self::text($arguments, 'run_id'), (int) ($arguments['seconds'] ?? 60)),
                'set_review_files' => self::setReviewFiles(self::text($arguments, 'run_id'), $arguments),
                'approve_run' => self::approveRun(self::text($arguments, 'run_id'), $arguments),
                'list_locals_and_sites' => [self::listAll(), null],
                'local_status' => [self::localStatus(self::text($arguments, 'local')), null],
                'site_status' => [self::siteStatus(self::site($arguments)), null],
                'deploy_preview' => [self::deployPreview(self::site($arguments)), null],
                'list_runs' => [self::listRuns($arguments), 'index.php?r=history'],
                'get_run' => self::getRun(self::text($arguments, 'run_id')),
                'file_changes' => self::fileChanges(self::text($arguments, 'run_id'), self::text($arguments, 'path')),
                default => throw new \RuntimeException("There is no action called $name."),
            };
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (strlen($json) > self::MAX_RESULT) {
                $json = substr($json, 0, self::MAX_RESULT) . '… [cut: the result was too long]';
            }
            return ['result' => $json, 'ok' => true, 'label' => $label, 'link' => $link, 'run' => is_array($data) ? ($data['run_id'] ?? null) : null];
        } catch (\Throwable $e) {
            return ['result' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'ok' => false, 'label' => $label, 'link' => null, 'run' => null];
        } finally {
            self::$local = null;
        }
    }

    /** The key of the local a site gets its code from, or null when it isn't connected. */
    private static function localOf(array $site): ?string
    {
        try {
            return Sites::rootSource($site)['key'];
        } catch (\RuntimeException) {
            return null;
        }
    }

    /** Whether a site belongs to the chat's local. */
    private static function inScope(array $site): bool
    {
        return self::$local === null || self::localOf($site) === self::$local;
    }

    /** "Staging nova (nova), Nova Prod (nova-prod)": the sites this chat may use. */
    private static function scopeSites(): string
    {
        $names = [];
        foreach (Sites::ordered() as $key => $site) {
            if (self::inScope($site)) {
                $names[] = "{$site['name']} ($key)";
            }
        }
        return $names ? implode(', ', $names) : 'none';
    }

    private static function outOfScope(string $what, ?string $otherLocal): \RuntimeException
    {
        $mine = Sources::get((string) self::$local)['name'] ?? (string) self::$local;
        $other = $otherLocal !== null ? (Sources::get($otherLocal)['name'] ?? $otherLocal) : 'no local';
        return new \RuntimeException("$what belongs to $other, but this chat is about the local $mine and may only use its sites: "
            . self::scopeSites() . ". Use one of those, or tell the user to start a new chat with the local $other picked.");
    }

    /** Loads a run, refusing runs of another local. */
    private static function loadRun(string $id): array
    {
        $run = RunStore::load($id);
        if (self::$local !== null) {
            try {
                $runLocal = RunStore::source($run)['key'];
            } catch (\RuntimeException) {
                $runLocal = $run['repo_source'] ?? null;
            }
            if ($runLocal !== self::$local) {
                throw self::outOfScope("Run $id", $runLocal);
            }
        }
        return $run;
    }

    /** What the chat shows for an action, e.g. "Read the status of Staging site". */
    public static function label(string $name, array $arguments): string
    {
        $siteName = static fn (): string => Sites::get((string) ($arguments['site'] ?? ''))['name'] ?? (string) ($arguments['site'] ?? '?');
        return match ($name) {
            'list_locals_and_sites' => 'Listed locals and sites',
            'local_status' => 'Read the local ' . (Sources::get((string) ($arguments['local'] ?? ''))['name'] ?? (string) ($arguments['local'] ?? '?')),
            'site_status' => 'Checked ' . $siteName() . ' on its server',
            'deploy_preview' => 'Looked at what a deploy to ' . $siteName() . ' would change',
            'list_runs' => 'Read the history' . (isset($arguments['site']) ? ' of ' . $siteName() : ''),
            'get_run' => 'Opened run ' . (string) ($arguments['run_id'] ?? '?'),
            'file_changes' => 'Read the changes in ' . basename((string) ($arguments['path'] ?? '?')),
            'start_compare' => 'Started a compare of ' . $siteName(),
            'prepare_deploy' => 'Prepared a deploy to ' . $siteName(),
            'prepare_match' => 'Prepared "Make it match"' . (isset($arguments['paths']) ? ' for ' . count((array) $arguments['paths']) . ' file(s)' : ''),
            'wait_for_run' => 'Waited for run ' . (string) ($arguments['run_id'] ?? '?'),
            'set_review_files' => self::reviewFilesLabel($arguments),
            'approve_run' => 'Approved run ' . (string) ($arguments['run_id'] ?? '?'),
            'fetch_local' => 'Fetched from GitHub',
            'confirm_sql_done' => 'Marked the SQL step done',
            'retry_run' => 'Retried run ' . (string) ($arguments['run_id'] ?? '?'),
            'prepare_rollback' => 'Prepared a rollback of run ' . (string) ($arguments['run_id'] ?? '?'),
            'record_version' => 'Recorded the version from run ' . (string) ($arguments['compare_run_id'] ?? '?'),
            'cancel_run' => 'Cancelled run ' . (string) ($arguments['run_id'] ?? '?'),
            'protect_paths' => 'Protected ' . implode(', ', array_slice(array_map('strval', (array) ($arguments['paths'] ?? [])), 0, 3)),
            'site_settings' => 'Read the settings of ' . $siteName(),
            'add_site' => 'Added the site ' . (string) ($arguments['name'] ?? $arguments['key'] ?? '?'),
            'edit_site' => 'Changed ' . implode(', ', array_keys((array) ($arguments['changes'] ?? []))) . ' of ' . $siteName(),
            'add_local' => 'Added the local ' . (string) ($arguments['name'] ?? $arguments['key'] ?? '?'),
            'edit_local' => "Changed the local's " . implode(', ', array_keys((array) ($arguments['changes'] ?? []))),
            default => 'Tried an unknown action: ' . $name,
        };
    }

    /** @return array{0: array, 1: string} */
    private static function startCompare(array $site, array $arguments): array
    {
        $git = Sites::git($site);
        $wanted = trim((string) ($arguments['commit'] ?? ''));
        $sha = $wanted === '' ? DeployRun::sourceCommit($site, $git) : ($git->resolve($wanted) ?? throw new \RuntimeException("$wanted was not found in the repository."));
        $method = in_array($arguments['method'] ?? '', ['fingerprint', 'sizes'], true) ? $arguments['method'] : Settings::get('compare_method');
        return self::started(CompareRun::create($site, $sha, $method));
    }

    /** @return array{0: array, 1: string} */
    private static function prepareDeploy(array $site): array
    {
        if (ServerState::read(Sites::transport($site)) === null) {
            throw new \RuntimeException("{$site['name']} has no recorded version yet, so the tool can't tell what changed. The user can pick the server's commit on the Deploy page, or you can start a compare so they can record the version.");
        }
        return self::started(DeployRun::create($site, 'deploy'));
    }

    /** @return array{0: array, 1: string} */
    private static function prepareMatch(string $compareId, array $arguments): array
    {
        $compare = self::loadRun($compareId);
        if ($compare['type'] !== 'compare' || $compare['status'] !== 'done' || !empty($compare['recorded'])) {
            throw new \RuntimeException('That run is not a finished compare whose version is still unrecorded.');
        }
        $paths = isset($arguments['paths']) ? array_values(array_filter((array) $arguments['paths'], 'is_string')) : null;
        $listed = array_merge($compare['result']['differs'], $compare['result']['missing'], $compare['result']['eol']);
        if ($paths !== null && ($unknown = array_diff($paths, $listed)) !== []) {
            throw new \RuntimeException('Not in this compare\'s different, missing or line-ending lists: ' . implode(', ', array_slice($unknown, 0, 10)) . '. Use get_run for the exact paths.');
        }
        return self::started(CompareRun::createSync($compare, $paths, !empty($arguments['include_line_endings'])));
    }

    /** @return array{0: array, 1: string} */
    private static function setReviewFiles(string $id, array $arguments): array
    {
        $run = self::loadRun($id);
        if (!in_array($run['type'], ['deploy', 'sync'], true) || $run['status'] !== 'review') {
            throw new \RuntimeException('Only a deploy or match that is waiting for review can change its files.');
        }
        $paths = static fn (string $key): array => array_values(array_unique(array_filter((array) ($arguments[$key] ?? []), 'is_string')));
        [$exclude, $include] = [$paths('exclude'), $paths('include')];
        if ($exclude === [] && $include === []) {
            throw new \RuntimeException('Give the paths to exclude or include.');
        }
        $files = array_column($run['files'], null, 'path');
        $excluded = array_column($run['excluded'] ?? [], null, 'path');
        $unknown = array_merge(array_diff($exclude, array_keys($files)), array_diff($include, array_keys($excluded)));
        if ($unknown !== []) {
            throw new \RuntimeException('Not ' . ($include !== [] ? 'in this review or left out of it' : 'in this review') . ': ' . implode(', ', array_slice($unknown, 0, 10)) . '. Use get_run for the exact paths.');
        }
        foreach ($exclude as $path) {
            $excluded[$path] = $files[$path];
            unset($files[$path]);
        }
        foreach ($include as $path) {
            $files[$path] = $excluded[$path];
            unset($excluded[$path]);
        }
        if ($files === []) {
            throw new \RuntimeException('That would leave nothing to upload. The user can cancel the run on its page instead.');
        }
        // Files that come back keep their place in the review's original order.
        $run['review_order'] ??= array_column($run['files'], 'path');
        $order = array_flip($run['review_order']);
        uksort($files, static fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));
        $run['files'] = array_values($files);
        $run['excluded'] = array_values($excluded);
        $who = Settings::get('deployer_name') ?: 'the user';
        if ($exclude !== []) {
            Runner::log($run, 'info', "Left out by the AI assistant for $who: " . implode(', ', $exclude) . '.');
        }
        if ($include !== []) {
            Runner::log($run, 'info', "Put back by the AI assistant for $who: " . implode(', ', $include) . '.');
        }
        RunStore::save($run);
        return [
            [
                'run_id' => $run['id'],
                'files_in_review' => count($run['files']),
                'left_out' => array_column($run['excluded'], 'path'),
                'note' => 'Left-out files stay as they are on the server. The version is still recorded as the commit, so later deploys only send them if they change again.',
            ],
            'index.php?r=run&id=' . urlencode($run['id']),
        ];
    }

    /** @return array{0: array, 1: string} */
    private static function approveRun(string $id, array $arguments): array
    {
        $run = self::loadRun($id);
        if (!in_array($run['type'], ['deploy', 'sync', 'rollback'], true)) {
            throw new \RuntimeException('Only deploys, matches and rollbacks can be approved.');
        }
        if (!empty($run['production'])) {
            throw new \RuntimeException("{$run['site_name']} is a production site, so it is approved on the run's page, where the user types the site key.");
        }
        $who = Settings::get('deployer_name') ?: 'the user';
        if ($run['type'] === 'rollback') {
            if (!Ai::can('rollback')) {
                throw new \RuntimeException('Not allowed: approving a rollback also needs "Roll back deploys" in Settings → AI permissions.');
            }
            $error = RollbackRun::approve($run, []);
            if ($error !== null) {
                throw new \RuntimeException($error);
            }
            Runner::log($run, 'info', "Approved from the AI assistant chat at the request of $who.");
            RunStore::save($run);
            Worker::ensure($run);
            return [
                ['run_id' => $run['id'], 'status' => 'Approved: putting files back in the background.', 'next' => 'Use wait_for_run with this run_id, then tell the user how it went.'],
                'index.php?r=run&id=' . urlencode($run['id']),
            ];
        }
        $warnings = DeployRun::warnings($run);
        if ($warnings !== [] && empty($arguments['warnings_checked'])) {
            $list = array_map(static fn (array $f): string => $f['path'] . ' (' . RunView::serverBadge($f)[0] . ')', array_slice($warnings, 0, 15));
            throw new \RuntimeException(count($warnings) . ' warning(s) to show the user first: ' . implode(', ', $list) . '. Approve with warnings_checked only if they still want it.');
        }
        $error = DeployRun::approve($run, ['ack_warnings' => $warnings !== [] ? '1' : '', 'approve_deletes' => ($arguments['delete_files'] ?? true) ? '1' : '']);
        if ($error !== null) {
            throw new \RuntimeException($error);
        }
        Runner::log($run, 'info', "Approved from the AI assistant chat at the request of $who.");
        RunStore::save($run);
        Worker::ensure($run);
        return [
            ['run_id' => $run['id'], 'status' => 'Approved: uploading in the background.', 'next' => 'Use wait_for_run with this run_id, then tell the user how it went.'],
            'index.php?r=run&id=' . urlencode($run['id']),
        ];
    }

    /** "… at the request of Dhruvil." for run logs. */
    private static function byAssistant(string $what): string
    {
        return "$what from the AI assistant chat at the request of " . (Settings::get('deployer_name') ?: 'the user') . '.';
    }

    private static function fetchLocal(): array
    {
        $source = Sources::get((string) self::$local) ?? throw new \RuntimeException('This chat has no local to fetch.');
        Sources::git($source)->fetch($source['remote']);
        return ['fetched' => "{$source['remote']}/{$source['branch']}"] + self::localStatus($source['key']);
    }

    /** @return array{0: array, 1: string} */
    private static function confirmSqlDone(string $id): array
    {
        $run = self::loadRun($id);
        if ($run['status'] !== 'sql') {
            throw new \RuntimeException('This run is not waiting at the SQL step.');
        }
        DeployRun::sqlDone($run);
        Runner::log($run, 'info', self::byAssistant('SQL step confirmed'));
        RunStore::save($run);
        return [['run_id' => $run['id'], 'status' => 'Done.'], 'index.php?r=run&id=' . urlencode($run['id'])];
    }

    /** @return array{0: array, 1: string} */
    private static function retryRun(string $id): array
    {
        $run = self::loadRun($id);
        if ($run['status'] !== 'failed') {
            throw new \RuntimeException('Only a failed run can be retried.');
        }
        if (!empty($run['production'])) {
            throw new \RuntimeException("{$run['site_name']} is a production site, so a failed run there is retried on its page.");
        }
        $run['status'] = 'running';
        $run['error'] = null;
        Runner::log($run, 'info', self::byAssistant('Retrying'));
        RunStore::save($run);
        Worker::ensure($run);
        return [['run_id' => $run['id'], 'status' => 'Running again in the background.', 'next' => 'Use wait_for_run with this run_id.'], 'index.php?r=run&id=' . urlencode($run['id'])];
    }

    /** @return array{0: array, 1: string} */
    private static function prepareRollback(string $id): array
    {
        return self::started(RollbackRun::create(self::loadRun($id)));
    }

    /** @return array{0: array, 1: string} */
    private static function recordVersion(string $id, bool $accept): array
    {
        $compare = self::loadRun($id);
        if ($compare['type'] !== 'compare') {
            throw new \RuntimeException('Only a compare can record its commit as the version.');
        }
        if (!empty($compare['production'])) {
            throw new \RuntimeException("{$compare['site_name']} is a production site, so its version is recorded on the compare's page.");
        }
        if (!empty($compare['recorded'])) {
            throw new \RuntimeException('This compare\'s commit is already recorded.');
        }
        CompareRun::recordVersion($compare, $accept); // saves, and refuses unaccepted differences
        Runner::log($compare, 'info', self::byAssistant('Version recorded'));
        RunStore::save($compare);
        return [['run_id' => $compare['id'], 'recorded' => short_sha($compare['to']), 'site' => $compare['site_name']], 'index.php?r=run&id=' . urlencode($compare['id'])];
    }

    /** @return array{0: array, 1: string} */
    private static function cancelRun(string $id): array
    {
        $run = self::loadRun($id);
        if (!empty($run['touched']) || !in_array($run['status'], ['review', 'failed'], true)) {
            throw new \RuntimeException('Only a run waiting for review, or one that failed before changing anything, can be cancelled. A run that changed files is rolled back or abandoned on its page.');
        }
        $run['status'] = 'cancelled';
        Runner::log($run, 'info', self::byAssistant('Cancelled') . ' Nothing was changed on the server.');
        RunStore::save($run);
        return [['run_id' => $run['id'], 'status' => 'Cancelled.'], 'index.php?r=run&id=' . urlencode($run['id'])];
    }

    /** A site's settings for the model: everything but the password. */
    private static function siteSettings(array $site): array
    {
        return ['gets_code_from' => Sites::sourceLabel($site), 'has_password' => $site['password'] !== '']
            + array_diff_key($site, ['password' => true, 'source' => true]);
    }

    /**
     * 'local:nova' or 'site:nova-staging' for this chat's local or one of its sites. A bare
     * key that names both a local and a site must say which.
     */
    private static function sourceRef(string $value, ?string $selfKey): string
    {
        preg_match('/^(?:(local|site):)?(.+)$/', trim($value), $m);
        [$kind, $key] = [$m[1] ?? '', $m[2] ?? ''];
        $isLocal = Sources::get($key) !== null && (self::$local === null || $key === self::$local);
        $site = Sites::get($key);
        $isSite = $site !== null && $key !== $selfKey && self::inScope($site);
        if ($kind === '' && $isLocal && $isSite) {
            throw new \RuntimeException("\"$key\" is both the local and the site {$site['name']}. Use local:$key or site:$key.");
        }
        if ($isLocal && $kind !== 'site') {
            return 'local:' . $key;
        }
        if ($isSite && $kind !== 'local') {
            return 'site:' . $key;
        }
        throw new \RuntimeException("$value is not this chat's local or one of its sites. Choose from: local:" . (self::$local ?? '?') . ', or a site: ' . self::scopeSites() . '.');
    }

    /**
     * Adds a site ($existing null) or changes some settings of one. Passwords are never
     * taken, a production site can't be unmarked, and the site stays in this chat's local.
     *
     * @return array{0: array, 1: string}
     */
    private static function saveSite(?array $existing, array $fields): array
    {
        if (array_intersect(array_keys($fields), ['password', 'pass', 'ftp_password'])) {
            throw new \RuntimeException("Passwords are never set from the chat. Tell the user to type it on the site's page.");
        }
        $allowed = array_merge(array_keys(self::SITE_FIELDS), $existing === null ? ['key'] : []);
        if (($unknown = array_diff(array_keys($fields), $allowed)) !== []) {
            throw new \RuntimeException('These settings can\'t be set from the chat: ' . implode(', ', $unknown) . '.');
        }
        if ($existing !== null && !empty($existing['production']) && array_key_exists('production', $fields) && empty($fields['production'])) {
            throw new \RuntimeException("{$existing['name']} is a production site. Only the user can unmark that, on the site's page.");
        }

        $input = ($existing ?? Sites::blank()) + ['key' => ''];
        $input['password'] = ''; // keeps the saved one when editing
        foreach (array_keys(self::SITE_FIELDS) as $field) {
            if ($field !== 'gets_code_from' && array_key_exists($field, $fields)) {
                $input[$field] = is_bool($fields[$field]) ? ($fields[$field] ? '1' : '') : (string) $fields[$field];
            }
        }
        if (isset($fields['gets_code_from'])) {
            $input['source'] = self::sourceRef((string) $fields['gets_code_from'], $existing['key'] ?? null);
        } elseif ($existing === null) {
            $input['source'] = 'local:' . (self::$local ?? Sources::firstKey());
        }
        if ($existing === null) {
            $input['key'] = (string) ($fields['key'] ?? '');
        }

        [$errors, $site] = Sites::save($input, $existing['key'] ?? null, true);
        if ($errors) {
            throw new \RuntimeException('Not saved: ' . implode(' ', array_map(static fn (string $field, string $error): string => "$field: $error", array_keys($errors), $errors)));
        }
        $who = Settings::get('deployer_name') ?: 'the user';
        $link = 'index.php?r=site-edit&key=' . urlencode($site['key']);
        $needsPassword = $site['type'] === 'ftp' && $site['password'] === '';
        return [
            [
                'site' => $site['key'],
                'saved' => self::siteSettings($site),
                'next' => $needsPassword
                    ? "Tell $who to type the FTP password on the site's page ($link), then use Test connection on the Sites page."
                    : 'Suggest using Test connection on the Sites page.',
            ],
            $link,
        ];
    }

    /**
     * Adds a local ($key null) or changes this chat's local's name, folder, branch or remote.
     *
     * @return array{0: array, 1: string}
     */
    private static function saveLocal(?string $key, array $fields): array
    {
        $allowed = ['name', 'repo_path', 'branch', 'remote'];
        if ($key === null) {
            $allowed[] = 'key';
        }
        if (($unknown = array_diff(array_keys($fields), $allowed)) !== []) {
            throw new \RuntimeException('These can\'t be set this way: ' . implode(', ', $unknown) . (in_array('extra_protected', $unknown, true) ? ' (use protect_paths for protected paths).' : '.'));
        }
        $existing = $key !== null ? (Sources::get($key) ?? throw new \RuntimeException('This chat has no local to edit.')) : null;
        $input = ($existing ?? ['key' => '', 'name' => '', 'repo_path' => '', 'branch' => '', 'remote' => 'origin', 'extra_protected' => '']);
        foreach ($fields as $field => $value) {
            $input[$field] = (string) $value;
        }
        [$errors, $source] = Sources::save($input, $key);
        if ($errors) {
            throw new \RuntimeException('Not saved: ' . implode(' ', array_map(static fn (string $field, string $error): string => "$field: $error", array_keys($errors), $errors)));
        }
        return [
            [
                'local' => $source['key'],
                'saved' => array_diff_key($source, ['extra_protected' => true]),
                'next' => $key === null ? "To work with it, the user starts a new chat with {$source['name']} picked, and adds its first site there." : null,
            ],
            'index.php?r=source-edit&key=' . urlencode($source['key']),
        ];
    }

    private static function protectPaths(array $arguments): array
    {
        $paths = array_values(array_filter((array) ($arguments['paths'] ?? []), 'is_string'));
        if ($paths === [] || self::$local === null) {
            throw new \RuntimeException('Give the paths to protect.');
        }
        foreach ($paths as $path) {
            Sources::addProtected(self::$local, $path);
        }
        $source = Sources::get(self::$local);
        return ['protected' => array_map([ProtectedPaths::class, 'normalize'], $paths), 'local_now_protects' => ProtectedPaths::parse($source['extra_protected'])];
    }

    private static function reviewFilesLabel(array $arguments): string
    {
        $out = count((array) ($arguments['exclude'] ?? []));
        $back = count((array) ($arguments['include'] ?? []));
        return trim(($out ? "Left out $out file(s)" : '') . ($out && $back ? ', ' : '') . ($back ? "put back $back file(s)" : '')) ?: 'Changed the files of a review';
    }

    /**
     * Notes who started a run and hands it to a background worker.
     *
     * @return array{0: array, 1: string}
     */
    private static function started(array $run): array
    {
        Runner::log($run, 'info', 'Started by the AI assistant for ' . (Settings::get('deployer_name') ?: 'the user') . '.');
        RunStore::save($run);
        Worker::ensure($run);
        return [
            ['run_id' => $run['id'], 'status' => 'Started in the background.', 'next' => 'Use wait_for_run with this run_id.'],
            'index.php?r=run&id=' . urlencode($run['id']),
        ];
    }

    /**
     * Polls until the run stops running or time is up. Without a background worker this
     * request moves the run on itself, as the run page would.
     *
     * @return array{0: array, 1: string}
     */
    private static function waitForRun(string $id, int $seconds): array
    {
        $deadline = microtime(true) + max(5, min(90, $seconds));
        $run = self::loadRun($id);
        while ($run['status'] === 'running' && microtime(true) < $deadline) {
            if (Worker::ensure($run)) {
                usleep(1_500_000);
                $run = RunStore::load($id);
            } else {
                $run = Runner::step($id);
            }
        }
        [$data, $link] = self::getRun($id);
        if ($run['status'] === 'running') {
            $data['note'] = 'Still running. Wait again, or tell the user to follow it on its page.';
        } elseif ($run['status'] === 'review') {
            $data['note'] = 'Ready for the review: show the user its files and ask whether to go ahead.';
        } elseif ($run['status'] === 'sql') {
            $data['note'] = 'Files are uploaded and the version recorded. The user now runs any database changes and clicks "SQL is done (or not needed)" on the run\'s page.';
        }
        return [$data, $link];
    }

    private static function text(array $arguments, string $key): string
    {
        $value = trim((string) ($arguments[$key] ?? ''));
        return $value !== '' ? $value : throw new \RuntimeException("Missing $key.");
    }

    private static function site(array $arguments): array
    {
        $key = self::text($arguments, 'site');
        $site = Sites::get($key) ?? throw new \RuntimeException("There is no site with the key $key. This chat's sites: " . self::scopeSites() . '.');
        if (!self::inScope($site)) {
            throw self::outOfScope("{$site['name']} ($key)", self::localOf($site));
        }
        return $site;
    }

    private static function listAll(): array
    {
        $locals = [];
        foreach (Sources::all() as $key => $source) {
            if (self::$local === null || $key === self::$local) {
                $locals[] = ['key' => $key, 'name' => $source['name'], 'branch' => $source['branch'], 'repository' => $source['repo_path']];
            }
        }
        $sites = [];
        foreach (Sites::ordered() as $key => $site) {
            if (!self::inScope($site)) {
                continue;
            }
            $sites[] = [
                'key' => $key,
                'name' => $site['name'],
                'gets_code_from' => Sites::sourceLabel($site),
                'production' => (bool) $site['production'],
                'kind' => $site['type'],
                'address' => $site['url'],
            ];
        }
        return ['locals' => $locals, 'sites' => $sites, 'note' => self::$local !== null ? 'Only the local picked for this chat and its sites are listed; others are out of reach in this chat.' : null];
    }

    private static function localStatus(string $key): array
    {
        if (self::$local !== null && $key !== self::$local) {
            $mine = Sources::get(self::$local)['name'] ?? self::$local;
            $other = Sources::get($key)['name'] ?? $key;
            throw new \RuntimeException("This chat is about the local $mine only, so $other is out of reach here. Tell the user to start a new chat with $other picked.");
        }
        $status = LocalStatus::collect(Sources::get($key) ?? throw new \RuntimeException("There is no local with the key $key."));
        return [
            'error' => $status['error'],
            'commit' => $status['commit'],
            'github_commit' => $status['remote_commit'],
            'commits_not_on_github' => $status['ahead'],
            'github_commits_not_here' => $status['behind'],
            'warnings' => $status['warnings'],
            'malware_findings' => array_merge($status['malware'], $status['remote_malware']),
            'last_fetch' => $status['last_fetch'] ? date('Y-m-d H:i', $status['last_fetch']) : null,
        ];
    }

    private static function siteStatus(array $site): array
    {
        try {
            $sourceCommit = DeployRun::sourceCommit($site, Sites::git($site));
        } catch (\RuntimeException) {
            $sourceCommit = null;
        }
        return SiteStatus::check($site, $sourceCommit) + ['open_run' => RunStore::activeRunFor($site['key'])['id'] ?? null];
    }

    private static function deployPreview(array $site): array
    {
        $preview = DeployRun::preview($site);
        $changes = $preview['changes'];
        return [
            'would_deploy' => $preview['source'],
            'problem_with_code' => $preview['sourceError'],
            'server_version' => $preview['state'],
            'problem_reading_server' => $preview['stateError'],
            'open_run' => $preview['open']['id'] ?? null,
            'server_has_no_version' => $preview['stateError'] === null && $preview['state'] === null,
            'commit_count' => $changes['commits'] ?? null,
            'commits' => array_map(static fn (array $c): string => short_sha($c['sha']) . ' ' . $c['subject'] . ' (' . $c['author'] . ')', array_slice($changes['list'] ?? [], 0, 30)),
            'changed_files' => array_map(static fn (array $f): string => $f[0] . ' ' . $f[1], array_slice($changes['files'] ?? [], 0, 200)),
            'changed_file_count' => isset($changes['files']) ? count($changes['files']) : null,
            'note' => 'A = new, M = changed, D = removed in git. Protected paths are left out when the deploy runs.',
        ];
    }

    private static function listRuns(array $arguments): array
    {
        $limit = max(1, min(30, (int) ($arguments['limit'] ?? 10)));
        if (isset($arguments['site'])) {
            self::site($arguments); // refuses another local's site
        }
        $runs = [];
        foreach (RunStore::all() as $run) {
            if ((isset($arguments['site']) && $run['site'] !== $arguments['site']) || (isset($arguments['type']) && $run['type'] !== $arguments['type'])
                || (self::$local !== null && ($run['repo_source'] ?? null) !== self::$local)) {
                continue;
            }
            $runs[] = self::summary($run);
            if (count($runs) >= $limit) {
                break;
            }
        }
        return ['runs' => $runs];
    }

    private static function summary(array $run): array
    {
        $summary = [
            'run_id' => $run['id'],
            'kind' => RunView::TYPES[$run['type']] ?? $run['type'],
            'site' => $run['site_name'],
            'status' => RunView::STATUSES[$run['status']][0] ?? $run['status'],
            'started' => date('Y-m-d H:i', $run['created_at']),
            'by' => $run['by'],
            'from_commit' => short_sha($run['from']) ?: null,
            'to_commit' => short_sha($run['to']) ?: null,
        ];
        if ($run['type'] === 'compare' && isset($run['result'])) {
            $summary['result'] = array_map(static fn ($list) => is_array($list) ? count($list) : $list, array_intersect_key($run['result'], array_flip(['same', 'differs', 'eol', 'missing', 'extra'])));
        } elseif ($run['type'] !== 'compare') {
            $summary['files'] = count($run['files']);
        }
        return $summary;
    }

    /** @return array{0: array, 1: string} */
    private static function getRun(string $id): array
    {
        $run = self::loadRun($id);
        $data = self::summary($run) + ['error' => $run['error'], 'production' => !empty($run['production'])];
        if ($run['type'] === 'compare' && isset($run['result'])) {
            foreach (['differs' => 'different', 'missing' => 'missing_on_server', 'extra' => 'extra_on_server', 'eol' => 'line_endings_only'] as $key => $label) {
                $list = $run['result'][$key] ?? [];
                $data[$label] = ['count' => count($list), 'paths' => array_slice($list, 0, $key === 'eol' ? 20 : 60)];
            }
        } else {
            $data['files'] = array_map(static fn (array $f): array => array_filter([
                'path' => $f['path'],
                'action' => $f['action'],
                'change' => $f['change'] ?? null,
                'server_copy' => isset($f['server']) ? RunView::serverBadge($f)[0] : null,
                'done' => !empty($f['done']),
                'verified' => !empty($f['verified']),
            ], static fn ($v): bool => $v !== null), array_slice($run['files'], 0, 150));
            $data['protected_and_skipped'] = count($run['skipped']);
        }
        $data['log_end'] = array_map(static fn (array $line): string => implode(' ', $line), array_slice($run['log'], -15));
        return [$data, 'index.php?r=run&id=' . urlencode($id)];
    }

    /** @return array{0: array, 1: string} */
    private static function fileChanges(string $id, string $path): array
    {
        $run = self::loadRun($id);
        $file = current(array_filter($run['files'], static fn (array $f): bool => $f['path'] === $path))
            ?: throw new \RuntimeException("$path is not a file of run $id. Use get_run to see its files.");
        $git = Sources::git(RunStore::source($run));
        [$before, $after, $labels] = DiffView::sides($run, $file, 'change', $git);
        $diff = DiffView::text($git, $before, $after);
        // Drop git's header lines (temp file names), keep the hunks.
        $diff = substr($diff, max(0, (int) strpos($diff, '@@')));
        return [
            ['minus_side' => $labels[0] . ($before === null ? ' (no file)' : ''), 'plus_side' => $labels[1] . ($after === null ? ' (no file)' : ''), 'diff' => $diff !== '' ? $diff : 'No differences.'],
            'index.php?r=run-diff&id=' . urlencode($id) . '&path=' . urlencode($path),
        ];
    }
}

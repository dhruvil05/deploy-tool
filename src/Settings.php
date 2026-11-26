<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Tool settings: who is deploying, which git, PHP and ssh programs to use, how runs behave,
 * and the paths protected on every site. Repositories are locals (Sources).
 */
final class Settings
{
    private const LARAGON_GIT = 'C:\\laragon\\bin\\git\\bin\\git.exe';
    private const WINDOWS_SSH = 'C:\\Windows\\System32\\OpenSSH\\ssh.exe';

    /** Choices for the default compare method: method => label. */
    public const COMPARE_METHODS = ['fingerprint' => 'Fingerprint file', 'sizes' => 'File sizes over FTP'];

    /** The Settings page's sections, in menu order. */
    public const TABS = ['general', 'deploys', 'speedups', 'protected', 'programs', 'ai', 'permissions', 'cloud', 'security', 'storage'];

    /**
     * Optional speed-ups, each switched on or off in Settings → Speed-ups. Off gives exactly
     * the tool's behaviour from before. key => [label, what it does, what Off means]
     */
    public const FEATURES = [
        'feature_bundle_upload' => ['Upload big FTP deploys in one go', 'From the number of files set below, an FTP deploy goes up as one compressed file that a small helper unpacks on the server (each file to a temporary name, then renamed into place). Needs the site address. Off by default: on some shared hosts PHP writes files as another user, and later FTP uploads can\'t replace them, so try it on one deploy first.', 'Off: every file is its own FTP transfer.'],
        'feature_fast_verify' => ['Verify SSH uploads by fingerprint', 'After uploading, the server sends a fingerprint of each file instead of the whole file, so verifying takes a fraction of the time. Just as exact.', 'Off: every uploaded file is downloaded again and compared.'],
        'feature_promote' => ['Promote to the next site', "When a deploy finishes, its page offers to prepare the same commit for the next site in the pipeline (staging → prod). You still review and approve it.", 'Off: you start the next deploy from the dashboard.'],
        'feature_review_insights' => ['Review insights', 'The review groups files by folder with lines added and removed, marks risky files (config, .htaccess, dependencies, routes), and shows each file\'s changes in place.', 'Off: the plain file list.'],
        'feature_auto_fetch' => ['Check GitHub in the background', 'While the dashboard is open, it fetches the latest commits every 10 minutes (and when it opens, if the last fetch is older), so "GitHub has N commits" is always current. Branches and files never change.', 'Off: you click "Fetch from GitHub" yourself.'],
        'feature_run_index' => ['Fast history', 'Keeps a small index of past runs, so the dashboard and History open quickly however long the history gets.', 'Off: every run file is read on each load.'],
        'feature_release_notes' => ['Release notes', "Each deploy lists the commits it brings, with their authors, and a button that copies them as text for Slack or email.", 'Off: no commit list on the run page.'],
        'feature_notify' => ['Messages to Slack, Teams or Discord', 'When a deploy, match or rollback finishes, waits for SQL or fails, a short message goes to the chat webhook below.', 'Off, or no webhook: no messages.'],
        'feature_smart_checks' => ['Smarter checks after a deploy', 'A site can have several check pages (separated by commas). Each is measured before anything changes and again after the deploy: a page that breaks, changes its status or gets much slower is named, and a failed check offers Roll back right there.', 'Off: only the first check page, after the deploy.'],
        'feature_three_way' => ['Three-way view for server edits', 'For a file someone changed directly on the server, one page shows the edit, what your upload does to it, and which lines from the server would be lost, so a hotfix is never overwritten unnoticed.', 'Off: "Server edits" and "Changes" as two separate pages.'],
        'feature_debug_flags' => ['Flag leftover debug code', 'The review lists debug calls in the lines a deploy adds (var_dump, print_r, dd, die, console.log, debugger, phpinfo), with file and line, so they don\'t reach a live site.', 'Off: no debug-code check.'],
        'feature_commit_files' => ['Files of each commit', 'The dashboard, compare and run pages show the commits involved, each with the files it changed as folders; click a file to see its changes. Several commits get one tree each.', 'Off: no commit file trees.'],
        'feature_delete_extras' => ['Delete extra server files with "Make it match"', 'On a compare of a non-production site, "Extra on server" gets tick boxes (none ticked): the ticked files, usually ones deleted in git but left on the server, are deleted by "Make it match", after a backup, with the usual review and Roll back. Production never deletes.', 'Off: extra files always stay.'],
        'feature_server_watch' => ['Watch servers for changes', 'Every few hours (set below) each site\'s files are read, the way a compare reads them, and compared with the last time. Files changed, added or removed that no deploy of the tool made (a hotfix on the server, or a hack) are listed on the site\'s card and sent to your chat apps, and the top folder\'s build files are scanned for malware markers. On FTP sites this uploads the compare\'s small temporary file each time. Off by default.', 'Off: servers are only read when you compare.'],
        'feature_uptime' => ['Uptime and certificate monitor', 'Every few minutes (set below) each site with an address loads its check pages and reads its HTTPS certificate. You hear when a site goes down, when it is back, and while its certificate ends within 14 days; the site\'s card shows its state. Off by default.', 'Off: sites are only checked after a deploy.'],
        'feature_post_commands' => ['Commands after a deploy', 'SSH and folder sites can list commands (on the site\'s page), such as composer install or php artisan migrate. They show at the review and run on the server after the upload; their output is in the log and a failed command stops the run.', 'Off: no commands run, even when a site lists some.'],
        'feature_db_backup' => ['Database backup before a deploy', 'SSH and folder sites with a database set on their page get a database copy (mysqldump) with the file backup, before anything changes. It is kept with the run and can be downloaded from its page.', 'Off: only files are backed up.'],
        'feature_commit_compare' => ['Compare only some commits', 'On a site\'s Compare page, tick commits instead of comparing the whole site: only the files those commits changed are read and compared, in seconds. "Make it match" then sends just those (a hotfix to prod without what is still waiting on staging). If another commit you didn\'t tick also changed one of those files, you are told and can include it. The site\'s recorded version stays as it was, so the next normal deploy brings the rest, and knows the files sent.', 'Off: compares read the whole site.'],
        'feature_outside_selection' => ['Files outside the picked commits', 'After a compare of only some commits, a list of the files the site still lacks from the commits you didn\'t pick (between its recorded version and the newest commit it gets code from), grouped by commit. It comes from git alone: nothing more is read from the server. One click compares those commits too. Needs "Compare only some commits".', 'Off: only the picked commits\' files are shown.'],
        'feature_remote_locals' => ['Locals from a Git address', 'A local can be a Git address (GitHub, GitLab, Bitbucket) instead of a folder: the tool keeps its own copy, follows the branch when it fetches (stopping on malware markers, like Pull), and deploys from it. Nothing needs to be on this computer.', 'Off: locals are folders on this computer.'],
        'feature_discord_bridge' => ['Chat with the assistant from Discord', 'A Discord bot you create reads one channel: start a chat there with !new and ask in its thread; the answer comes back to Discord. It may act as far as AI permissions and the choice below allow, asking for your ✅ first; approving a production deploy is always done in the tool. Only the Discord users listed below are answered. Off by default; set it up below.', 'Off: Discord is never read.'],
        'feature_slack_bridge' => ['Chat with the assistant from Slack', 'A Slack app you create reads one channel over a connection the tool opens to Slack (Socket Mode): start a chat there with !new and ask in its thread; the answer comes back to Slack. It may act as far as AI permissions and the choice below allow, asking for your ✅ first; approving a production deploy is always done in the tool. Only the Slack members listed below are answered. Off by default; set it up below.', 'Off: Slack is never read.'],
        'feature_git_pull' => ['Pull from GitHub on the dashboard', 'When GitHub has commits your local doesn\'t, the dashboard offers Pull. It only fast-forwards (no merges), refuses with uncommitted changes, stops before any file changes when GitHub\'s commit has malware markers, and runs no git hooks.', 'Off: pull in your own git tool.'],
        'feature_streaming' => ["Answers appear as they're written", "The assistant's answer shows word by word while the model writes it, instead of all at once at the end. Providers that can't stream answer the normal way.", 'Off: the whole answer appears when it is done.'],
        'feature_quick_commands' => ['Quick commands in the chat', 'Messages starting with / (/status, /status staging, /compare staging, /deploy staging, /history, /help) run at once, without the AI: instant, free, and they work while the provider is down. Same AI permissions as the assistant.', 'Off: every message goes to the AI.'],
        'feature_explain' => ['"Explain this deploy" (AI)', 'A button on the review asks the AI assistant for a short summary of the deploy: what changes, risks, database steps and what to check afterwards. Sends the changes to your AI provider, and only when clicked. Needs the AI assistant switched on.', 'Off: no button.'],
        'feature_detect_sql' => ['Spot database changes', 'Lists migrations, .sql files, module installers and code that creates or alters tables, before you prepare the deploy and again at the review and SQL step, so the SQL step is no surprise.', 'Off: database changes only come up at the SQL step.'],
    ];

    /** The Settings section a form field is in, to open it when that field has a mistake. */
    public static function tabOf(string $field): string
    {
        return match (true) {
            str_starts_with($field, 'ai_perm_') => 'permissions',
            str_starts_with($field, 'cloud_') => 'cloud',
            str_starts_with($field, 'ai_') => 'ai',
            str_starts_with($field, 'feature_'), str_starts_with($field, 'discord_'), str_starts_with($field, 'slack_'), in_array($field, ['notify_webhook', 'bundle_min_files', 'watch_hours', 'monitor_minutes'], true) => 'speedups',
            $field === 'deployer_name' => 'general',
            in_array($field, ['git_path', 'php_path', 'ssh_path'], true) => 'programs',
            $field === 'protected_paths' => 'protected',
            default => 'deploys',
        };
    }

    public static function defaults(): array
    {
        return [
            'deployer_name' => '',
            'git_path' => '',
            'php_path' => '',
            'ssh_path' => '',
            'review_minutes' => '60',
            'compare_method' => 'fingerprint',
            'background_runs' => '1',
            'ftp_connections' => '4',
            'protected_paths' => implode("\n", ProtectedPaths::DEFAULTS),
            'notify_webhook' => '',
            'bundle_min_files' => '50',
            'discord_bot_token' => '',
            'discord_channel_id' => '',
            'discord_user_ids' => '',
            'discord_actions' => 'ask',
            'slack_bot_token' => '',
            'slack_app_token' => '',
            'slack_channel_id' => '',
            'slack_user_ids' => '',
            'slack_actions' => 'ask',
            'feature_bundle_upload' => '0', // see FEATURES: try it on one deploy first
            'feature_discord_bridge' => '0', // needs a Discord bot set up first
            'feature_slack_bridge' => '0', // needs a Slack app set up first
            'feature_server_watch' => '0', // reads every server on a timer
            'feature_uptime' => '0', // loads every site on a timer
            'watch_hours' => '6',
            'monitor_minutes' => '5',
        ] + array_fill_keys(array_keys(self::FEATURES), '1');
    }

    /**
     * How Settings → Speed-ups shows FEATURES: group => [title, what the group is for, its
     * features in order]. Every feature is in exactly one group (the tests check).
     */
    public const FEATURE_GROUPS = [
        'deploy' => ['Deploying', 'Fewer steps and faster uploads, with a safety net.', [
            'feature_fast_verify', 'feature_bundle_upload', 'feature_commit_compare', 'feature_outside_selection', 'feature_promote', 'feature_post_commands', 'feature_db_backup', 'feature_delete_extras',
        ]],
        'review' => ['Reviewing', 'See what a deploy brings before you approve it.', [
            'feature_review_insights', 'feature_detect_sql', 'feature_debug_flags', 'feature_three_way', 'feature_commit_files', 'feature_release_notes',
        ]],
        'git' => ['Git and locals', 'Keep your code in step with GitHub.', [
            'feature_auto_fetch', 'feature_git_pull', 'feature_remote_locals',
        ]],
        'watch' => ['Watching your sites', 'Hear about problems before your users do.', [
            'feature_smart_checks', 'feature_uptime', 'feature_server_watch',
        ]],
        'chat' => ['Messages and chat apps', 'News about deploys, and the assistant on your phone.', [
            'feature_notify', 'feature_discord_bridge', 'feature_slack_bridge',
        ]],
        'assistant' => ['AI assistant', 'Quicker answers, and some without the AI at all.', [
            'feature_streaming', 'feature_quick_commands', 'feature_explain',
        ]],
        'tool' => ['The tool itself', 'Keeps pages quick as your history grows.', [
            'feature_run_index',
        ]],
    ];

    /** One line per feature for Settings → Speed-ups; the full text (FEATURES) is under "Details". */
    public const FEATURE_SHORT = [
        'feature_bundle_upload' => 'Big FTP deploys go up as one file and are unpacked on the server.',
        'feature_fast_verify' => 'Checks SSH uploads by fingerprint instead of downloading them again.',
        'feature_promote' => 'After a deploy, prepare the same commit for the next site in one click.',
        'feature_review_insights' => 'Groups the review by folder, marks risky files, previews changes in place.',
        'feature_auto_fetch' => 'Fetches from GitHub every 10 minutes while the dashboard is open.',
        'feature_run_index' => 'A small index keeps the dashboard and History quick.',
        'feature_release_notes' => 'Lists each deploy\'s commits, ready to copy for Slack or email.',
        'feature_notify' => 'A short message to a chat channel when a deploy finishes, fails or waits for SQL.',
        'feature_smart_checks' => 'Several check pages, timed before and after; a slow or broken page is named.',
        'feature_three_way' => 'For a file edited on the server: which of its lines an upload would lose.',
        'feature_debug_flags' => 'Points out var_dump, dd, console.log and the like in a deploy\'s new lines.',
        'feature_commit_files' => 'Each commit with the files it changed, as folders, on several pages.',
        'feature_delete_extras' => '"Make it match" can delete server files you tick (never on production).',
        'feature_server_watch' => 'Every few hours, lists files changed on a server outside the tool, and scans for malware.',
        'feature_uptime' => 'Every few minutes, checks each site is up and its certificate isn\'t ending.',
        'feature_post_commands' => 'Runs a site\'s commands (composer install, migrations…) after the upload.',
        'feature_db_backup' => 'Saves a mysqldump of the site\'s database before anything changes.',
        'feature_remote_locals' => 'A local can be a GitHub address; the tool keeps its own copy.',
        'feature_commit_compare' => 'Compare and send only the files of the commits you tick, e.g. one hotfix to prod.',
        'feature_outside_selection' => 'After comparing some commits, lists the files the site still lacks from the others.',
        'feature_discord_bridge' => 'Ask the assistant in a Discord channel and get the answer there.',
        'feature_slack_bridge' => 'Ask the assistant in a Slack channel and get the answer there.',
        'feature_git_pull' => 'A Pull button on the dashboard: fast-forward only, stopped by malware.',
        'feature_streaming' => 'The assistant\'s answer shows word by word as it is written.',
        'feature_quick_commands' => '/status, /deploy, /history and more answer at once, without the AI.',
        'feature_explain' => 'A button on the review asks the AI for a short summary of the deploy.',
        'feature_detect_sql' => 'Lists migrations and table changes before the deploy, not only at the SQL step.',
    ];

    /** What the assistant may do when you write from Discord: value => [label, explanation]. */
    public const DISCORD_ACTIONS = [
        'ask' => ['Ask in Discord before each action', 'What AI permissions allow, but every action waits for your ✅ (or "yes") in Discord, even ones set to Allow. Recommended.'],
        'same' => ['Same as AI permissions', 'Actions set to Allow run at once; ones set to Ask wait for your ✅ in Discord.'],
        'read' => ['Only read', 'It looks things up and answers; every action is done in the tool.'],
    ];

    /** Settings kept encrypted on disk, like passwords; a blank field keeps the saved value. */
    private const SECRET_FIELDS = ['notify_webhook', 'discord_bot_token', 'slack_bot_token', 'slack_app_token'];

    /** Whether an optional speed-up (a key of FEATURES) is switched on. */
    public static function on(string $feature): bool
    {
        return self::get($feature) === '1';
    }

    public static function all(): array
    {
        return Secrets::openFields(array_merge(self::defaults(), array_intersect_key(JsonStore::read('settings'), self::defaults())), self::SECRET_FIELDS);
    }

    public static function get(string $key): string
    {
        return (string) self::all()[$key];
    }

    /** Repository settings saved before locals existed (used once, to fill in the first local). */
    public static function legacy(): array
    {
        return array_intersect_key(JsonStore::read('settings'), array_flip(['repo_path', 'branch', 'remote', 'extra_protected']));
    }

    /**
     * Validates and saves. Returns [field => error]; nothing is saved when there are errors.
     * $write false only checks.
     */
    public static function save(array $input, bool $write = true): array
    {
        $values = [];
        foreach (array_keys(self::defaults()) as $key) {
            $values[$key] = trim((string) ($input[$key] ?? ''));
        }
        $values['protected_paths'] = implode("\n", array_unique(ProtectedPaths::parse($values['protected_paths'])));
        $values['background_runs'] = $values['background_runs'] === '1' ? '1' : '0';
        foreach (array_keys(self::FEATURES) as $feature) {
            $values[$feature] = $values[$feature] === '1' ? '1' : '0';
        }
        // The chat webhook and the Discord bot token work like passwords: a blank field keeps the saved one.
        foreach (self::SECRET_FIELDS as $secret) {
            if (!empty($input[$secret . '_remove'])) {
                $values[$secret] = '';
            } elseif ($values[$secret] === '') {
                $values[$secret] = self::get($secret);
            }
        }
        $values['discord_user_ids'] = implode(', ', array_unique(preg_split('/[\s,;]+/', $values['discord_user_ids'], -1, PREG_SPLIT_NO_EMPTY) ?: []));

        $errors = [];
        if (str_contains($values['protected_paths'], '..')) {
            $errors['protected_paths'] = 'Paths cannot contain "..".';
        }
        if ($values['deployer_name'] === '') {
            $errors['deployer_name'] = 'Enter your name. It is recorded on the server with every deploy.';
        }
        foreach (['git_path', 'php_path', 'ssh_path'] as $key) {
            if ($values[$key] !== '' && !is_file($values[$key])) {
                $errors[$key] = 'File not found.';
            }
        }
        if (!ctype_digit($values['review_minutes']) || (int) $values['review_minutes'] < 5 || (int) $values['review_minutes'] > 720) {
            $errors['review_minutes'] = 'Enter 5 to 720 minutes.';
        }
        if (!isset(self::COMPARE_METHODS[$values['compare_method']])) {
            $errors['compare_method'] = 'Pick a method.';
        }
        if (!ctype_digit($values['ftp_connections']) || (int) $values['ftp_connections'] < 1 || (int) $values['ftp_connections'] > FtpTransport::MAX_CONNECTIONS) {
            $errors['ftp_connections'] = 'Enter 1 to ' . FtpTransport::MAX_CONNECTIONS . '.';
        }
        if (!ctype_digit($values['bundle_min_files']) || (int) $values['bundle_min_files'] < 2 || (int) $values['bundle_min_files'] > 5000) {
            $errors['bundle_min_files'] = 'Enter 2 to 5000 files.';
        }
        if ($values['notify_webhook'] !== '' && !Secrets::isSealed($values['notify_webhook'])
            && (!preg_match('#^https://[^\s/]+/\S+$#', $values['notify_webhook']) || !filter_var($values['notify_webhook'], FILTER_VALIDATE_URL))) {
            $errors['notify_webhook'] = 'Paste the webhook address from Slack, Teams or Discord. It starts with https://';
        }

        if ($values['discord_channel_id'] !== '' && !preg_match('/^\d{15,21}$/', $values['discord_channel_id'])) {
            $errors['discord_channel_id'] = 'Paste the channel ID: a long number (right-click the channel → Copy Channel ID).';
        }
        if ($values['discord_user_ids'] !== '' && !preg_match('/^\d{15,21}(, \d{15,21})*$/', $values['discord_user_ids'])) {
            $errors['discord_user_ids'] = 'Paste user IDs: long numbers separated by commas (right-click your name → Copy User ID).';
        }
        foreach (['watch_hours', 'monitor_minutes'] as $timing) {
            $values[$timing] = $values[$timing] !== '' ? $values[$timing] : self::defaults()[$timing]; // a blank field: the default
        }
        if (!ctype_digit($values['watch_hours']) || (int) $values['watch_hours'] < 1 || (int) $values['watch_hours'] > 48) {
            $errors['watch_hours'] = 'Enter 1 to 48 hours.';
        }
        if (!ctype_digit($values['monitor_minutes']) || (int) $values['monitor_minutes'] < 1 || (int) $values['monitor_minutes'] > 60) {
            $errors['monitor_minutes'] = 'Enter 1 to 60 minutes.';
        }
        foreach (['discord_actions', 'slack_actions'] as $choice) {
            if (!isset(self::DISCORD_ACTIONS[$values[$choice]])) {
                $values[$choice] = 'ask';
            }
        }
        $values['slack_user_ids'] = strtoupper(implode(', ', array_unique(preg_split('/[\s,;]+/', $values['slack_user_ids'], -1, PREG_SPLIT_NO_EMPTY) ?: [])));
        $values['slack_channel_id'] = strtoupper($values['slack_channel_id']);
        if ($values['slack_channel_id'] !== '' && !preg_match('/^[CG][A-Z0-9]{8,12}$/', $values['slack_channel_id'])) {
            $errors['slack_channel_id'] = 'Paste the channel ID, like C0123ABCD (channel name → View channel details, at the bottom).';
        }
        if ($values['slack_user_ids'] !== '' && !preg_match('/^[UW][A-Z0-9]{8,12}(, [UW][A-Z0-9]{8,12})*$/', $values['slack_user_ids'])) {
            $errors['slack_user_ids'] = 'Paste member IDs, like U0123ABCD (your profile → ⋯ → Copy member ID), separated by commas.';
        }
        if ($values['slack_bot_token'] !== '' && !Secrets::isSealed($values['slack_bot_token']) && !str_starts_with($values['slack_bot_token'], 'xoxb-')) {
            $errors['slack_bot_token'] = 'The bot token starts with xoxb- (OAuth & Permissions → Bot User OAuth Token).';
        }
        if ($values['slack_app_token'] !== '' && !Secrets::isSealed($values['slack_app_token']) && !str_starts_with($values['slack_app_token'], 'xapp-')) {
            $errors['slack_app_token'] = 'The app-level token starts with xapp- (Basic Information → App-Level Tokens).';
        }
        if ($values['feature_slack_bridge'] === '1' && ($values['slack_bot_token'] === '' || $values['slack_app_token'] === '' || $values['slack_channel_id'] === '' || $values['slack_user_ids'] === '')) {
            $errors['feature_slack_bridge'] = 'Fill in both tokens, the channel ID and at least one member ID before switching on Slack.';
        }
        if ($values['feature_discord_bridge'] === '1' && ($values['discord_bot_token'] === '' || $values['discord_channel_id'] === '' || $values['discord_user_ids'] === '')) {
            $errors['feature_discord_bridge'] = 'Fill in the bot token, the channel ID and at least one user ID before switching on Discord.';
        }

        if (!$errors && $write) {
            JsonStore::write('settings', Secrets::sealFields($values, self::SECRET_FIELDS) + self::legacy());
        }
        return $errors;
    }

    /** How long a prepared deploy can wait for approval before its backups count as out of date. */
    public static function reviewMinutes(): int
    {
        return max(5, (int) self::get('review_minutes'));
    }

    public static function backgroundRuns(): bool
    {
        return self::get('background_runs') === '1';
    }

    /** The configured git, else Laragon's git, else git from PATH. */
    public static function gitBinary(?string $configured = null): string
    {
        $configured ??= self::get('git_path');
        if ($configured !== '') {
            return $configured;
        }
        return is_file(self::LARAGON_GIT) ? self::LARAGON_GIT : 'git';
    }

    /** The configured ssh, else Windows' own OpenSSH, else ssh from PATH. */
    public static function sshBinary(?string $configured = null): string
    {
        $configured ??= self::get('ssh_path');
        if ($configured !== '') {
            return $configured;
        }
        return is_file(self::WINDOWS_SSH) ? self::WINDOWS_SSH : 'ssh';
    }

    /** The configured PHP, else the PHP running this tool, else php.exe next to the loaded php.ini. */
    public static function phpBinary(?string $configured = null): string
    {
        $configured ??= self::get('php_path');
        if ($configured !== '') {
            return $configured;
        }
        if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && PHP_BINARY !== '') {
            return PHP_BINARY;
        }
        $ini = php_ini_loaded_file();
        if ($ini && is_file(dirname($ini) . DIRECTORY_SEPARATOR . 'php.exe')) {
            return dirname($ini) . DIRECTORY_SEPARATOR . 'php.exe';
        }
        return 'php';
    }
}

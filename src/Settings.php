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
            str_starts_with($field, 'feature_'), in_array($field, ['notify_webhook', 'bundle_min_files'], true) => 'speedups',
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
            'feature_bundle_upload' => '0', // see FEATURES: try it on one deploy first
        ] + array_fill_keys(array_keys(self::FEATURES), '1');
    }

    /** Whether an optional speed-up (a key of FEATURES) is switched on. */
    public static function on(string $feature): bool
    {
        return self::get($feature) === '1';
    }

    public static function all(): array
    {
        return Secrets::openFields(array_merge(self::defaults(), array_intersect_key(JsonStore::read('settings'), self::defaults())), ['notify_webhook']);
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
        // The chat webhook works like a password: a blank field keeps the saved one.
        if (!empty($input['notify_webhook_remove'])) {
            $values['notify_webhook'] = '';
        } elseif ($values['notify_webhook'] === '') {
            $values['notify_webhook'] = self::get('notify_webhook');
        }

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

        if (!$errors && $write) {
            JsonStore::write('settings', Secrets::sealFields($values, ['notify_webhook']) + self::legacy());
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

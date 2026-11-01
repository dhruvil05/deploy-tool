<?php

declare(strict_types=1);

namespace DeployTool;

use DeployTool\Ai\Provider;

/** Dashboard, sites, locals and settings. Runs are in RunController. */
final class Controller
{
    public function handle(string $route): void
    {
        match ($route) {
            'dashboard' => $this->dashboard(),
            'fetch' => $this->fetch(),
            'sites' => $this->sites(),
            'site-edit' => $this->siteEdit(),
            'site-save' => $this->siteSave(),
            'site-delete' => $this->siteDelete(),
            'site-status' => $this->siteStatus(),
            'site-source' => $this->siteSource(),
            'site-hide' => $this->siteHide(),
            'source-edit' => $this->sourceEdit(),
            'source-save' => $this->sourceSave(),
            'source-delete' => $this->sourceDelete(),
            'protect-info' => $this->protectInfo(),
            'protect-add' => $this->protectAdd(),
            'settings' => $this->settings(),
            'settings-save' => $this->settingsSave(),
            'ai-models' => $this->aiModels(),
            'ai-test' => $this->aiTest(),
            'storage-save' => $this->storageSave(),
            'storage-browse' => $this->storageBrowse(),
            'cloud-test' => $this->cloudTest(),
            'cloud-backup-now' => $this->cloudBackupNow(),
            'cloud-list' => $this->cloudList(),
            'cloud-restore' => $this->cloudRestore(),
            'assistant', 'assistant-new', 'assistant-send', 'assistant-poll', 'assistant-stop', 'assistant-rename', 'assistant-delete' => (new AssistantController())->handle($route),
            default => (new RunController())->handle($route),
        };
    }

    private function dashboard(): void
    {
        // One local at a time: the one picked in the switcher, remembered in a cookie.
        $sources = Sources::all();
        $key = (string) ($_GET['local'] ?? $_COOKIE['dt_local'] ?? '');
        if (!isset($sources[$key])) {
            $key = (string) array_key_first($sources);
        } elseif (isset($_GET['local'])) {
            setcookie('dt_local', $key, ['expires' => time() + 365 * 86400, 'httponly' => true, 'samesite' => 'Lax']);
        }
        try {
            $local = LocalStatus::collect($sources[$key]);
        } catch (\RuntimeException $e) {
            $local = ['source' => $sources[$key], 'error' => $e->getMessage()];
        }

        $runs = RunStore::all();
        $openRuns = RunStore::open($runs);
        CloudBackup::maybeStart(); // the daily cloud backup, in the background, when one is due
        foreach ($openRuns as $run) {
            Worker::ensure($run); // picks up runs whose worker ended, for example after a restart
        }
        $layout = Sites::pipelines();
        $pipeline = current(array_filter($layout['pipelines'], static fn (array $p): bool => $p['source']['key'] === $key));
        $siteCounts = [];
        foreach ($layout['pipelines'] as $p) {
            $siteCounts[$p['source']['key']] = array_sum(array_map(static fn (array $row): int => count($row['sites']), $p['rows']));
        }
        View::render('dashboard', [
            'page' => 'dashboard',
            'sources' => $sources,
            'siteCounts' => $siteCounts,
            'local' => $local,
            'pipeline' => $pipeline,
            'layout' => $layout,
            'stats' => Activity::forSource($runs, $key),
            'nameMissing' => Settings::get('deployer_name') === '',
            'openRuns' => $openRuns,
        ], 'Dashboard');
    }

    private function fetch(): void
    {
        Http::requirePost();
        $source = Sources::get((string) ($_POST['source'] ?? ''));
        if ($source === null) {
            Http::deny(404, 'Local not found.');
        }
        try {
            Sources::git($source)->fetch($source['remote']);
            Http::flash('success', "Fetched the latest commits for {$source['name']}. Your branches and files were not changed.");
        } catch (\RuntimeException $e) {
            Http::flash('danger', $e->getMessage());
        }
        Http::redirect();
    }

    private function sites(): void
    {
        View::render('sites', [
            'page' => 'sites',
            'tree' => Sites::bySource(),
            'saved' => Sites::isSaved(),
            'sources' => Sources::all(),
        ], 'Sites');
    }

    private function siteEdit(): void
    {
        $key = isset($_GET['key']) ? (string) $_GET['key'] : null;
        $site = $key === null ? Sites::blank() : Sites::get($key);
        if ($site === null) {
            Http::deny(404, 'Site not found.');
        }
        if ($key === null && isset($_GET['source']) && array_key_exists((string) $_GET['source'], Sites::sourceOptions(null))) {
            $site['source'] = (string) $_GET['source'];
        }
        $this->renderSiteForm($site, [], $key);
    }

    private function siteSave(): void
    {
        Http::requirePost();
        $originalKey = ($_POST['original_key'] ?? '') !== '' ? (string) $_POST['original_key'] : null;
        [$errors, $site] = Sites::save($_POST, $originalKey);
        if ($errors) {
            $this->renderSiteForm($site, $errors, $originalKey);
            return;
        }
        Http::flash('success', "{$site['name']} saved.");
        if (($warning = Sites::checkoutWarning($site)) !== null) {
            Http::flash('warning', $warning);
        }
        Http::redirect('r=sites');
    }

    private function siteDelete(): void
    {
        Http::requirePost();
        $error = Sites::delete((string) ($_POST['key'] ?? ''));
        Http::flash($error === null ? 'success' : 'danger', $error ?? 'Site deleted.');
        Http::redirect('r=sites');
    }

    /** Dashboard dropdown: where a site gets its code from. */
    private function siteSource(): void
    {
        Http::requirePost();
        $key = (string) ($_POST['key'] ?? '');
        $error = Sites::setSource($key, (string) ($_POST['source'] ?? ''));
        $site = Sites::get($key);
        Http::flash($error === null ? 'success' : 'danger', $error ?? "{$site['name']} now gets its code from " . Sites::sourceLabel($site) . '.');
        Http::redirect();
    }

    private function siteHide(): void
    {
        Http::requirePost();
        Sites::setHidden((string) ($_POST['key'] ?? ''), ($_POST['hidden'] ?? '') === '1');
        Http::redirect();
    }

    /** JSON for the dashboard cards and the "Test connection" button. Read-only. */
    private function siteStatus(): void
    {
        Http::requireToken();
        $site = Sites::get((string) ($_GET['site'] ?? ''));
        if ($site === null) {
            Http::json(['ok' => false, 'error' => 'Unknown site.'], 404);
        }
        $sourceCommit = (string) ($_GET['source_commit'] ?? '');
        $sourceCommit = preg_match('/^[0-9a-f]{40}$/', $sourceCommit) ? $sourceCommit : null;

        try {
            $status = SiteStatus::check($site, $sourceCommit);
        } catch (\RuntimeException $e) {
            $status = ['ok' => false, 'error' => $e->getMessage()];
        }
        Http::json([
            'ok' => $status['ok'],
            'commit' => $status['state']['commit'] ?? null,
            'html' => View::partial('site_status', ['site' => $site, 'status' => $status]),
        ]);
    }

    private function sourceEdit(): void
    {
        $key = isset($_GET['key']) ? (string) $_GET['key'] : null;
        $source = $key === null ? Sources::blank() : Sources::get($key);
        if ($source === null) {
            Http::deny(404, 'Local not found.');
        }
        $this->renderSourceForm($source, [], $key);
    }

    private function sourceSave(): void
    {
        Http::requirePost();
        $originalKey = ($_POST['original_key'] ?? '') !== '' ? (string) $_POST['original_key'] : null;
        [$errors, $source] = Sources::save($_POST, $originalKey);
        if ($errors) {
            $this->renderSourceForm($source, $errors, $originalKey);
            return;
        }
        Http::flash('success', "Local {$source['name']} saved.");
        Http::redirect('r=source-edit&key=' . urlencode($source['key'])); // stay, to keep adjusting protected paths
    }

    private function sourceDelete(): void
    {
        Http::requirePost();
        $error = Sources::delete((string) ($_POST['key'] ?? ''));
        Http::flash($error === null ? 'success' : 'danger', $error ?? 'Local deleted.');
        Http::redirect('r=sites');
    }

    /** The folder picker on the local form: folders, rule counts, server-only folders (JSON). */
    private function protectInfo(): void
    {
        Http::requirePost();
        session_write_close();
        $key = (string) ($_POST['original_key'] ?? '');
        $source = [
            'repo_path' => rtrim(trim((string) ($_POST['repo_path'] ?? '')), '\\/'),
            'branch' => trim((string) ($_POST['branch'] ?? '')),
            'extra_protected' => (string) ($_POST['extra_protected'] ?? ''),
        ];
        if ($source['repo_path'] === '' || !is_dir($source['repo_path'])) {
            Http::json(['ok' => false, 'error' => 'Enter the repository folder to see its folders.']);
        }
        try {
            Http::json(ProtectPicker::info($source, Sources::get($key) !== null ? $key : null));
        } catch (\RuntimeException $e) {
            Http::json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** "Protect" on a compare result: adds the rule to the local the compare used. */
    private function protectAdd(): void
    {
        Http::requirePost();
        $run = RunStore::load((string) ($_POST['id'] ?? ''));
        $source = RunStore::source($run);
        $rule = (string) ($_POST['rule'] ?? '');
        try {
            Sources::addProtected($source['key'], $rule);
            Http::flash('success', "Protected $rule for {$source['name']}: it is never uploaded, deleted or read on its sites.");
        } catch (\RuntimeException $e) {
            Http::flash('danger', $e->getMessage());
        }
        Http::redirect('r=run&id=' . urlencode($run['id']) . '&open=extra#extra');
    }

    private function settings(): void
    {
        $this->renderSettings(Settings::all(), [], Ai::settings(), self::settingsTab($_GET['tab'] ?? null), CloudBackup::settings());
    }

    private function settingsSave(): void
    {
        Http::requirePost();
        $posted = array_map(static fn ($value): string => is_string($value) ? $value : '', $_POST);
        // Every section is checked before anything is saved, so all mistakes show at once.
        [$ai, $errors] = Ai::allowed() ? Ai::fromInput($_POST) : [Ai::settings(), []];
        [$cloud, $cloudErrors] = CloudBackup::fromInput($_POST);
        $errors += $cloudErrors + Settings::save($_POST, false);
        if ($errors) {
            // Open the section with the first mistake, unless the one in view has one too.
            $tab = self::settingsTab($_POST['tab'] ?? null);
            $tabs = array_map([Settings::class, 'tabOf'], array_keys($errors));
            $this->renderSettings(array_merge(Settings::all(), $posted), $errors, $ai, in_array($tab, $tabs, true) ? $tab : $tabs[0], $cloud);
            return;
        }
        Settings::save($_POST);
        CloudBackup::save($cloud);
        if (Ai::allowed()) {
            Ai::save($ai);
        }
        Http::flash('success', 'Settings saved.');
        Http::redirect('r=settings&tab=' . self::settingsTab($_POST['tab'] ?? null));
    }

    private static function settingsTab(mixed $tab): string
    {
        return in_array($tab, Settings::TABS, true) ? $tab : 'general';
    }

    /** Settings page: the provider's model list, for the form's current (unsaved) AI fields. */
    private function aiModels(): void
    {
        $this->aiCall(static fn (Provider $provider): array => ['models' => $provider->models()]);
    }

    /** Settings page: one test request with the form's current (unsaved) AI fields. */
    private function aiTest(): void
    {
        $this->aiCall(static fn (Provider $provider): array => $provider->test());
    }

    private function aiCall(callable $call): never
    {
        Http::requirePost();
        if (!Ai::allowed()) {
            Http::json(['ok' => false, 'error' => 'The AI assistant is turned off in .env.'], 404);
        }
        [$settings] = Ai::fromInput($_POST);
        try {
            Http::json(['ok' => true] + $call(new Provider($settings)));
        } catch (\RuntimeException $e) {
            Http::json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private function renderSiteForm(array $site, array $errors, ?string $originalKey): void
    {
        View::render('site_edit', [
            'page' => 'sites',
            'site' => $site,
            'errors' => $errors,
            'originalKey' => $originalKey,
            'sourceOptions' => Sites::sourceOptions($originalKey),
        ], $originalKey === null ? 'Add site' : 'Edit site');
    }

    private function renderSourceForm(array $source, array $errors, ?string $originalKey): void
    {
        View::render('source_edit', [
            'page' => 'sites',
            'source' => $source,
            'errors' => $errors,
            'originalKey' => $originalKey,
        ], $originalKey === null ? 'Add local' : 'Edit local');
    }

    private function renderSettings(array $values, array $errors, array $ai, string $tab, array $cloud): void
    {
        View::render('settings', [
            'page' => 'settings',
            'tab' => $tab,
            'cloud' => $cloud,
            'cloudSaved' => CloudBackup::settings(),
            'values' => $values,
            'errors' => $errors,
            'aiAllowed' => Ai::allowed(),
            'ai' => $ai,
            'aiKeySaved' => Ai::settings()['api_key'] !== '',
            'gitBinary' => Settings::gitBinary($values['git_path'] ?? ''),
            'phpBinary' => Settings::phpBinary($values['php_path'] ?? ''),
            'sshBinary' => Settings::sshBinary($values['ssh_path'] ?? ''),
            'storage' => $this->storageSummary(),
        ], 'Settings');
    }

    /** Where the tool keeps its data, and how much of it there is, for the Settings page. */
    private function storageSummary(): array
    {
        $backups = glob(STORAGE_DIR . '/backups/*.zip') ?: [];
        return [
            'dir' => StorageLocation::display(STORAGE_DIR),
            'default' => StorageLocation::display(DEFAULT_STORAGE_DIR),
            'isDefault' => StorageLocation::isDefault(),
            'fromEnv' => StorageLocation::fromEnvironment(),
            'missing' => STORAGE_MISSING,
            'bytes' => StorageLocation::size(STORAGE_DIR),
            'runs' => count(glob(RunStore::dir() . '/*.json') ?: []),
            'backups' => count($backups),
            'backupBytes' => array_sum(array_map('filesize', $backups)),
        ];
    }

    /** Settings → Storage: switch the data folder (or back to storage/ here). */
    private function storageSave(): void
    {
        Http::requirePost();
        $useDefault = ($_POST['storage_mode'] ?? '') === 'default';
        $path = trim((string) ($_POST['storage_path'] ?? ''));
        try {
            if (!$useDefault && $path === '') {
                throw new \RuntimeException('Choose a folder, or pick "storage folder next to the tool".');
            }
            $from = StorageLocation::display(STORAGE_DIR);
            StorageLocation::change($useDefault ? null : $path, ($_POST['storage_copy'] ?? '') === '1');
            Http::flash('success', 'The tool now keeps its data in ' . StorageLocation::display($useDefault ? DEFAULT_STORAGE_DIR : $path)
                . ". The old folder ($from) was left as it is; delete it yourself once you're sure.");
        } catch (\RuntimeException $e) {
            Http::flash('danger', $e->getMessage());
        }
        Http::redirect('r=settings&tab=storage');
    }

    /** Settings → Cloud backup: checks the bucket with the form's current (unsaved) fields. */
    private function cloudTest(): void
    {
        Http::requirePost();
        [$cloud, $errors] = CloudBackup::fromInput($_POST);
        $missing = array_intersect_key($errors, array_flip(['cloud_endpoint', 'cloud_bucket', 'cloud_region', 'cloud_access_key', 'cloud_secret_key']));
        if ($missing) {
            Http::json(['ok' => false, 'error' => reset($missing)]);
        }
        try {
            CloudBackup::test($cloud);
            Http::json(['ok' => true, 'message' => "Connected: the bucket {$cloud['bucket']} answers with these keys."]);
        } catch (\RuntimeException $e) {
            Http::json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** Settings → Cloud backup: one backup now, with the saved settings. */
    private function cloudBackupNow(): void
    {
        Http::requirePost();
        session_write_close();
        set_time_limit(900);
        try {
            $result = CloudBackup::backup();
            Http::json(['ok' => true, 'message' => 'Backed up ' . $result['files'] . ' files (' . number_format($result['size'] / 1048576, 2) . ' MB, encrypted) as ' . basename($result['name']) . '.']);
        } catch (\RuntimeException $e) {
            Http::json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** Settings → Cloud backup: the backups in the bucket. */
    private function cloudList(): void
    {
        Http::requirePost();
        try {
            Http::json(['ok' => true, 'backups' => array_map(static fn (array $b): array => [
                'key' => $b['key'],
                'name' => $b['name'],
                'size' => number_format($b['size'] / 1048576, 2) . ' MB',
                'when' => date('d M Y, H:i', $b['modified']) . ' · ' . ago($b['modified']),
            ], CloudBackup::list())]);
        } catch (\RuntimeException $e) {
            Http::json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private function cloudRestore(): void
    {
        Http::requirePost();
        set_time_limit(900);
        try {
            $result = CloudBackup::restore((string) ($_POST['key'] ?? ''), (string) ($_POST['passphrase'] ?? ''));
            Http::flash('success', "Restored {$result['files']} files from " . basename((string) $_POST['key']) . ". The data from before is in {$result['safety']}.");
        } catch (\RuntimeException $e) {
            Http::flash('danger', 'Not restored: ' . $e->getMessage());
        }
        Http::redirect('r=settings&tab=cloud');
    }

    /** Settings → Storage: folders for the picker. */
    private function storageBrowse(): void
    {
        Http::requireToken();
        try {
            Http::json(['ok' => true] + StorageLocation::browse((string) ($_GET['path'] ?? '')));
        } catch (\RuntimeException $e) {
            Http::json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}

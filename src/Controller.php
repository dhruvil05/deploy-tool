<?php

declare(strict_types=1);

namespace DeployTool;

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
            default => (new RunController())->handle($route),
        };
    }

    private function dashboard(): void
    {
        $locals = [];
        foreach (Sources::all() as $key => $source) {
            try {
                $locals[$key] = LocalStatus::collect($source);
            } catch (\RuntimeException $e) {
                $locals[$key] = ['source' => $source, 'error' => $e->getMessage()];
            }
        }
        $openRuns = RunStore::open();
        foreach ($openRuns as $run) {
            Worker::ensure($run); // picks up runs whose worker ended, for example after a restart
        }
        View::render('dashboard', [
            'page' => 'dashboard',
            'locals' => $locals,
            'layout' => Sites::pipelines(),
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
            'sites' => Sites::ordered(),
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
        $this->renderSettings(Settings::all(), []);
    }

    private function settingsSave(): void
    {
        Http::requirePost();
        $errors = Settings::save($_POST);
        if ($errors) {
            $this->renderSettings(array_merge(Settings::all(), array_map('strval', $_POST)), $errors);
            return;
        }
        Http::flash('success', 'Settings saved.');
        Http::redirect('r=settings');
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

    private function renderSettings(array $values, array $errors): void
    {
        View::render('settings', [
            'page' => 'settings',
            'values' => $values,
            'errors' => $errors,
            'gitBinary' => Settings::gitBinary($values['git_path'] ?? ''),
            'phpBinary' => Settings::phpBinary($values['php_path'] ?? ''),
            'sshBinary' => Settings::sshBinary($values['ssh_path'] ?? ''),
        ], 'Settings');
    }
}

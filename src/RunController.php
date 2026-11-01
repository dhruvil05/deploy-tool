<?php

declare(strict_types=1);

namespace DeployTool;

/** Deploys, compares, rollbacks, history. */
final class RunController
{
    public function handle(string $route): void
    {
        match ($route) {
            'deploy-new' => $this->deployNew(),
            'deploy-start' => $this->deployStart(),
            'compare-new' => $this->compareNew(),
            'compare-start' => $this->compareStart(),
            'compare-record' => $this->compareRecord(),
            'compare-sync' => $this->compareSync(),
            'run' => $this->show(),
            'run-step' => $this->step(),
            'run-approve' => $this->approve(),
            'run-retry' => $this->retry(),
            'run-stop' => $this->stop(),
            'run-cancel' => $this->cancel(),
            'run-abandon' => $this->abandon(),
            'run-sql-done' => $this->sqlDone(),
            'run-rollback' => $this->rollback(),
            'run-diff' => $this->diff(),
            'run-export' => $this->export(),
            'history' => $this->history(),
            'backup-download' => $this->backupDownload(),
            default => Http::deny(404, 'Page not found.'),
        };
    }

    private function deployNew(): void
    {
        $site = $this->site((string) ($_GET['site'] ?? ''));
        View::render('deploy_new', ['page' => 'dashboard', 'site' => $site] + DeployRun::preview($site), 'Deploy to ' . $site['name']);
    }

    private function deployStart(): void
    {
        Http::requirePost();
        $site = $this->site((string) ($_POST['site'] ?? ''));
        $baseline = (string) ($_POST['baseline'] ?? '');
        try {
            $run = DeployRun::create($site, 'deploy', preg_match('/^[0-9a-f]{40}$/', $baseline) ? $baseline : null);
        } catch (\RuntimeException $e) {
            Http::flash('danger', $e->getMessage());
            Http::redirect('r=deploy-new&site=' . urlencode($site['key']));
        }
        Http::redirect('r=run&id=' . urlencode($run['id']));
    }

    private function compareNew(): void
    {
        $site = $this->site((string) ($_GET['site'] ?? ''));
        $git = Sites::git($site);
        $default = null;
        $sourceError = null;
        try {
            $default = $git->commitInfo(DeployRun::sourceCommit($site, $git));
        } catch (\RuntimeException $e) {
            $sourceError = $e->getMessage();
        }
        View::render('compare_new', [
            'page' => 'dashboard',
            'site' => $site,
            'default' => $default,
            'sourceError' => $sourceError,
            'recent' => $default !== null ? $git->recentCommits($default['sha'], 15) : [],
            'lastCompare' => RunStore::latestFor($site['key'], ['compare'], ['done']),
            'lastDeploy' => RunStore::latestFor($site['key'], ['deploy', 'sync'], ['done']),
        ], 'Compare ' . $site['name']);
    }

    private function compareStart(): void
    {
        Http::requirePost();
        $site = $this->site((string) ($_POST['site'] ?? ''));
        $sha = Sites::git($site)->resolve(trim((string) ($_POST['commit'] ?? '')));
        if ($sha === null) {
            Http::flash('danger', 'That commit, branch or tag was not found in your repository.');
            Http::redirect('r=compare-new&site=' . urlencode($site['key']));
        }
        $run = CompareRun::create($site, $sha, (string) ($_POST['method'] ?? Settings::get('compare_method')));
        Http::redirect('r=run&id=' . urlencode($run['id']));
    }

    private function compareRecord(): void
    {
        Http::requirePost();
        $run = $this->run((string) ($_POST['id'] ?? ''));
        try {
            CompareRun::recordVersion($run, !empty($_POST['ack']));
            Http::flash('success', 'Recorded commit ' . short_sha($run['to']) . " on {$run['site_name']}. You can deploy to it now.");
        } catch (\RuntimeException $e) {
            Http::flash('danger', $e->getMessage());
        }
        Http::redirect('r=run&id=' . urlencode($run['id']));
    }

    private function compareSync(): void
    {
        Http::requirePost();
        $compare = $this->run((string) ($_POST['id'] ?? ''));
        try {
            $selected = !empty($_POST['pick']) ? array_values(array_filter((array) ($_POST['files'] ?? []), 'is_string')) : null;
            $run = CompareRun::createSync($compare, $selected, !empty($_POST['include_eol']));
        } catch (\RuntimeException $e) {
            Http::flash('danger', $e->getMessage());
            Http::redirect('r=run&id=' . urlencode($compare['id']));
        }
        Http::redirect('r=run&id=' . urlencode($run['id']));
    }

    private function show(): void
    {
        Backup::packOldFolders();
        $run = $this->run((string) ($_GET['id'] ?? ''));
        $infos = Sources::git(RunStore::source($run))->commitInfos([$run['from'], $run['to']]);
        $info = static fn (?string $sha): ?array => $sha === null ? null : ($infos[$sha] ?? ['sha' => $sha, 'subject' => '']);
        View::render('run', [
            'page' => 'history',
            'run' => $run,
            'fromInfo' => $info($run['from']),
            'toInfo' => $info($run['to']),
            'rollbackBlocker' => in_array($run['type'], ['deploy', 'sync'], true) ? RollbackRun::blocker($run) : 'n/a',
        ], RunView::title($run));
    }

    /**
     * Called over and over by the run page while a run is running. A background worker
     * normally does the work and this only reports progress; without one, the page's
     * calls do the work, a few seconds each.
     */
    private function step(): void
    {
        Http::requirePost();
        session_write_close(); // don't block other pages while this step works
        $id = (string) ($_GET['id'] ?? '');
        $run = RunStore::load($id);
        if (Worker::ensure($run)) {
            Http::json(RunView::live($run) + ['worker' => true]);
        }
        @set_time_limit(120);
        Http::json(RunView::live(Runner::step($id)) + ['worker' => false]);
    }

    private function approve(): void
    {
        Http::requirePost();
        $run = $this->run((string) ($_POST['id'] ?? ''));
        $error = $run['type'] === 'rollback' ? RollbackRun::approve($run, $_POST) : DeployRun::approve($run, $_POST);
        if ($error !== null) {
            Http::flash('danger', $error);
        } else {
            RunStore::save($run);
        }
        Http::redirect('r=run&id=' . urlencode($run['id']));
    }

    private function retry(): void
    {
        Http::requirePost();
        $run = $this->run((string) ($_POST['id'] ?? ''));
        if ($run['status'] === 'failed') {
            $run['status'] = 'running';
            $run['error'] = null;
            Runner::log($run, 'info', 'Retrying.');
            RunStore::save($run);
        }
        Http::redirect('r=run&id=' . urlencode($run['id']));
    }

    private function stop(): void
    {
        Http::requirePost();
        $run = $this->run((string) ($_POST['id'] ?? ''));
        if ($run['status'] === 'running') {
            Runner::requestStop($run['id']);
            Http::flash('warning', 'Stopping after the current file.');
        }
        Http::redirect('r=run&id=' . urlencode($run['id']));
    }

    private function cancel(): void
    {
        Http::requirePost();
        $run = $this->run((string) ($_POST['id'] ?? ''));
        if (empty($run['touched']) && in_array($run['status'], ['review', 'failed'], true)) {
            $run['status'] = 'cancelled';
            Runner::log($run, 'info', 'Cancelled. Nothing was changed on the server.');
            RunStore::save($run);
        }
        Http::redirect('r=run&id=' . urlencode($run['id']));
    }

    /** For a failed run that already changed files: release the site without finishing or rolling back. */
    private function abandon(): void
    {
        Http::requirePost();
        $run = $this->run((string) ($_POST['id'] ?? ''));
        if ($run['status'] === 'failed' && !empty($run['touched'])) {
            $run['status'] = 'abandoned';
            Runner::log($run, 'warning', 'Abandoned. Some files on the server may be from the new commit and some from the old one.');
            RunStore::save($run);
        }
        Http::redirect('r=run&id=' . urlencode($run['id']));
    }

    private function sqlDone(): void
    {
        Http::requirePost();
        $run = $this->run((string) ($_POST['id'] ?? ''));
        if ($run['status'] === 'sql') {
            DeployRun::sqlDone($run);
            RunStore::save($run);
        }
        Http::redirect('r=run&id=' . urlencode($run['id']));
    }

    private function rollback(): void
    {
        Http::requirePost();
        $original = $this->run((string) ($_POST['id'] ?? ''));
        try {
            $run = RollbackRun::create($original);
        } catch (\RuntimeException $e) {
            Http::flash('danger', $e->getMessage());
            Http::redirect('r=run&id=' . urlencode($original['id']));
        }
        Http::redirect('r=run&id=' . urlencode($run['id']));
    }

    /**
     * kind=change: the server copy now vs. what it will be (or was made) by this run.
     * kind=server: what we expected on the server vs. what was actually there.
     */
    private function diff(): void
    {
        $run = $this->run((string) ($_GET['id'] ?? ''));
        $path = (string) ($_GET['path'] ?? '');
        $kind = ($_GET['kind'] ?? '') === 'server' ? 'server' : 'change';
        $file = null;
        foreach ($run['files'] as $candidate) {
            if ($candidate['path'] === $path) {
                $file = $candidate;
                break;
            }
        }
        if ($file === null) {
            Http::deny(404, 'That file is not part of this run.');
        }

        $git = Sources::git(RunStore::source($run));
        [$before, $after, $labels] = DiffView::sides($run, $file, $kind, $git);

        $options = [
            'view' => ($_GET['view'] ?? '') === 'split' ? 'split' : 'unified',
            'ctx' => in_array($_GET['ctx'] ?? '', ['10', 'all'], true) ? (string) $_GET['ctx'] : '3',
        ];
        $text = DiffView::text($git, $before, $after, $options['ctx'] === 'all' ? 1000000 : (int) $options['ctx']);

        $paths = array_column($run['files'], 'path');
        $index = (int) array_search($path, $paths, true);
        View::render('diff', [
            'page' => 'history',
            'run' => $run,
            'file' => $file,
            'path' => $path,
            'kind' => $kind,
            'labels' => $labels,
            'beforeMissing' => $before === null,
            'afterMissing' => $after === null,
            'eolOnly' => $before !== null && $after !== null && $before !== $after
                && str_replace("\r\n", "\n", $before) === str_replace("\r\n", "\n", $after),
            'diff' => DiffView::parse($text),
            'options' => $options,
            'position' => [$index + 1, count($paths)],
            'prevPath' => $paths[$index - 1] ?? null,
            'nextPath' => $paths[$index + 1] ?? null,
        ], 'Changes in ' . basename($path));
    }

    private function export(): void
    {
        $run = $this->run((string) ($_GET['id'] ?? ''));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $run['id'] . '.csv"');
        $out = fopen('php://output', 'w');
        if ($run['type'] === 'compare') {
            fputcsv($out, ['result', 'path']);
            $labels = ['differs' => 'different', 'eol' => 'line endings only', 'missing' => 'missing on server', 'extra' => 'extra on server'];
            foreach ($labels as $key => $label) {
                foreach ($run['result'][$key] ?? [] as $path) {
                    fputcsv($out, [$label, $path]);
                }
            }
        } else {
            fputcsv($out, ['action', 'change', 'path', 'server copy before', 'backup kept', 'done']);
            foreach ($run['files'] as $file) {
                fputcsv($out, [
                    $file['action'],
                    $file['change'],
                    $file['path'],
                    isset($file['server']) ? RunView::serverBadge($file)[0] : '',
                    !empty($file['existed']) ? 'yes' : 'no',
                    !empty($file['done']) ? 'yes' : 'no',
                ]);
            }
            foreach ($run['skipped'] as $file) {
                fputcsv($out, ['skipped (protected)', $file['change'], $file['path'], '', '', '']);
            }
        }
        exit;
    }

    private function history(): void
    {
        Backup::packOldFolders(); // backups saved before zips existed
        $all = RunStore::all();
        $filters = [
            'type' => (string) ($_GET['type'] ?? ''),
            'local' => (string) ($_GET['local'] ?? ''),
            'site' => (string) ($_GET['site'] ?? ''),
            'status' => (string) ($_GET['status'] ?? ''),
            'q' => trim((string) ($_GET['q'] ?? '')),
        ];
        $q = strtolower($filters['q']);
        $runs = array_values(array_filter($all, static fn (array $run): bool =>
            ($filters['local'] === '' || ($run['repo_source'] ?? '') === $filters['local'])
            && ($filters['site'] === '' || $run['site'] === $filters['site'])
            && ($filters['status'] === '' || $run['status'] === $filters['status'])
            && ($q === '' || str_contains(strtolower(implode(' ', [$run['site_name'], $run['by'], (string) $run['from'], (string) $run['to'], $run['id']])), $q))));

        // Counts per type for the type chips, before the type filter itself.
        $kind = static fn (array $run): string => $run['type'] === 'sync' ? 'deploy' : $run['type'];
        $counts = ['' => count($runs), 'deploy' => 0, 'compare' => 0, 'rollback' => 0];
        foreach ($runs as $run) {
            $counts[$kind($run)] = ($counts[$kind($run)] ?? 0) + 1;
        }
        if (isset($counts[$filters['type']]) && $filters['type'] !== '') {
            $runs = array_values(array_filter($runs, static fn (array $run): bool => $kind($run) === $filters['type']));
        }

        if (($_GET['format'] ?? '') === 'csv') {
            $this->historyCsv($runs);
        }

        $perPage = 50;
        $pages = max(1, (int) ceil(count($runs) / $perPage));
        $page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
        $sites = [];
        foreach ($all as $run) {
            $sites[$run['site']] ??= $run['site_name'];
        }
        View::render('history', [
            'page' => 'history',
            'runs' => array_slice($runs, ($page - 1) * $perPage, $perPage),
            'total' => count($runs),
            'counts' => $counts,
            'filters' => $filters,
            'pageNumber' => $page,
            'pages' => $pages,
            'sources' => Sources::all(),
            'sites' => $sites,
        ], 'History');
    }

    /** The filtered history as a spreadsheet. */
    private function historyCsv(array $runs): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="deploy-history-' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['started', 'finished', 'type', 'site', 'local', 'from', 'to', 'files', 'status', 'by', 'run']);
        foreach ($runs as $run) {
            fputcsv($out, [
                date('Y-m-d H:i:s', $run['created_at']),
                isset($run['finished_at']) ? date('Y-m-d H:i:s', $run['finished_at']) : '',
                RunView::TYPES[$run['type']] ?? $run['type'],
                $run['site_name'],
                $run['repo_source'] ?? '',
                (string) $run['from'],
                (string) $run['to'],
                $run['type'] === 'compare' ? '' : count($run['files']),
                RunView::STATUSES[$run['status']][0] ?? $run['status'],
                $run['by'],
                $run['id'],
            ]);
        }
        exit;
    }

    private function backupDownload(): void
    {
        $run = $this->run((string) ($_GET['id'] ?? ''));
        $zip = Backup::zipFile($run['id']);
        if (!is_file($zip) && $run['status'] !== 'running') {
            Backup::pack($run);
        }
        if (!is_file($zip)) {
            Http::deny(404, 'This run has no backup: none of its files existed on the server before it.');
        }
        $name = $run['id'] . '-backup.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($zip));
        readfile($zip);
        exit;
    }

    private function site(string $key): array
    {
        return Sites::get($key) ?? Http::deny(404, 'Site not found.');
    }

    private function run(string $id): array
    {
        try {
            return RunStore::load($id);
        } catch (\RuntimeException) {
            Http::deny(404, 'Run not found.');
        }
    }
}

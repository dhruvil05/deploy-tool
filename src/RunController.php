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
        $git = Sites::git($site);
        $data = [
            'page' => 'dashboard',
            'site' => $site,
            'open' => RunStore::activeRunFor($site['key']),
            'source' => null,
            'sourceError' => null,
            'state' => null,
            'stateError' => null,
            'commits' => [],
            'changes' => null,
        ];
        try {
            $data['source'] = $git->commitInfo(DeployRun::sourceCommit($site, $git));
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
                $data['changes'] = [
                    'commits' => $git->countBetween($data['state']['commit'], $data['source']['sha']),
                    'files' => count($git->changedFiles($data['state']['commit'], $data['source']['sha'])),
                ];
            }
        }
        View::render('deploy_new', $data, 'Deploy to ' . $site['name']);
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
        $run = CompareRun::create($site, $sha, (string) ($_POST['method'] ?? 'sizes'));
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
            $run = CompareRun::createSync($compare, !empty($_POST['include_eol']));
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
        $git = Sources::git(RunStore::source($run));
        $info = static function (?string $sha) use ($git): ?array {
            return $sha !== null && $git->resolve($sha) !== null ? $git->commitInfo($sha) : ($sha !== null ? ['sha' => $sha, 'subject' => ''] : null);
        };
        View::render('run', [
            'page' => 'history',
            'run' => $run,
            'fromInfo' => $info($run['from']),
            'toInfo' => $info($run['to']),
            'rollbackBlocker' => in_array($run['type'], ['deploy', 'sync'], true) ? RollbackRun::blocker($run) : 'n/a',
        ], RunView::title($run));
    }

    /** Called over and over by the run page while a run is running. */
    private function step(): void
    {
        Http::requirePost();
        session_write_close(); // don't block other pages while this step works
        @set_time_limit(120);
        $run = Runner::step((string) ($_GET['id'] ?? ''));
        Http::json(RunView::live($run));
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
        if ($run['type'] === 'rollback') {
            $before = $git->fileAt($run['from'], $path);
            $after = $file['action'] === 'restore' ? Backup::get($run['rollback_of'], $path) : null;
            $labels = ['now (after the deploy)', 'after the rollback'];
        } elseif ($kind === 'server') {
            $before = $run['from'] !== null ? $git->fileAt($run['from'], $path) : null;
            $after = Backup::get($run['id'], $path);
            $labels = ['expected (commit ' . short_sha($run['from']) . ')', 'actually on the server'];
        } else {
            $before = Backup::get($run['id'], $path);
            $after = $file['action'] === 'upload' ? $git->fileAt($run['to'], $path) : null;
            $labels = ['on the server before', 'after this run'];
        }

        $fileA = tempnam(sys_get_temp_dir(), 'dtdiffa');
        $fileB = tempnam(sys_get_temp_dir(), 'dtdiffb');
        file_put_contents($fileA, $before ?? '');
        file_put_contents($fileB, $after ?? '');
        $text = $git->diffFiles($fileA, $fileB);
        @unlink($fileA);
        @unlink($fileB);
        $text = str_replace([str_replace('\\', '/', $fileA), str_replace('\\', '/', $fileB), $fileA, $fileB], ["({$labels[0]})", "({$labels[1]})", "({$labels[0]})", "({$labels[1]})"], $text);

        View::render('diff', [
            'page' => 'history',
            'run' => $run,
            'path' => $path,
            'labels' => $labels,
            'beforeMissing' => $before === null,
            'afterMissing' => $after === null,
            'text' => $text,
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
        View::render('history', ['page' => 'history', 'runs' => RunStore::all()], 'History');
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

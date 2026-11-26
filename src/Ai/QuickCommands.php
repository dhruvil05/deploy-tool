<?php

declare(strict_types=1);

namespace DeployTool\Ai;

use DeployTool\LocalStatus;
use DeployTool\RunStore;
use DeployTool\Settings;
use DeployTool\Sites;
use DeployTool\Sources;

/**
 * Speed-up "Quick commands in the chat": a message starting with / runs at once, without the
 * AI provider (so it's instant, free, and works while the provider is down). The commands use
 * the same actions as the assistant, so the same AI permissions and the chat's local apply.
 */
final class QuickCommands
{
    public const HELP = "**Quick commands** (instant, no AI):\n"
        . "- `/status`: the local and each site's last deploy\n"
        . "- `/status staging`: check a site now (its version, how far behind)\n"
        . "- `/compare staging`: start a compare\n"
        . "- `/compare prod f16451a8 9c1e2d3`: compare only those commits' files\n"
        . "- `/deploy staging`: prepare a deploy; it stops at your review\n"
        . "- `/history` or `/history 20`: the latest runs\n\n"
        . 'A site can be named by its key or part of its name. Anything else goes to the assistant.';

    public static function applies(string $text): bool
    {
        return Settings::on('feature_quick_commands') && preg_match('#^/[a-z]#i', ltrim($text)) === 1;
    }

    /** Adds the user's command and the answer to $chat. */
    public static function handle(array &$chat, string $text): void
    {
        $chat['messages'][] = ['role' => 'user', 'content' => $text, 'at' => time()];
        [$command, $argument] = array_pad(preg_split('/\s+/', trim(ltrim(trim($text), '/')), 2) ?: [''], 2, '');
        try {
            $answer = match (strtolower($command)) {
                'help', '?' => self::HELP,
                'status' => $argument === '' ? self::localStatus((string) $chat['local']) : self::siteStatus($chat, $argument),
                'compare' => self::compare($chat, $argument),
                'deploy' => self::start($chat, $argument, 'prepare_deploy', 'Deploy being prepared', 'It runs the checks and a backup, then stops at your review.'),
                'history' => self::history($chat, $argument),
                default => "There's no command /$command.\n\n" . self::HELP,
            };
        } catch (\RuntimeException $e) {
            $answer = $e->getMessage();
        }
        $chat['messages'][] = ['role' => 'assistant', 'content' => $answer, 'model' => 'quick command', 'quick' => true, 'at' => time()];
    }

    private static function localStatus(string $localKey): string
    {
        $source = Sources::get($localKey) ?? throw new \RuntimeException('This chat has no local.');
        $local = LocalStatus::collect($source);
        $lines = ["**{$source['name']}** · `{$source['branch']}`"];
        if (!empty($local['error'])) {
            $lines[] = '- ' . $local['error'];
        } else {
            $lines[] = '- Your branch: `' . substr($local['commit']['sha'], 0, 8) . '` ' . $local['commit']['subject'];
            if ($local['remote_commit'] !== null) {
                $lines[] = '- GitHub: ' . ($local['ahead'] === 0 && $local['behind'] === 0 ? 'the same' : "you're {$local['ahead']} ahead, {$local['behind']} behind")
                    . ' (fetched ' . \ago($local['last_fetch']) . ')';
            }
            foreach (array_merge($local['malware'], $local['remote_malware']) as $finding) {
                $lines[] = "- **Malware marker:** $finding";
            }
        }
        $lines[] = '';
        foreach (self::sites($localKey) as $site) {
            $last = RunStore::latestFor($site['key'], ['deploy', 'sync'], ['done', 'sql', 'rolled_back']);
            $open = RunStore::activeRunFor($site['key']);
            $lines[] = "- **{$site['name']}**" . ($site['production'] ? ' (production)' : '') . ': '
                . ($last !== null ? 'last deploy `' . substr((string) $last['to'], 0, 8) . '` ' . \ago((int) $last['created_at']) . ' by ' . $last['by'] : 'no deploy from this tool yet')
                . ($open !== null ? ' · open run: [' . $open['id'] . '](index.php?r=run&id=' . urlencode($open['id']) . ')' : '');
        }
        return implode("\n", $lines) . "\n\n`/status <site>` checks a site on the server.";
    }

    private static function siteStatus(array $chat, string $name): string
    {
        $site = self::site($chat, $name);
        $data = self::call($chat, 'site_status', ['site' => $site['key']]);
        if (empty($data['ok'])) {
            return "**{$site['name']}**: " . ($data['error'] ?? "couldn't be checked") . '.';
        }
        $commit = $data['commit'] ?? null;
        $lines = ["**{$site['name']}**" . ($site['production'] ? ' (production)' : '')];
        $lines[] = '- Recorded version: ' . ($commit === null ? 'none yet' : '`' . substr($commit['sha'], 0, 8) . '` ' . ($commit['subject'] ?? '(not in your repository)'));
        $lines[] = '- ' . match (true) {
            !empty($data['diverged']) => 'Its commit is not in the line of what it gets code from.',
            $data['behind'] === null => 'How far behind: unknown.',
            $data['behind'] === 0 => 'Up to date with what it gets code from.',
            default => "{$data['behind']} commit(s) behind what it gets code from.",
        };
        if (!empty($data['open_run'])) {
            $lines[] = '- Open run: [' . $data['open_run'] . '](index.php?r=run&id=' . urlencode($data['open_run']) . ')';
        }
        return implode("\n", $lines);
    }

    /**
     * /compare site, or /compare site abc1234 def5678 for only those commits' files (speed-up
     * "Compare only some commits"): trailing words that look like commit ids are the commits.
     */
    private static function compare(array $chat, string $argument): string
    {
        $words = preg_split('/\s+/', trim($argument)) ?: [];
        $commits = [];
        while (count($words) > 1 && preg_match('/^[0-9a-f]{7,40}$/i', (string) end($words)) === 1) {
            array_unshift($commits, array_pop($words));
        }
        if ($commits === []) {
            return self::start($chat, $argument, 'start_compare', 'Compare started', '');
        }
        return self::start($chat, implode(' ', $words), 'start_compare', 'Compare of ' . count($commits) . ' commit(s) started', 'Only their files are read.', ['commits' => $commits]);
    }

    private static function start(array $chat, string $name, string $action, string $what, string $after, array $extra = []): string
    {
        $site = self::site($chat, $name);
        $data = self::call($chat, $action, ['site' => $site['key']] + $extra);
        $id = (string) ($data['run_id'] ?? '');
        return "$what on **{$site['name']}**" . ($site['production'] ? ' (production)' : '') . '.' . ($after !== '' ? " $after" : '')
            . ($id !== '' ? " [Open the run](index.php?r=run&id=" . urlencode($id) . ')' : '');
    }

    private static function history(array $chat, string $argument): string
    {
        $limit = ctype_digit($argument) ? max(1, min(30, (int) $argument)) : 10;
        $runs = (array) (self::call($chat, 'list_runs', ['limit' => $limit])['runs'] ?? []);
        if ($runs === []) {
            return 'No runs yet.';
        }
        $rows = ['| Started | Run | Site | Status | Commit |', '|---|---|---|---|---|'];
        foreach ($runs as $run) {
            $rows[] = '| ' . $run['started'] . ' | [' . $run['kind'] . '](index.php?r=run&id=' . urlencode($run['run_id']) . ') | ' . $run['site'] . ' | ' . $run['status'] . ' | `' . ($run['to_commit'] ?? '') . '` |';
        }
        return implode("\n", $rows);
    }

    /** One of the chat's local's sites, by key or (part of) its name. */
    private static function site(array $chat, string $name): array
    {
        $name = strtolower(trim($name));
        $sites = self::sites((string) $chat['local']);
        if ($name === '') {
            throw new \RuntimeException('Which site? ' . self::choices($sites));
        }
        foreach ($sites as $site) {
            if ($site['key'] === $name || strtolower($site['name']) === $name) {
                return $site;
            }
        }
        $matches = array_values(array_filter($sites, static fn (array $s): bool => str_contains($s['key'], $name) || str_contains(strtolower($s['name']), $name)));
        if (count($matches) === 1) {
            return $matches[0];
        }
        throw new \RuntimeException(($matches === [] ? "No site matches \"$name\"." : "\"$name\" fits more than one site.") . ' ' . self::choices($matches ?: $sites));
    }

    private static function sites(string $localKey): array
    {
        return array_values(array_filter(Sites::ordered(), static function (array $site) use ($localKey): bool {
            try {
                return Sites::rootSource($site)['key'] === $localKey;
            } catch (\RuntimeException) {
                return false;
            }
        }));
    }

    private static function choices(array $sites): string
    {
        return $sites === [] ? 'This local has no sites yet.' : 'Pick one of: ' . implode(', ', array_map(static fn (array $s): string => '`' . $s['key'] . '`', $sites)) . '.';
    }

    /** Runs an assistant action for the chat's local; its error becomes the answer. */
    private static function call(array $chat, string $action, array $arguments): array
    {
        $outcome = Tools::run($action, $arguments, (string) $chat['local']);
        $data = json_decode($outcome['result'], true);
        if (!$outcome['ok']) {
            throw new \RuntimeException((string) ($data['error'] ?? 'That didn\'t work.'));
        }
        return is_array($data) ? $data : [];
    }
}

<?php

declare(strict_types=1);

namespace DeployTool\Ai;

use DeployTool\Ai;
use DeployTool\Settings;
use DeployTool\Sites;
use DeployTool\Sources;

/**
 * Answers one message in a chat: asks the model, runs the actions it picks, gives it the
 * results, and repeats until it answers in words or reaches the step limit.
 */
final class Agent
{
    public function __construct(private readonly Provider $provider, private readonly array $settings)
    {
    }

    public static function fromSettings(): self
    {
        $settings = Ai::settings();
        return new self(new Provider($settings), $settings);
    }

    /**
     * Adds the user's message and everything the assistant does about it to $chat.
     * $checkpoint($chat) is called whenever something new is in the chat (so the page can
     * show progress) and returns true when the user asked to stop.
     */
    public function reply(array &$chat, string $text, ?callable $checkpoint = null): void
    {
        $checkpoint ??= static fn (): bool => false;
        $chat['messages'][] = ['role' => 'user', 'content' => $text, 'at' => time()];
        $steps = max(1, (int) $this->settings['max_steps']);
        $stopped = static function (array &$chat): void {
            $chat['messages'][] = ['role' => 'assistant', 'content' => 'Stopped, as you asked.', 'stopped' => true, 'at' => time()];
        };
        if ($checkpoint($chat)) {
            $stopped($chat);
            return;
        }

        for ($step = 0; $step < $steps; $step++) {
            try {
                $reply = $this->provider->chat($this->systemPrompt($chat), $this->history($chat), Tools::allowedDefinitions());
            } catch (\RuntimeException $e) {
                $chat['messages'][] = ['role' => 'assistant', 'content' => $e->getMessage(), 'error' => true, 'at' => time()];
                return;
            }
            $chat['usage']['in'] += $reply['usage']['in'];
            $chat['usage']['out'] += $reply['usage']['out'];
            if ($reply['usage']['cost'] !== null) {
                $chat['usage']['cost'] = ($chat['usage']['cost'] ?? 0.0) + $reply['usage']['cost'];
            }

            if ($reply['calls'] === []) {
                $answer = $reply['text'] !== '' ? $reply['text'] : 'I have no answer for that.';
                if ($reply['stop'] === 'length') {
                    $answer .= "\n\n(My answer was cut off: it reached the length limit.)";
                }
                $chat['messages'][] = ['role' => 'assistant', 'content' => $answer, 'at' => time()];
                return;
            }

            // Each action is saved as soon as it is done. Every call gets a result, even after
            // a stop, because providers refuse a conversation with an unanswered call.
            $chat['messages'][] = ['role' => 'assistant', 'content' => $reply['text'], 'calls' => $reply['calls'], 'actions' => [], 'at' => time()];
            $index = array_key_last($chat['messages']);
            $stop = $checkpoint($chat);
            foreach ($reply['calls'] as $call) {
                if ($stop) {
                    $chat['messages'][] = ['role' => 'tool', 'call_id' => $call['id'], 'name' => $call['name'], 'content' => '{"error":"Not run: the user stopped."}'];
                    continue;
                }
                $outcome = Tools::run($call['name'], $call['arguments'], $chat['local']);
                $chat['messages'][$index]['actions'][] = ['label' => $outcome['label'], 'ok' => $outcome['ok'], 'link' => $outcome['link'], 'run' => $outcome['run']];
                $chat['messages'][] = ['role' => 'tool', 'call_id' => $call['id'], 'name' => $call['name'], 'content' => $outcome['result']];
                $stop = $checkpoint($chat);
            }
            if ($stop) {
                $stopped($chat);
                return;
            }
        }

        $chat['messages'][] = ['role' => 'assistant', 'content' => "I stopped after $steps steps, the most one message may take (change it in Settings). Ask me to go on, or narrow the question.", 'error' => true, 'at' => time()];
    }

    /**
     * The messages the model sees: the latest ones up to the "messages kept" limit, starting
     * at a user message so no action result is cut from the call that asked for it.
     * Error notes are the tool's own, not the model's, and are left out.
     */
    private function history(array $chat): array
    {
        $messages = array_values(array_filter($chat['messages'], static fn (array $m): bool => empty($m['error']) && empty($m['stopped'])));
        $messages = array_slice($messages, -max(10, (int) $this->settings['max_messages']));
        while ($messages !== [] && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }
        return array_map(static fn (array $m): array => array_intersect_key($m, array_flip(['role', 'content', 'calls', 'call_id', 'name'])), $messages);
    }

    private function listOrNone(array $items): string
    {
        return $items !== [] ? implode('; ', $items) : 'none yet';
    }

    private function systemPrompt(array $chat): string
    {
        $local = Sources::get((string) $chat['local']);
        $sites = [];
        foreach (Sites::ordered() as $key => $site) {
            try {
                if (Sites::rootSource($site)['key'] === $chat['local']) {
                    $sites[] = "{$site['name']} (key $key, gets code from " . Sites::sourceLabel($site) . ($site['production'] ? ', production' : '') . ')';
                }
            } catch (\RuntimeException) {
                // not connected to any local
            }
        }
        $localLine = $local === null ? 'No local is picked for this chat.' : <<<SCOPE
            This chat is about one local only: {$local['name']} (key {$local['key']}, branch {$local['branch']}). Its sites, in pipeline order: {$this->listOrNone($sites)}.
            You can only see and use this local, these sites and their runs; the actions refuse anything else. When the user says "staging" or "prod", they mean the matching site above. If they ask about another local or a site that isn't listed, say this chat is about {$local['name']} and that they can start a new chat with the other local picked. Never use a site from another local instead.
            SCOPE;
        $name = Settings::get('deployer_name') ?: 'the user';
        $today = date('l j F Y, H:i');
        // What the user allowed in Settings → AI permissions, and where they click for the rest.
        $where = [
            'fetch' => '"Fetch from GitHub" on the dashboard',
            'compare' => '"Compare" on the site\'s card on the dashboard',
            'prepare' => '"Deploy" on the site\'s card, or "Make it match" on a compare\'s page',
            'review_files' => 'the review page (by preparing a match with only the files they want)',
            'approve' => '"Approve" on the run\'s review page',
            'sql' => '"SQL is done (or not needed)" on the run\'s page',
            'retry' => '"Retry" on the failed run\'s page',
            'rollback' => '"Roll back this deploy…" on the deploy\'s page',
            'record' => '"Record version" on the compare\'s page',
            'cancel' => '"Cancel" on the run\'s page',
            'protect' => 'the local\'s page under Sites',
            'add_local' => '"Add local" on the Sites page',
            'edit_local' => '"Edit local" on the Sites page',
            'add_site' => '"Add site" on the Sites page',
            'edit_site' => '"Edit" on the site under Sites',
        ];
        $allowed = $denied = [];
        foreach (Ai::PERMISSIONS as $key => [$label]) {
            if (($this->settings['perm_' . $key] ?? '0') === '1') {
                $allowed[] = $label;
            } else {
                $denied[] = "$label (they click {$where[$key]})";
            }
        }
        $permissions = '- Allowed beyond reading: ' . ($allowed ? implode('; ', $allowed) : 'nothing') . '.'
            . ($denied ? "\n- Not allowed, so tell the user where to click and that they can allow it in Settings → AI permissions: " . implode('; ', $denied) . '.' : '');
        return <<<TEXT
            You are the assistant inside "Deploy tool", a web app on this computer that deploys PHP projects from git to servers. You talk with $name. It is $today.

            How the tool works: a local is a git repository on this computer plus a branch. A site is a server (FTP or SSH) or a test folder; it gets its code from a local or from another site, so sites form a pipeline such as staging → prod. Runs are what the tool does to a site: a compare (server files vs. a commit), a deploy, a "match to commit" (uploads only chosen files), and a rollback. Deploys go through checks, a backup of the server's files and the user's review before anything changes on the server. Protected paths (uploads, vendor folders and so on) are never touched.

            $localLine

            What you may do (the user's Settings → AI permissions):
            $permissions
            - Never, whatever is allowed: change the tool's settings, delete anything, abandon a run, unmark a production site, or approve, retry, roll back or record a version on a production site (the user does those on the run's page).
            - Never ask for, accept or repeat passwords or keys. If the user types one in the chat, don't use it; tell them to enter it on the site's page instead.
            - When adding or editing a site or local, ask for anything you need that the user didn't give (name, type, host, folder…), and repeat back what you will save before saving.

            Rules:
            - Use the actions to look things up. Never guess site keys, commits, file names or numbers; if an action fails, say so plainly.
            - After starting a compare, deploy, match, rollback or retry, use wait_for_run and report what happened. A prepared deploy, match or rollback stops at the review: tell the user it is ready, list its files briefly, and ask whether to go ahead (the chat also shows an "Open review" button).
            - Before preparing anything for a production site, ask the user to confirm in the chat, unless they clearly asked for exactly that. Never do more than the user asked for.
            - When the user wants files ticked or unticked, use prepare_match's paths before a match is prepared, or set_review_files on a run already waiting for review. Match their names to the exact paths with get_run; if a name fits several files or none, ask.
            - Approve, mark SQL done, retry, roll back, record a version or cancel only when the user's latest message clearly asks for that, for that run. Before approving, show them the files and any warnings; they must accept the warnings. Never do these on your own initiative.
            - Answer briefly in plain words. Format with Markdown: **bold**, `code` for commits and paths, "- " lists. Use a table when the user asks for one or when comparing several runs or sites side by side, and a short heading only to split a long answer. Link a run as [run](index.php?r=run&id=RUN_ID) when it helps.
            TEXT;
    }
}

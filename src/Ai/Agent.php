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
        // A question left unanswered (Allow / Deny) counts as "no" once the user writes on.
        if (self::pendingIndex($chat) !== null) {
            $this->settle($chat, false, $checkpoint, 'Not run: the user wrote a new message instead of allowing it.');
        }
        $chat['messages'][] = ['role' => 'user', 'content' => $text, 'at' => time()];
        $this->work($chat, $checkpoint);
    }

    /**
     * The user's answer to the Allow / Deny question: runs (or refuses) the waiting actions,
     * then lets the assistant carry on from there.
     */
    public function resume(array &$chat, bool $allow, ?callable $checkpoint = null): void
    {
        $checkpoint ??= static fn (): bool => false;
        if (self::pendingIndex($chat) === null) {
            throw new \RuntimeException('Nothing is waiting for your answer in this chat.');
        }
        if ($this->settle($chat, $allow, $checkpoint, 'Not run: the user said no.')) {
            $this->work($chat, $checkpoint);
        }
    }

    /** The index of the assistant message whose actions wait for Allow / Deny, or null. */
    public static function pendingIndex(array $chat): ?int
    {
        $last = array_key_last($chat['messages']);
        return $last !== null && !empty($chat['messages'][$last]['pending']) ? $last : null;
    }

    /** Asks the model, runs what it picks, and repeats until it answers, asks, or stops. */
    private function work(array &$chat, callable $checkpoint): void
    {
        $steps = max(1, (int) $this->settings['max_steps']);
        if ($checkpoint($chat)) {
            $this->stopped($chat);
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
                $chat['messages'][] = ['role' => 'assistant', 'content' => $answer, 'model' => $reply['model'], 'at' => time()];
                return;
            }

            $chat['messages'][] = ['role' => 'assistant', 'content' => $reply['text'], 'calls' => $reply['calls'], 'actions' => [], 'model' => $reply['model'], 'at' => time()];
            // An action set to "Ask me first": nothing runs until the user clicks Allow or Deny.
            $asks = array_filter($reply['calls'], static fn (array $call): bool => isset(Tools::NEEDS[$call['name']]) && Ai::mode(Tools::NEEDS[$call['name']]) === 'ask');
            if ($asks !== []) {
                $index = array_key_last($chat['messages']);
                $chat['messages'][$index]['pending'] = true;
                $chat['messages'][$index]['asking'] = array_map(static fn (array $call): string => Tools::label($call['name'], $call['arguments'], true), array_values($asks));
                return;
            }
            if ($this->runCalls($chat, array_key_last($chat['messages']), null, $checkpoint)) {
                $this->stopped($chat);
                return;
            }
        }

        $chat['messages'][] = ['role' => 'assistant', 'content' => "I stopped after $steps steps, the most one message may take (change it in Settings). Ask me to go on, or narrow the question.", 'error' => true, 'at' => time()];
    }

    /**
     * Answers the waiting question: with Allow every call runs; with Deny the ones that
     * needed asking are refused and the others still run. Returns whether to carry on.
     */
    private function settle(array &$chat, bool $allow, callable $checkpoint, string $refusal): bool
    {
        $index = (int) self::pendingIndex($chat);
        unset($chat['messages'][$index]['pending']);
        $chat['messages'][$index]['answer'] = $allow ? 'allowed' : 'denied';
        if ($this->runCalls($chat, $index, $allow ? null : $refusal, $checkpoint)) {
            $this->stopped($chat);
            return false;
        }
        return true;
    }

    /**
     * Runs the calls of the assistant message at $index, saving each result as it comes.
     * With $refusal, calls that need asking get it as their result instead of running.
     * Every call gets a result, even after a stop: providers refuse unanswered calls.
     * Returns true when the user asked to stop.
     */
    private function runCalls(array &$chat, int $index, ?string $refusal, callable $checkpoint): bool
    {
        $stop = $checkpoint($chat);
        foreach ($chat['messages'][$index]['calls'] as $call) {
            $asks = isset(Tools::NEEDS[$call['name']]) && Ai::mode(Tools::NEEDS[$call['name']]) === 'ask';
            if ($stop || ($refusal !== null && $asks)) {
                $why = $stop ? 'Not run: the user stopped.' : $refusal;
                if (!$stop) {
                    $chat['messages'][$index]['actions'][] = ['label' => Tools::label($call['name'], $call['arguments'], true) . ' · you said no', 'ok' => false, 'link' => null, 'run' => null];
                }
                $chat['messages'][] = ['role' => 'tool', 'call_id' => $call['id'], 'name' => $call['name'], 'content' => json_encode(['error' => $why])];
                continue;
            }
            $outcome = Tools::run($call['name'], $call['arguments'], $chat['local']);
            $chat['messages'][$index]['actions'][] = ['label' => $outcome['label'], 'ok' => $outcome['ok'], 'link' => $outcome['link'], 'run' => $outcome['run']];
            $chat['messages'][] = ['role' => 'tool', 'call_id' => $call['id'], 'name' => $call['name'], 'content' => $outcome['result']];
            $stop = $checkpoint($chat);
        }
        return $stop;
    }

    private function stopped(array &$chat): void
    {
        $chat['messages'][] = ['role' => 'assistant', 'content' => 'Stopped, as you asked.', 'stopped' => true, 'at' => time()];
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
            'review_files' => 'the tick boxes on the compare\'s page',
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
        $allowed = $asks = $denied = [];
        foreach (Ai::PERMISSIONS as $key => [$label]) {
            match (Ai::mode($key, $this->settings)) {
                'allow' => $allowed[] = $label,
                'ask' => $asks[] = $label,
                default => $denied[] = "$label (they click {$where[$key]})",
            };
        }
        $permissions = '- Allowed beyond reading: ' . ($allowed ? implode('; ', $allowed) : 'nothing') . '.'
            . ($asks ? "\n- Allowed after the user clicks Allow: " . implode('; ', $asks) . '. Just call the action when they ask for it; the chat then shows them Allow / Deny buttons and you continue with the result. Don\'t ask for permission in words first.' : '')
            . ($denied ? "\n- Not allowed, so tell the user where to click and that they can allow it in Settings → AI permissions: " . implode('; ', $denied) . '.' : '');
        $own = trim((string) ($this->settings['instructions'] ?? ''));
        $ownBlock = $own === '' ? '' : "\n\nThe user's own instructions for you (follow them, unless they conflict with the rules above or ask for something not allowed):\n" . $own;
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
            - Do exactly what the user asked, nothing more. "Tick" or "untick" about a compare means set_compare_ticks only: it starts nothing. "Tick" or "untick" about a run waiting for review means set_review_files. Only prepare a match or deploy when the user asks to upload, deploy, prepare or make it match.
            - Short follow-ups ("that one too", "also X") are about the run or files you just handled. Match file names to exact paths with get_run; if a name fits several files or none, ask. Read typos generously ("intick" is untick).
            - Approve, mark SQL done, retry, roll back, record a version or cancel only when the user's latest message clearly asks for that, for that run. Before approving, show them the files and any warnings; they must accept the warnings. Never do these on your own initiative.
            - After an action, say what changed in one or two lines; don't claim something is done unless an action result shows it.
            - Answer briefly in plain words. Format with Markdown: **bold**, `code` for commits and paths, "- " lists. Use a table when the user asks for one or when comparing several runs or sites side by side, and a short heading only to split a long answer. Link a run as [run](index.php?r=run&id=RUN_ID) when it helps.$ownBlock
            TEXT;
    }
}

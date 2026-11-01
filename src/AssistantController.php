<?php

declare(strict_types=1);

namespace DeployTool;

use DeployTool\Ai\Agent;
use DeployTool\Ai\ChatStore;

/** The Assistant page: chats with the AI assistant. Gone entirely when .env turns AI off. */
final class AssistantController
{
    public function handle(string $route): void
    {
        if (!Ai::allowed()) {
            Http::deny(404, 'Page not found.');
        }
        match ($route) {
            'assistant' => $this->page(),
            'assistant-new' => $this->newChat(),
            'assistant-send' => $this->send(),
            'assistant-poll' => $this->poll(),
            'assistant-stop' => $this->stop(),
            'assistant-rename' => $this->rename(),
            'assistant-delete' => $this->delete(),
        };
    }

    private function page(): void
    {
        $sources = Sources::all();
        $chat = ChatStore::load((string) ($_GET['chat'] ?? ''));
        $local = $chat['local'] ?? (string) ($_GET['local'] ?? $_COOKIE['dt_local'] ?? '');
        if (!isset($sources[$local])) {
            $local = (string) array_key_first($sources);
        }
        View::render('assistant', [
            'page' => 'assistant',
            'enabled' => Ai::enabled(),
            'settings' => Ai::settings(),
            'chats' => ChatStore::all(),
            'chat' => $chat,
            'sources' => $sources,
            'local' => $local,
        ], 'Assistant');
    }

    /** Creates an empty chat, so the page knows its id before the first answer starts. */
    private function newChat(): void
    {
        Http::requirePost();
        $local = (string) ($_POST['local'] ?? '');
        $chat = ChatStore::create(Sources::get($local) !== null ? $local : Sources::firstKey());
        ChatStore::save($chat);
        Http::json(['ok' => true, 'chat' => $chat['id']]);
    }

    /**
     * One message: runs the assistant (it may take minutes) and returns its answer as HTML.
     * The chat is saved after every step, so poll() can show the actions as they happen.
     */
    private function send(): void
    {
        Http::requirePost();
        if (!Ai::enabled()) {
            Http::json(['ok' => false, 'error' => 'The assistant is switched off in Settings.'], 403);
        }
        $text = trim((string) ($_POST['message'] ?? ''));
        if ($text === '') {
            Http::json(['ok' => false, 'error' => 'Type a message first.'], 422);
        }
        $chat = ChatStore::load((string) ($_POST['chat'] ?? '')) ?? Http::json(['ok' => false, 'error' => 'This chat no longer exists. Start a new one.'], 404);
        $lock = ChatStore::lock($chat['id']) ?? Http::json(['ok' => false, 'error' => 'Still answering your last message. Wait for it, or press Stop.'], 409);
        // The model and the actions can take minutes; let other pages load meanwhile.
        session_write_close();
        set_time_limit(600);
        ChatStore::clearStop($chat['id']);

        try {
            Agent::fromSettings()->reply($chat, $text, static function (array $chat): bool {
                try {
                    ChatStore::save($chat);
                } catch (\RuntimeException) {
                    // Only progress for the page; Windows can refuse while the page reads the file.
                }
                return ChatStore::stopRequested($chat['id']);
            });
            for ($try = 1; ; $try++) {
                try {
                    ChatStore::save($chat);
                    break;
                } catch (\RuntimeException $e) {
                    if ($try === 5) {
                        throw $e;
                    }
                    usleep(200_000);
                }
            }
        } finally {
            ChatStore::clearStop($chat['id']);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        Http::json($this->state($chat) + [
            'title' => $chat['title'],
            'list' => View::partial('assistant_list', ['chats' => ChatStore::all(), 'current' => $chat['id'], 'sources' => Sources::all()]),
        ]);
    }

    /** While an answer is being written: what the assistant has done so far. */
    private function poll(): void
    {
        session_write_close();
        $chat = ChatStore::load((string) ($_GET['chat'] ?? '')) ?? Http::json(['ok' => false, 'error' => 'Chat not found.'], 404);
        Http::json($this->state($chat) + ['busy' => ChatStore::isBusy($chat['id'])]);
    }

    private function stop(): void
    {
        Http::requirePost();
        $chat = ChatStore::load((string) ($_POST['chat'] ?? '')) ?? Http::json(['ok' => false, 'error' => 'Chat not found.'], 404);
        if (ChatStore::isBusy($chat['id'])) {
            ChatStore::requestStop($chat['id']);
        }
        Http::json(['ok' => true]);
    }

    /** The answer to the latest message as HTML (everything after it), and the chat's usage. */
    private function state(array $chat): array
    {
        $lastUser = 0;
        foreach ($chat['messages'] as $index => $message) {
            $lastUser = $message['role'] === 'user' ? $index : $lastUser;
        }
        return [
            'ok' => true,
            'chat' => $chat['id'],
            'html' => View::partial('assistant_turns', ['messages' => array_slice($chat['messages'], $lastUser + 1)]),
            'usage' => ChatStore::usageText($chat),
        ];
    }

    private function rename(): void
    {
        Http::requirePost();
        $id = (string) ($_POST['chat'] ?? '');
        $error = ChatStore::rename($id, (string) ($_POST['title'] ?? ''));
        if ($error !== null) {
            Http::json(['ok' => false, 'error' => $error], 422);
        }
        Http::json([
            'ok' => true,
            'title' => ChatStore::load($id)['title'],
            'list' => View::partial('assistant_list', ['chats' => ChatStore::all(), 'current' => $id, 'sources' => Sources::all()]),
        ]);
    }

    /**
     * Deletes one chat (chat) or several (chats[]). A chat still being answered is kept.
     * Comes back to the open chat (back) when it wasn't one of them.
     */
    private function delete(): void
    {
        Http::requirePost();
        $ids = array_values(array_filter(array_merge([(string) ($_POST['chat'] ?? '')], (array) ($_POST['chats'] ?? [])), static fn ($id): bool => is_string($id) && $id !== ''));
        $deleted = $busy = 0;
        foreach (array_unique($ids) as $id) {
            if (ChatStore::load($id) === null) {
                continue;
            }
            if (ChatStore::isBusy($id)) {
                $busy++;
                continue;
            }
            ChatStore::delete($id);
            $deleted++;
        }
        Http::flash($busy ? 'warning' : 'success', ($deleted === 1 ? 'Deleted 1 chat.' : "Deleted $deleted chats.")
            . ($busy ? " $busy still being answered " . ($busy === 1 ? 'was' : 'were') . ' kept; delete ' . ($busy === 1 ? 'it' : 'them') . ' when the answer is done.' : ''));
        $back = (string) ($_POST['back'] ?? '');
        Http::redirect('r=assistant' . ($back !== '' && ChatStore::load($back) !== null ? '&chat=' . urlencode($back) : ''));
    }
}

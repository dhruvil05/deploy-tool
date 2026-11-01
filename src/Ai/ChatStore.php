<?php

declare(strict_types=1);

namespace DeployTool\Ai;

use DeployTool\JsonStore;

/** Assistant chats, one file each: storage/chats/<id>.json. */
final class ChatStore
{
    public static function dir(): string
    {
        return STORAGE_DIR . '/chats';
    }

    public static function create(string $local): array
    {
        return [
            'id' => date('Ymd-His') . '-' . bin2hex(random_bytes(3)),
            'title' => 'New chat',
            'local' => $local,
            'created_at' => time(),
            'updated_at' => time(),
            'messages' => [],
            'usage' => ['in' => 0, 'out' => 0],
        ];
    }

    public static function load(string $id): ?array
    {
        if (!preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}$/', $id)) {
            return null;
        }
        $chat = JsonStore::readFile(self::dir() . "/$id.json");
        return $chat !== [] ? $chat : null;
    }

    public static function save(array &$chat): void
    {
        $chat['updated_at'] = time();
        // A name given while an answer was being written wins over the answer's copy.
        $stored = JsonStore::readFile(self::dir() . "/{$chat['id']}.json");
        if (!empty($stored['named'])) {
            $chat['title'] = $stored['title'];
            $chat['named'] = true;
        }
        if ($chat['title'] === 'New chat' && empty($chat['named'])) {
            foreach ($chat['messages'] as $message) {
                if ($message['role'] === 'user') {
                    $text = trim((string) preg_replace('/\s+/', ' ', $message['content']));
                    $chat['title'] = strlen($text) > 60 ? rtrim((string) preg_replace('/^(.{0,57}).*$/us', '$1', $text)) . '…' : $text;
                    break;
                }
            }
        }
        JsonStore::writeFile(self::dir() . "/{$chat['id']}.json", $chat);
    }

    /** Gives a chat your own name; it then keeps it (no automatic title from the first message). */
    public static function rename(string $id, string $title): ?string
    {
        $chat = self::load($id);
        $title = trim((string) preg_replace('/\s+/', ' ', $title));
        if ($chat === null) {
            return 'Chat not found.';
        }
        if ($title === '') {
            return 'Type a name.';
        }
        $chat['title'] = (string) preg_replace('/^(.{0,80}).*$/us', '$1', $title);
        $chat['named'] = true;
        self::save($chat);
        return null;
    }

    public static function delete(string $id): void
    {
        if (self::load($id) !== null) {
            @unlink(self::dir() . "/$id.json");
            @unlink(self::dir() . "/$id.lock");
            @unlink(self::dir() . "/$id.stop");
        }
    }

    /**
     * Held while the assistant answers in this chat, so a second message waits its turn.
     *
     * @return resource|null null when an answer is already being written
     */
    public static function lock(string $id)
    {
        $lock = fopen(self::dir() . "/$id.lock", 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }
            return null;
        }
        return $lock;
    }

    public static function isBusy(string $id): bool
    {
        $lock = self::lock($id);
        if ($lock === null) {
            return true;
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        return false;
    }

    /** "12,345 tokens · $0.0123" (the price only when the provider reports it, as OpenRouter does). */
    public static function usageText(array $chat): string
    {
        $tokens = (int) ($chat['usage']['in'] ?? 0) + (int) ($chat['usage']['out'] ?? 0);
        $text = number_format($tokens) . ' token' . ($tokens === 1 ? '' : 's');
        if (isset($chat['usage']['cost'])) {
            $text .= ' · $' . number_format((float) $chat['usage']['cost'], $chat['usage']['cost'] < 0.01 ? 4 : 2);
        }
        return $text;
    }

    /** The Stop button: a flag file the running answer checks between steps. */
    public static function requestStop(string $id): void
    {
        touch(self::dir() . "/$id.stop");
    }

    public static function stopRequested(string $id): bool
    {
        return is_file(self::dir() . "/$id.stop");
    }

    public static function clearStop(string $id): void
    {
        @unlink(self::dir() . "/$id.stop");
    }

    /** @return list<array{id: string, title: string, local: string, updated_at: int}> newest first */
    public static function all(): array
    {
        $chats = [];
        foreach (glob(self::dir() . '/*.json') ?: [] as $file) {
            $chat = JsonStore::readFile($file);
            if (isset($chat['id'])) {
                $chats[] = array_intersect_key($chat, array_flip(['id', 'title', 'local', 'updated_at']));
            }
        }
        usort($chats, static fn (array $a, array $b): int => $b['updated_at'] <=> $a['updated_at']);
        return $chats;
    }
}

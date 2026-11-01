<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * The AI assistant's switches and settings. Two switches: DEPLOY_TOOL_AI=false in .env
 * removes it completely (allowed()), and the Settings tick turns it on or off
 * (enabled()). Settings live in storage/ai.json, the API key included.
 */
final class Ai
{
    /** preset => [label, base URL, API style, needs a key] */
    public const PRESETS = [
        'openrouter' => ['OpenRouter', 'https://openrouter.ai/api/v1', 'openai', true],
        'openai' => ['OpenAI', 'https://api.openai.com/v1', 'openai', true],
        'anthropic' => ['Anthropic', 'https://api.anthropic.com/v1', 'anthropic', true],
        'groq' => ['Groq', 'https://api.groq.com/openai/v1', 'openai', true],
        'ollama' => ['Ollama', 'http://localhost:11434/v1', 'openai', false],
        'lmstudio' => ['LM Studio', 'http://localhost:1234/v1', 'openai', false],
        'custom' => ['Custom', '', 'openai', false],
    ];

    /**
     * What the assistant may do beyond reading, each switched on or off in Settings →
     * AI permissions. key => [label, what it does, on by default, group, never on production]
     */
    public const PERMISSIONS = [
        'fetch' => ['Fetch from GitHub', "Downloads the chat's local's latest commits. Your branches and files don't change.", true, 'safe', false],
        'compare' => ['Start compares', 'Reads a server and compares it with a commit. On FTP sites a small helper file is uploaded and removed again.', true, 'safe', false],
        'prepare' => ['Prepare deploys and "Make it match"', "Runs the checks and backs up the server's files, then stops at the review. Nothing on the server changes.", true, 'safe', false],
        'review_files' => ['Change the files of a review', 'Leaves files out of a run waiting for review, or puts them back.', true, 'safe', false],
        'approve' => ['Approve deploys and matches', 'Uploads a reviewed run once you approve it in the chat, after it showed you the files and any warnings.', false, 'server', true],
        'sql' => ['Mark the SQL step done', 'Finishes a deploy when you say the database changes are done or not needed.', false, 'server', false],
        'retry' => ['Retry failed runs', 'Runs a failed deploy, match or compare again from where it stopped.', false, 'server', true],
        'rollback' => ['Roll back deploys', 'Prepares a rollback for review. With "Approve" also on, it approves it when you say so.', false, 'server', true],
        'record' => ['Record a version', "Writes a compare's commit as the server's version, accepting the differences it found.", false, 'server', true],
        'cancel' => ['Cancel runs', 'Cancels a run waiting for review, or one that failed before changing anything.', false, 'housekeeping', false],
        'protect' => ['Add protected paths', "Adds paths to the chat's local, so they are never touched on its sites.", false, 'housekeeping', false],
        'add_local' => ['Add locals', 'Adds a git repository on this computer as a new local. Its files are only read, never changed.', false, 'setup', false],
        'edit_local' => ["Edit the chat's local", 'Changes its name, repository folder, branch or remote. Its protected paths have their own switch.', false, 'setup', false],
        'add_site' => ['Add sites', "Adds a site to the chat's local's pipeline. Passwords are never typed in the chat: you add them on the site's page.", false, 'setup', false],
        'edit_site' => ["Edit the chat's sites", 'Changes details such as the address, login name, folder or where a site gets its code from. It can mark a site as production but never unmark one, and never sees or sets passwords.', false, 'setup', false],
    ];

    public static function defaults(): array
    {
        $defaults = [
            'enabled' => '0',
            'provider' => 'openrouter',
            'base_url' => self::PRESETS['openrouter'][1],
            'api_key' => '',
            'model' => '',
            'max_steps' => '8',
            'max_messages' => '40',
        ];
        foreach (self::PERMISSIONS as $key => [, , $on]) {
            $defaults['perm_' . $key] = $on ? '1' : '0';
        }
        return $defaults;
    }

    /** Whether the assistant may do this (a key of PERMISSIONS). */
    public static function can(string $permission): bool
    {
        return self::settings()['perm_' . $permission] === '1';
    }

    /** False when .env says DEPLOY_TOOL_AI=false (or 0, off, no): then nothing about AI shows or runs. */
    public static function allowed(): bool
    {
        return !in_array(strtolower(trim((string) env('DEPLOY_TOOL_AI'))), ['false', '0', 'off', 'no'], true);
    }

    public static function enabled(): bool
    {
        return self::allowed() && self::settings()['enabled'] === '1';
    }

    public static function settings(): array
    {
        $stored = JsonStore::read('ai');
        // Before permissions had their own section, approving was one tick: allow_approve.
        if (isset($stored['allow_approve']) && !isset($stored['perm_approve'])) {
            $stored['perm_approve'] = $stored['allow_approve'];
        }
        return array_merge(self::defaults(), array_intersect_key($stored, self::defaults()));
    }

    /** 'openai' or 'anthropic': which kind of API the base URL speaks. */
    public static function apiStyle(array $settings): string
    {
        return self::PRESETS[$settings['provider']][2] ?? 'openai';
    }

    /**
     * Settings from the form's ai_* fields; a blank key keeps the saved one.
     *
     * @return array{0: array, 1: array<string, string>} the values and [field => error]
     */
    public static function fromInput(array $input): array
    {
        $saved = self::settings();
        $values = [];
        foreach (array_keys(self::defaults()) as $key) {
            $values[$key] = trim((string) ($input['ai_' . $key] ?? ''));
        }
        foreach (array_keys(self::defaults()) as $key) {
            if ($key === 'enabled' || str_starts_with($key, 'perm_')) {
                $values[$key] = $values[$key] === '1' ? '1' : '0';
            }
        }
        $values['base_url'] = rtrim($values['base_url'], '/');
        if ($values['api_key'] === '') {
            $values['api_key'] = $saved['api_key'];
        }

        $errors = [];
        if (!isset(self::PRESETS[$values['provider']])) {
            $errors['ai_provider'] = 'Pick a provider.';
            $values['provider'] = 'custom';
        }
        if (!preg_match('#^https?://[^\s/]+(/\S*)?$#i', $values['base_url'])) {
            $errors['ai_base_url'] = 'Enter the address of the API, starting with https:// (or http:// for one on this computer).';
        }
        if ($values['enabled'] === '1') {
            if ($values['model'] === '') {
                $errors['ai_model'] = 'Pick a model before switching the assistant on.';
            }
            if (self::PRESETS[$values['provider']][3] && $values['api_key'] === '') {
                $errors['ai_api_key'] = 'This provider needs an API key.';
            }
        }
        foreach (['max_steps' => [1, 20], 'max_messages' => [10, 200]] as $key => [$min, $max]) {
            if (!ctype_digit($values[$key]) || (int) $values[$key] < $min || (int) $values[$key] > $max) {
                $errors['ai_' . $key] = "Enter $min to $max.";
            }
        }
        return [$values, $errors];
    }

    public static function save(array $values): void
    {
        JsonStore::write('ai', array_intersect_key($values, self::defaults()));
    }
}

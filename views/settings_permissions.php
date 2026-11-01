<?php
/**
 * Settings → AI permissions: what the assistant may do beyond reading, one switch each
 * (part of the settings form).
 *
 * @var bool $allowed
 * @var array $ai Ai::settings(), or the posted values after a mistake
 */

use DeployTool\Ai;

$groups = [
    'safe' => ['Reading and preparing', "Nothing on a server changes. Reading (status, history, compare results, file changes) is always allowed."],
    'server' => ['Changes servers', 'Each changes files or versions on a site. Production sites stay on their own page wherever marked.'],
    'housekeeping' => ['Housekeeping', "Changes the tool's own records and settings for the chat's local."],
    'setup' => ['Locals and sites', "Adds and edits your locals and sites. It only edits the chat's own local and sites, and never sees or sets passwords."],
];
?>
<div class="card settings-card">
    <div class="settings-card-head">
        <h2>AI permissions</h2>
        <?php if ($allowed): ?>
            <?php $on = count(array_filter(array_keys(Ai::PERMISSIONS), static fn (string $key): bool => ($ai['perm_' . $key] ?? '0') === '1')); ?>
            <span class="pill"><?= $on ?> of <?= count(Ai::PERMISSIONS) ?> allowed</span>
        <?php endif ?>
    </div>
    <?php if (!$allowed): ?>
        <div class="ai-off"><p>The assistant is turned off in .env, so it can't do anything.</p></div>
    <?php else: ?>
        <p class="meta">What the assistant may do for you from the chat. It only ever works on the local picked for the chat and its sites, and everything it does is written in the run's log. Anything switched off, it tells you where to click instead.</p>
        <?php foreach ($groups as $group => [$title, $help]): ?>
            <section class="perm-group">
                <h3><?= e($title) ?></h3>
                <p class="meta"><?= e($help) ?></p>
                <ul class="perm-list">
                    <?php foreach (Ai::PERMISSIONS as $key => [$label, $what, , $permGroup, $notProduction]): ?>
                        <?php if ($permGroup !== $group) { continue; } ?>
                        <li>
                            <input type="hidden" name="ai_perm_<?= e($key) ?>" value="0">
                            <label class="perm-row">
                                <span class="perm-text">
                                    <strong><?= e($label) ?></strong>
                                    <?php if ($notProduction): ?><span class="pill">never on production</span><?php endif ?>
                                    <span class="perm-what"><?= e($what) ?></span>
                                </span>
                                <input type="checkbox" class="switch" name="ai_perm_<?= e($key) ?>" value="1" role="switch"<?= ($ai['perm_' . $key] ?? '0') === '1' ? ' checked' : '' ?>>
                            </label>
                        </li>
                    <?php endforeach ?>
                </ul>
            </section>
        <?php endforeach ?>
        <p class="meta perm-note">Never allowed from the chat: changing these settings, seeing or setting passwords, deleting anything (runs, sites or locals), abandoning a run, unmarking a production site, and approving, retrying, rolling back or recording a version on a production site.</p>
    <?php endif ?>
</div>

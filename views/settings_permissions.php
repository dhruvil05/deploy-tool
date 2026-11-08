<?php
/**
 * Settings → AI permissions: what the assistant may do beyond reading, Allow / Ask / Off
 * for each action (part of the settings form).
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
$modes = ['1' => ['allow', 'Allow'], 'ask' => ['ask', 'Ask'], '0' => ['off', 'Off']];
$counts = ['allow' => 0, 'ask' => 0, 'off' => 0];
foreach (array_keys(Ai::PERMISSIONS) as $key) {
    $counts[Ai::mode($key, $ai)]++;
}
?>
<div class="card settings-card perm-card">
    <div class="settings-card-head">
        <h2>AI permissions</h2>
        <?php if ($allowed): ?>
            <span class="perm-summary js-perm-summary" aria-live="polite">
                <span class="perm-count is-allow"><b class="js-perm-count" data-mode="allow"><?= $counts['allow'] ?></b> allow</span>
                <span class="perm-count is-ask"><b class="js-perm-count" data-mode="ask"><?= $counts['ask'] ?></b> ask</span>
                <span class="perm-count is-off"><b class="js-perm-count" data-mode="off"><?= $counts['off'] ?></b> off</span>
            </span>
        <?php endif ?>
    </div>
    <?php if (!$allowed): ?>
        <div class="ai-off"><p>The assistant is turned off in .env, so it can't do anything.</p></div>
    <?php else: ?>
        <p class="meta">What the assistant may do for you from the chat. It only ever works on the local picked for the chat and its sites, and everything it does is written in the run's log.</p>
        <ul class="perm-legend">
            <li><span class="perm-dot is-allow" aria-hidden="true"></span><strong>Allow</strong> it does it when you ask.</li>
            <li><span class="perm-dot is-ask" aria-hidden="true"></span><strong>Ask</strong> it shows <em>Allow / Deny</em> buttons in the chat first; nothing happens without your click.</li>
            <li><span class="perm-dot is-off" aria-hidden="true"></span><strong>Off</strong> it tells you where to click instead.</li>
        </ul>
        <?php foreach ($groups as $group => [$title, $help]): ?>
            <section class="perm-group">
                <div class="perm-group-head">
                    <h3><?= e($title) ?></h3>
                    <p class="meta"><?= e($help) ?></p>
                </div>
                <ul class="perm-list">
                    <?php foreach (Ai::PERMISSIONS as $key => [$label, $what, , $permGroup, $notProduction]): ?>
                        <?php if ($permGroup !== $group) { continue; } ?>
                        <?php $mode = Ai::mode($key, $ai); ?>
                        <li class="perm-row">
                            <span class="perm-text">
                                <strong><?= e($label) ?></strong>
                                <?php if ($notProduction): ?><span class="pill pill-warning">never on production</span><?php endif ?>
                                <span class="perm-what"><?= e($what) ?></span>
                            </span>
                            <span class="segmented perm-modes" role="radiogroup" aria-label="<?= e($label) ?>">
                                <?php foreach ($modes as $value => [$name, $text]): ?>
                                    <label class="is-<?= $name ?>"><input type="radio" name="ai_perm_<?= e($key) ?>" value="<?= $value ?>" data-mode="<?= $name ?>"<?= $mode === $name ? ' checked' : '' ?>><span><?= $text ?></span></label>
                                <?php endforeach ?>
                            </span>
                        </li>
                    <?php endforeach ?>
                </ul>
            </section>
        <?php endforeach ?>
        <div class="perm-note">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 8 0v4"></path></svg>
            <p><strong>Never allowed from the chat:</strong> changing these settings, seeing or setting passwords, deleting anything (runs, sites or locals), abandoning a run, unmarking a production site, and retrying, rolling back or recording a version on a production site. Approving a production deploy always needs you to type its site key on the chat's card.</p>
        </div>
    <?php endif ?>
</div>

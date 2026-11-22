<?php
/**
 * Settings → Speed-ups: optional extras in groups. Each row is one switch with a one-line
 * summary; "Details" shows the full text and what Off means. A feature's own settings (how
 * often, a webhook, a bot) open right under its switch while it is on.
 *
 * @var array $values
 * @var array $errors
 * @var callable $hasError
 * @var callable $error
 */

use DeployTool\Settings;
use DeployTool\View;

$isOn = static fn (string $key): bool => ($values[$key] ?? '1') === '1';
$total = count(Settings::FEATURES);
$onCount = count(array_filter(array_keys(Settings::FEATURES), $isOn));
$partials = ['values' => $values, 'hasError' => $hasError, 'error' => $error];

// Settings that belong to one feature, shown under its switch.
$webhookSaved = Settings::get('notify_webhook') !== '';
$extras = [
    'feature_bundle_upload' => static fn (): string => '
        <div class="field' . $hasError('bundle_min_files') . '">
            <label for="bundle_min_files">Use it from</label>
            <div class="input-unit"><input type="number" id="bundle_min_files" name="bundle_min_files" min="2" max="5000" value="' . e($values['bundle_min_files']) . '"><span>files in one deploy</span></div>
            ' . $error('bundle_min_files') . '
        </div>',
    'feature_server_watch' => static fn (): string => '
        <div class="field' . $hasError('watch_hours') . '">
            <label for="watch_hours">Check every</label>
            <div class="input-unit"><input type="number" id="watch_hours" name="watch_hours" min="1" max="48" value="' . e($values['watch_hours']) . '"><span>hours</span></div>
            ' . $error('watch_hours') . '
        </div>',
    'feature_uptime' => static fn (): string => '
        <div class="field' . $hasError('monitor_minutes') . '">
            <label for="monitor_minutes">Check every</label>
            <div class="input-unit"><input type="number" id="monitor_minutes" name="monitor_minutes" min="1" max="60" value="' . e($values['monitor_minutes']) . '"><span>minutes</span></div>
            ' . $error('monitor_minutes') . '
        </div>',
    'feature_notify' => static fn (): string => '
        <div class="field' . $hasError('notify_webhook') . '">
            <label for="notify_webhook">Chat webhook</label>
            <input type="password" id="notify_webhook" name="notify_webhook" value="" autocomplete="off" class="mono-input" placeholder="' . ($webhookSaved ? 'Saved. Blank keeps it.' : 'https://hooks.slack.com/services/…') . '">
            <p class="help">From Slack (Incoming Webhooks), Teams (Workflows → "Post to a channel when a webhook request is received") or Discord (Channel → Integrations → Webhooks). Kept encrypted like the other passwords.</p>
            ' . ($webhookSaved ? '<label class="checkbox"><input type="checkbox" name="notify_webhook_remove" value="1"> <span>Remove the saved webhook</span></label>' : '') . '
            ' . $error('notify_webhook') . '
        </div>',
    'feature_discord_bridge' => static fn (): string => View::partial('speedup_discord', $partials),
    'feature_slack_bridge' => static fn (): string => View::partial('speedup_slack', $partials),
];
// A short state next to a feature that needs more than its switch.
$needs = [
    'feature_notify' => $webhookSaved ? null : 'Needs a webhook',
    'feature_discord_bridge' => Settings::get('discord_bot_token') === '' ? 'Needs a bot' : null,
    'feature_slack_bridge' => Settings::get('slack_bot_token') === '' ? 'Needs an app' : null,
];
$offByDefault = ['feature_bundle_upload', 'feature_discord_bridge', 'feature_slack_bridge', 'feature_server_watch', 'feature_uptime'];
?>
<div class="sp-head card settings-card">
    <div class="sp-intro">
        <h2>Speed-ups</h2>
        <p class="meta">Optional extras. Each one switches on or off by itself; off, the tool works exactly as before. Changes count after <strong>Save settings</strong>.</p>
    </div>
    <div class="sp-tools">
        <p class="sp-count"><strong class="js-sp-on"><?= $onCount ?></strong> of <?= $total ?> on</p>
        <label class="sp-search">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
            <input type="search" class="js-sp-search" placeholder="Find a speed-up…" aria-label="Find a speed-up">
        </label>
        <div class="segmented sp-filter" role="radiogroup" aria-label="Show">
            <button type="button" class="js-sp-filter active" data-show="all" aria-pressed="true">All</button>
            <button type="button" class="js-sp-filter" data-show="on" aria-pressed="false">On</button>
            <button type="button" class="js-sp-filter" data-show="off" aria-pressed="false">Off</button>
        </div>
    </div>
</div>

<div class="sp-groups">
    <?php foreach (Settings::FEATURE_GROUPS as $group => [$title, $about, $keys]): ?>
        <?php $groupOn = count(array_filter($keys, $isOn)); ?>
        <section class="card sp-group js-sp-group">
            <header class="sp-group-head">
                <div>
                    <h3><?= e($title) ?></h3>
                    <p class="meta"><?= e($about) ?></p>
                </div>
                <span class="pill js-sp-group-count"><?= $groupOn ?>/<?= count($keys) ?></span>
            </header>
            <?php foreach ($keys as $key): ?>
                <?php
                [$label, $what, $off] = Settings::FEATURES[$key];
                $on = $isOn($key);
                $extra = $extras[$key] ?? null;
                $extraErrors = array_filter(array_keys($errors), static fn (string $f): bool => $f === $key || str_starts_with($f, str_replace(['feature_', '_bridge'], '', $key) . '_')
                    || ($key === 'feature_bundle_upload' && $f === 'bundle_min_files') || ($key === 'feature_server_watch' && $f === 'watch_hours')
                    || ($key === 'feature_uptime' && $f === 'monitor_minutes') || ($key === 'feature_notify' && $f === 'notify_webhook'));
                ?>
                <div class="sp-row js-sp-row<?= $on ? ' is-on' : '' ?><?= $extraErrors ? ' has-error' : '' ?>" data-search="<?= e(strtolower($label . ' ' . Settings::FEATURE_SHORT[$key] . ' ' . $what)) ?>">
                    <div class="sp-line">
                        <div class="sp-text">
                            <p class="sp-name">
                                <span><?= e($label) ?></span>
                                <?php if (in_array($key, $offByDefault, true)): ?><span class="badge badge-neutral js-sp-default"<?= $on ? ' hidden' : '' ?>>Off by default</span><?php endif ?>
                                <?php if ($on && ($needs[$key] ?? null) !== null): ?><span class="badge badge-warning"><?= e($needs[$key]) ?></span><?php endif ?>
                            </p>
                            <p class="sp-short"><?= e(Settings::FEATURE_SHORT[$key]) ?></p>
                            <button type="button" class="sp-more js-sp-more" aria-expanded="false">Details</button>
                        </div>
                        <input type="hidden" name="<?= e($key) ?>" value="0">
                        <input type="checkbox" class="switch js-sp-switch" name="<?= e($key) ?>" value="1" role="switch" aria-label="<?= e($label) ?>"<?= $on ? ' checked' : '' ?>>
                    </div>
                    <div class="sp-details" hidden>
                        <p><?= e($what) ?></p>
                        <p class="speedup-off"><?= e($off) ?></p>
                    </div>
                    <?php if ($extra !== null): ?>
                        <?php // Kept in the form when hidden, so a switched-off feature keeps its settings. ?>
                        <div class="sp-extra js-sp-extra" <?= $on || $extraErrors ? '' : 'hidden' ?>>
                            <?= $extra() ?>
                        </div>
                    <?php endif ?>
                </div>
            <?php endforeach ?>
            <?php if ($group === 'assistant'): ?>
                <p class="meta sp-note">Also for the assistant: a backup model it switches to when the main one times out, under <a href="index.php?r=settings&amp;tab=ai" class="js-settings-goto" data-tab="ai">AI assistant → Model</a>.</p>
            <?php endif ?>
        </section>
    <?php endforeach ?>
    <p class="meta sp-empty js-sp-empty" hidden>No speed-up matches. Clear the search or pick All.</p>
</div>

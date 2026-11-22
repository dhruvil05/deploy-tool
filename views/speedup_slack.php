<?php
/**
 * Settings → Speed-ups: the Slack app the assistant reads with, over Socket Mode (shown under its switch).
 *
 * @var array $values
 * @var callable $hasError
 * @var callable $error
 */
?>
<?php $slackBotSaved = \DeployTool\Settings::get('slack_bot_token') !== ''; $slackAppSaved = \DeployTool\Settings::get('slack_app_token') !== ''; $slack = \DeployTool\Slack\Bridge::status(); ?>
<div class="speedup-setup">
    <?= $error('feature_slack_bridge') ?>
    <p class="help">Create an app at <a href="https://api.slack.com/apps" target="_blank" rel="noopener noreferrer">api.slack.com/apps</a> with Socket Mode on, the bot scopes and events the README lists, install it, and <code>/invite</code> the bot to the channel. Test here, then switch the speed-up on and Save. The README has every step.</p>
    <div class="field<?= $hasError('slack_bot_token') ?>">
        <label for="slack_bot_token">Bot token</label>
        <input type="password" id="slack_bot_token" name="slack_bot_token" value="" autocomplete="off" class="mono-input" placeholder="<?= $slackBotSaved ? 'Saved. Blank keeps it.' : 'xoxb-… (OAuth & Permissions)' ?>">
        <?php if ($slackBotSaved): ?>
            <label class="checkbox"><input type="checkbox" name="slack_bot_token_remove" value="1"> <span>Remove the saved token</span></label>
        <?php endif ?>
        <?= $error('slack_bot_token') ?>
    </div>
    <div class="field<?= $hasError('slack_app_token') ?>">
        <label for="slack_app_token">App-level token</label>
        <input type="password" id="slack_app_token" name="slack_app_token" value="" autocomplete="off" class="mono-input" placeholder="<?= $slackAppSaved ? 'Saved. Blank keeps it.' : 'xapp-… (Basic Information → App-Level Tokens)' ?>">
        <p class="help">For the connection the tool opens to Slack. Made with the <code>connections:write</code> scope when you switch on Socket Mode.</p>
        <?php if ($slackAppSaved): ?>
            <label class="checkbox"><input type="checkbox" name="slack_app_token_remove" value="1"> <span>Remove the saved token</span></label>
        <?php endif ?>
        <?= $error('slack_app_token') ?>
    </div>
    <div class="field<?= $hasError('slack_channel_id') ?>">
        <label for="slack_channel_id">Channel ID</label>
        <input type="text" id="slack_channel_id" name="slack_channel_id" value="<?= e($values['slack_channel_id']) ?>" class="mono-input" placeholder="C0123ABCD">
        <p class="help">The one channel the bot reads: click the channel's name → at the bottom of the window.</p>
        <?= $error('slack_channel_id') ?>
    </div>
    <div class="field<?= $hasError('slack_user_ids') ?>">
        <label for="slack_user_ids">Your Slack member ID</label>
        <input type="text" id="slack_user_ids" name="slack_user_ids" value="<?= e($values['slack_user_ids']) ?>" class="mono-input" placeholder="U0123ABCD">
        <p class="help">Only these people are answered; anyone else in the channel is ignored. Your profile → ⋯ → Copy member ID. Several: separate with commas.</p>
        <?= $error('slack_user_ids') ?>
    </div>
    <div class="field">
        <span class="field-label">What it may do from Slack</span>
        <div class="discord-actions">
            <?php foreach (\DeployTool\Settings::DISCORD_ACTIONS as $choice => [$label, $about]): ?>
                <label class="radio-card">
                    <input type="radio" name="slack_actions" value="<?= e($choice) ?>"<?= ($values['slack_actions'] ?? 'ask') === $choice ? ' checked' : '' ?>>
                    <span><strong><?= e(str_replace('Discord', 'Slack', $label)) ?></strong><span class="meta"><?= e(str_replace('Discord', 'Slack', $about)) ?></span></span>
                </label>
            <?php endforeach ?>
        </div>
        <p class="help">Within what <a href="index.php?r=settings&amp;tab=permissions" class="js-settings-goto" data-tab="permissions">AI permissions</a> allow. Approving a production deploy is never done from Slack: it needs the site key typed in the tool.</p>
    </div>
    <div class="discord-test">
        <button type="button" class="small js-remote-test" data-route="slack-test" data-fields="token:slack_bot_token app_token:slack_app_token channel:slack_channel_id">Test connection</button>
        <span class="meta js-remote-result" aria-live="polite"></span>
    </div>
    <p class="meta discord-status">
        <?php if (\DeployTool\Settings::on('feature_slack_bridge')): ?>
            <?php if ($slack['running'] && $slack['connected']): ?>
                <span class="badge badge-success">Connected</span>
                <?= $slack['bot'] !== null ? 'as ' . e($slack['bot']) . ' · ' : '' ?>last heard from Slack <?= e(ago($slack['checked_at'])) ?> · <?= $slack['threads'] ?> chat thread(s)
            <?php elseif ($slack['running']): ?>
                <span class="badge badge-warning">Connecting</span> Reload in a few seconds.
            <?php else: ?>
                <span class="badge badge-warning">Starting</span> It starts in the background within a few seconds of opening a page; reload to check.
            <?php endif ?>
            <?php if ($slack['error'] !== null): ?>
                <br><span class="error-text"><?= e($slack['error']) ?></span>
            <?php endif ?>
        <?php else: ?>
            Off. Slack is never read.
        <?php endif ?>
    </p>
</div>

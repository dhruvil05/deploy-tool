<?php
/**
 * Settings → Speed-ups: the Discord bot the assistant reads with (shown under its switch).
 *
 * @var array $values
 * @var callable $hasError
 * @var callable $error
 */
?>
<?php $discordSaved = \DeployTool\Settings::get('discord_bot_token') !== ''; $discord = \DeployTool\Discord\Bridge::status(); ?>
<div class="speedup-setup">
    <?= $error('feature_discord_bridge') ?>
    <p class="help">Create a bot in the <a href="https://discord.com/developers/applications" target="_blank" rel="noopener noreferrer">Discord Developer Portal</a> (Bot → Reset Token, and switch on <strong>Message Content Intent</strong>), test it here, add it to your server with the link the test shows, then switch the speed-up on and Save. The README has every step.</p>
    <div class="field<?= $hasError('discord_bot_token') ?>">
        <label for="discord_bot_token">Bot token</label>
        <input type="password" id="discord_bot_token" name="discord_bot_token" value="" autocomplete="off" class="mono-input" placeholder="<?= $discordSaved ? 'Saved. Blank keeps it.' : 'From the bot\'s page: Reset Token' ?>">
        <?php if ($discordSaved): ?>
            <label class="checkbox"><input type="checkbox" name="discord_bot_token_remove" value="1"> <span>Remove the saved token</span></label>
        <?php endif ?>
        <?= $error('discord_bot_token') ?>
    </div>
    <div class="field<?= $hasError('discord_channel_id') ?>">
        <label for="discord_channel_id">Channel ID</label>
        <input type="text" id="discord_channel_id" name="discord_channel_id" value="<?= e($values['discord_channel_id']) ?>" inputmode="numeric" class="mono-input" placeholder="1234567890123456789">
        <p class="help">The one channel the bot reads. In Discord: Settings → Advanced → Developer Mode on, then right-click the channel → Copy Channel ID.</p>
        <?= $error('discord_channel_id') ?>
    </div>
    <div class="field<?= $hasError('discord_user_ids') ?>">
        <label for="discord_user_ids">Your Discord user ID</label>
        <input type="text" id="discord_user_ids" name="discord_user_ids" value="<?= e($values['discord_user_ids']) ?>" class="mono-input" placeholder="1234567890123456789">
        <p class="help">Only these people are answered; anyone else in the channel is ignored. Right-click your name → Copy User ID. Several: separate with commas.</p>
        <?= $error('discord_user_ids') ?>
    </div>
    <div class="field">
        <span class="field-label">What it may do from Discord</span>
        <div class="discord-actions">
            <?php foreach (\DeployTool\Settings::DISCORD_ACTIONS as $choice => [$label, $about]): ?>
                <label class="radio-card">
                    <input type="radio" name="discord_actions" value="<?= e($choice) ?>"<?= ($values['discord_actions'] ?? 'ask') === $choice ? ' checked' : '' ?>>
                    <span><strong><?= e($label) ?></strong><span class="meta"><?= e($about) ?></span></span>
                </label>
            <?php endforeach ?>
        </div>
        <p class="help">Within what <a href="index.php?r=settings&amp;tab=permissions" class="js-settings-goto" data-tab="permissions">AI permissions</a> allow. Approving a production deploy is never done from Discord: it needs the site key typed in the tool.</p>
    </div>
    <div class="discord-test">
        <button type="button" class="small js-remote-test" data-route="discord-test" data-fields="token:discord_bot_token channel:discord_channel_id">Test connection</button>
        <span class="meta js-remote-result" aria-live="polite"></span>
    </div>
    <p class="meta discord-status">
        <?php if (\DeployTool\Settings::on('feature_discord_bridge')): ?>
            <?php if ($discord['running']): ?>
                <span class="badge badge-success">Running</span>
                <?= $discord['bot'] !== null ? 'as ' . e($discord['bot']) . ' · ' : '' ?>last read Discord <?= e(ago($discord['checked_at'])) ?> · <?= $discord['threads'] ?> chat thread(s)
            <?php else: ?>
                <span class="badge badge-warning">Starting</span> It starts in the background within a few seconds of opening a page; reload to check.
            <?php endif ?>
            <?php if ($discord['error'] !== null): ?>
                <br><span class="error-text"><?= e($discord['error']) ?></span>
            <?php endif ?>
        <?php else: ?>
            Off. Discord is never read.
        <?php endif ?>
    </p>
</div>

<?php
/**
 * Sign-in page, shown when a password is set in Settings → Security.
 *
 * @var ?string $error
 */

use DeployTool\Http;
?>
<div class="login-wrap">
    <form method="post" action="index.php?r=login" class="card login-card">
        <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
        <span class="login-icon" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 8 0v4"></path></svg>
        </span>
        <h1>Sign in</h1>
        <p class="meta">This tool can deploy to production, so it asks for its password.</p>
        <?php if ($error !== null): ?>
            <div class="notice notice-danger"><?= e($error) ?></div>
        <?php endif ?>
        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required autofocus>
        </div>
        <button type="submit" class="primary login-submit">Sign in</button>
        <p class="help">Forgot it? Delete <code>auth.json</code> in the tool's data folder to remove the password.</p>
    </form>
</div>

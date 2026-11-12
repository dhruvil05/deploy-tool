<?php
/**
 * Review → "Explain this deploy" (speed-up, AI): the button, or the saved explanation.
 *
 * @var array $run
 */

use DeployTool\Ai;
use DeployTool\Ai\Markdown;

$explanation = $run['explanation'] ?? null;
$provider = Ai::PRESETS[Ai::settings()['provider']][0] ?? 'your AI provider';
?>
<section class="card explain-card js-explain" data-run="<?= e($run['id']) ?>">
    <div class="explain-head">
        <h3>Explain this deploy</h3>
        <button type="button" class="small<?= $explanation === null ? ' primary' : '' ?> js-explain-run" data-busy="Reading the changes…"><?= $explanation === null ? 'Explain' : 'Explain again' ?></button>
    </div>
    <?php if ($explanation === null): ?>
        <p class="meta">A short summary from the AI assistant: what changes, risks, database steps and what to check afterwards. Sends the file list and the diffs of the most changed files to <?= e($provider) ?>.</p>
    <?php else: ?>
        <div class="chat-text explain-text"><?= Markdown::html($explanation['text']) ?></div>
        <p class="meta">Written by <code><?= e((string) ($explanation['model'] ?? '?')) ?></code> <?= e(ago((int) $explanation['at'])) ?>. Check it against the files: it can be wrong.</p>
    <?php endif ?>
    <p class="error-text js-explain-error" hidden></p>
</section>

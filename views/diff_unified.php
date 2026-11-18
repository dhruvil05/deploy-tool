<?php
/**
 * A diff as one column with old and new line numbers.
 *
 * @var list<array> $rows DiffView::parse() rows (their html is already escaped)
 */
?>
<div class="diff-wrap">
    <table class="diff-table">
        <colgroup><col class="col-ln"><col class="col-ln"><col></colgroup>
        <?php foreach ($rows as $row): ?>
            <?php if ($row['type'] === 'hunk' || $row['type'] === 'note'): ?>
                <tr class="hunk"><td colspan="3"><?= $row['html'] !== '' ? $row['html'] : '…' ?></td></tr>
            <?php else: ?>
                <?php $type = $row['type'] === 'ctx' ? '' : ' ' . $row['type']; ?>
                <tr>
                    <td class="ln<?= $type ?>"><?= $row['old'] ?? '' ?></td>
                    <td class="ln<?= $type ?>"><?= $row['new'] ?? '' ?></td>
                    <td class="code<?= $type ?>"><span class="sign"><?= ['add' => '+', 'del' => '−'][$row['type']] ?? ' ' ?></span><?= $row['html'] ?><?= !empty($row['eol']) ? ' <span class="eol-tag">line ending</span>' : '' ?></td>
                </tr>
            <?php endif ?>
        <?php endforeach ?>
    </table>
</div>

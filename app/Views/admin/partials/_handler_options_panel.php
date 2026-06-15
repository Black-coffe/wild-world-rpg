<?php
/**
 * Phase B5 (ADR-023) — info-панель «зарегистрированные handler'ы» для admin-форм
 * event/task/world_object. Презентация на admin-ui (ADR-128); логика без изменений.
 *
 * @var list<array{key: string, displayName: string, description: string}> $options
 * @var string $title
 * @var string $hint
 * @var string $emoji
 */
?>

<?php if ($options !== []): ?>
<div class="aui-alert aui-alert--info" style="margin-bottom:var(--sp-4)"><i class="ri-information-line"></i><div style="width:100%">
    <div class="aui-alert__title"><?= esc($emoji !== '' ? $emoji : 'ℹ️') ?> <?= esc($title) ?> (<?= count($options) ?>)</div>
    <?php if ($hint !== ''): ?><div class="aui-hint" style="margin-bottom:var(--sp-2)"><?= esc($hint) ?></div><?php endif; ?>
    <table class="aui-table">
        <tbody>
        <?php foreach ($options as $opt): ?>
            <tr>
                <td style="width:22%"><code class="aui-mono"><?= esc($opt['key']) ?></code></td>
                <td class="strong" style="width:28%"><?= esc($opt['displayName']) ?></td>
                <td class="aui-muted"><?= esc($opt['description']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<?php endif; ?>

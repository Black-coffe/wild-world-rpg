<?php
/**
 * Phase B5 (ADR-023, 2026-05-14) — info-панель «зарегистрированные handler'ы»
 * для admin-форм event/task/world_object.
 *
 * Источник опций — `App\Services\Handlers\HandlerRegistry::optionsFor($interface)`.
 * Контроллер передаёт уже compact-список (key/displayName/description).
 *
 * @var list<array{key: string, displayName: string, description: string}> $options
 * @var string $title
 * @var string $hint   Подсказка под заголовком (пустая — не рендерится).
 * @var string $emoji  Emoji для шапки (e.g. 🎲 / 🛠️ / 🌐). Пустой — fallback ℹ️.
 */
?>

<?php if ($options !== []): ?>
<div class="alert alert-info" role="alert">
    <strong><?= esc($emoji !== '' ? $emoji : 'ℹ️') ?> <?= esc($title) ?> (<?= count($options) ?>):</strong>
    <?php if ($hint !== ''): ?>
        <br><small class="text-muted"><?= esc($hint) ?></small>
    <?php endif; ?>
    <table class="table table-sm table-borderless mt-2 mb-0">
        <tbody>
        <?php foreach ($options as $opt): ?>
            <tr>
                <td class="ps-0 align-top admin-handler-col-key">
                    <code><?= esc($opt['key']) ?></code>
                </td>
                <td class="align-top admin-handler-col-name">
                    <strong><?= esc($opt['displayName']) ?></strong>
                </td>
                <td class="text-muted align-top">
                    <?= esc($opt['description']) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

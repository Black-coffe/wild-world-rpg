<?php
/**
 * Унифицированный form-partial для создания и редактирования совета `/tips`.
 * Phase D, 2026-05-13. См. `inbox/2026-05-13-admin-refactor-roadmap.md` (C1).
 *
 * @param string                    $mode 'create' | 'edit'
 * @param array<string, mixed>|null $tip  Запись из БД (только при mode='edit')
 */

$isEdit = ($mode ?? 'create') === 'edit';
$tip    = $tip ?? null;

$formAction  = $isEdit
    ? site_url('admin/tips/update/' . ($tip['id'] ?? 0))
    : site_url('admin/tips/store');
$submitLabel = $isEdit ? 'Обновить совет' : 'Создать совет';

$renderOption = static function (string $value, string $label, ?string $current): string {
    $selected = $current !== null && $current === $value ? ' selected' : '';
    return sprintf('<option value="%s"%s>%s</option>', esc($value), $selected, esc($label));
};

$currentType = $isEdit ? (string) ($tip['tip_type'] ?? '') : null;
?>

<div class="card">
    <div class="card-body">
        <?php if (session()->getFlashdata('errors')): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach (session()->getFlashdata('errors') as $error): ?>
                    <?= esc($error) ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form action="<?= $formAction ?>" method="post">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="title_ru" class="form-label">Название совета (Русский)</label>
                <input type="text" class="form-control" id="title_ru" name="title_ru"
                       value="<?= $isEdit ? esc(old('title_ru', $tip['title_ru'] ?? '')) : esc(old('title_ru')) ?>" required>
            </div>
            <div class="mb-3">
                <label for="title_en" class="form-label">Название совета (Английский)</label>
                <input type="text" class="form-control" id="title_en" name="title_en"
                       value="<?= $isEdit ? esc(old('title_en', $tip['title_en'] ?? '')) : esc(old('title_en')) ?>" required>
            </div>
            <div class="mb-3">
                <label for="tip_type" class="form-label">Тип совета</label>
                <select class="form-select" id="tip_type" name="tip_type" required>
                    <?= $renderOption('биомы',      'Биомы',       $currentType) ?>
                    <?= $renderOption('ресурсы',    'Ресурсы',     $currentType) ?>
                    <?= $renderOption('крафт',      'Крафт',       $currentType) ?>
                    <?= $renderOption('персонаж',   'Персонаж',    $currentType) ?>
                    <?= $renderOption('события',    'События',     $currentType) ?>
                    <?= $renderOption('NPC',        'NPC',         $currentType) ?>
                    <?= $renderOption('общие',      'Общие',       $currentType) ?>
                    <?= $renderOption('земледелие', 'Земледелие',  $currentType) ?>
                    <?= $renderOption('еда',        'Еда',         $currentType) ?>
                    <?= $renderOption('квесты',     'Квесты',      $currentType) ?>
                    <?= $renderOption('фракции',    'Фракции',     $currentType) ?>
                    <?= $renderOption('бой',        'Бой',         $currentType) ?>
                    <?= $renderOption('эндгейм',    'Эндгейм',     $currentType) ?>
                    <?= $renderOption('настройки',  'Настройки',   $currentType) ?>
                </select>
            </div>
            <div class="mb-3">
                <label for="content" class="form-label">Содержимое совета</label>
                <textarea class="form-control" id="content" name="content" rows="<?= $isEdit ? 4 : 6 ?>"><?= $isEdit ? esc(old('content', $tip['content'] ?? '')) : esc(old('content')) ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary"><?= esc($submitLabel) ?></button>
        </form>
    </div>
</div>

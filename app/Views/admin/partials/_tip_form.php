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

<div class="aui-card"><div class="aui-card__body">
    <?php if (session()->getFlashdata('errors')): ?>
        <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div>
            <?php foreach (session()->getFlashdata('errors') as $error): ?><?= esc($error) ?><br><?php endforeach; ?>
        </div></div>
    <?php endif; ?>

    <form action="<?= $formAction ?>" method="post">
        <?= csrf_field() ?>
        <div class="aui-field">
            <label for="title_ru" class="aui-label">Название совета (Русский) <span class="req">*</span></label>
            <input type="text" class="aui-input" id="title_ru" name="title_ru"
                   value="<?= $isEdit ? esc(old('title_ru', $tip['title_ru'] ?? '')) : esc(old('title_ru')) ?>" required>
        </div>
        <div class="aui-field">
            <label for="title_en" class="aui-label">Название совета (Английский) <span class="req">*</span></label>
            <input type="text" class="aui-input" id="title_en" name="title_en"
                   value="<?= $isEdit ? esc(old('title_en', $tip['title_en'] ?? '')) : esc(old('title_en')) ?>" required>
        </div>
        <div class="aui-field">
            <label for="tip_type" class="aui-label">Тип совета <span class="req">*</span></label>
            <select class="aui-select" id="tip_type" name="tip_type" required>
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
        <div class="aui-field">
            <label for="content" class="aui-label">Содержимое совета</label>
            <textarea class="aui-textarea" id="content" name="content" rows="<?= $isEdit ? 4 : 6 ?>"><?= $isEdit ? esc(old('content', $tip['content'] ?? '')) : esc(old('content')) ?></textarea>
        </div>

        <div class="aui-form-actions">
            <button type="submit" class="aui-btn aui-btn--primary"><i class="ri-save-line"></i> <?= esc($submitLabel) ?></button>
        </div>
    </form>
</div></div>

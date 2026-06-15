<?php
/**
 * Унифицированный form-partial для создания и редактирования ресурса.
 * Phase D, 2026-05-13. Презентация переведена на admin-ui (ADR-128); вся PHP-логика
 * (mode/isEdit, multi-select state, renderOption, old()) сохранена без изменений.
 *
 * @param string                                $mode      'create' | 'edit'
 * @param array<string, mixed>|null             $resource  Запись из БД (только при mode='edit')
 * @param array<int, array<string, mixed>>      $biomes    Список биомов для multi-select
 */

$isEdit   = ($mode ?? 'create') === 'edit';
$resource = $resource ?? null;

$formAction  = $isEdit
    ? site_url('admin/resources/update/' . ($resource['id'] ?? 0))
    : site_url('admin/resources/store');
$submitLabel = $isEdit ? 'Сохранить изменения' : 'Создать ресурс';

$selectedTypes = $isEdit && $resource !== null && isset($resource['type'])
    ? array_filter(array_map('trim', explode(',', (string) $resource['type'])))
    : (is_array(old('type')) ? old('type') : []);

$selectedBiomes = $isEdit && $resource !== null && isset($resource['biome_id'])
    ? array_map('intval', array_filter(array_map('trim', explode(',', (string) $resource['biome_id']))))
    : array_map('intval', is_array(old('biome_id')) ? old('biome_id') : []);

$renderOption = static function (string $value, string $label, ?string $current): string {
    $selected = $current !== null && $current === $value ? ' selected' : '';
    return sprintf('<option value="%s"%s>%s</option>', esc($value), $selected, esc($label));
};

$currentIsTradeable = $isEdit && $resource !== null
    ? (string) ((int) ($resource['is_tradeable'] ?? 0))
    : null;
?>

<div class="aui-card"><div class="aui-card__body">
    <?php if (session()->getFlashdata('errors')): ?>
        <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div>
            <?php foreach (session()->getFlashdata('errors') as $error): ?><?= esc($error) ?><br><?php endforeach; ?>
        </div></div>
    <?php endif; ?>

    <div class="aui-alert aui-alert--info" style="margin-bottom:var(--sp-5)"><i class="ri-information-line"></i><div>
        <div class="aui-alert__title">Как считаются поля</div>
        Редкость 1–10 (10 — частый, 1 — редкий). Цена = <code>11 − редкость</code>. Для торговца = <code>1000 × редкость</code>. Требуемый уровень: 8–10 → 1; 5–7 → 2; 3–4 → 3; 2 → 5; 1 → 10.
    </div></div>

    <form action="<?= $formAction ?>" method="post">
        <?= csrf_field() ?>

        <div class="aui-formgrid">
            <div class="aui-field"><label class="aui-label" for="name">Название <span class="req">*</span></label>
                <input class="aui-input" id="name" name="name" value="<?= $isEdit ? esc(old('name', $resource['name'] ?? '')) : esc(old('name')) ?>" required></div>
            <div class="aui-field"><label class="aui-label" for="name_en">Название (English) <span class="req">*</span></label>
                <input class="aui-input" id="name_en" name="name_en" value="<?= $isEdit ? esc(old('name_en', $resource['name_en'] ?? '')) : esc(old('name_en')) ?>" required></div>
            <div class="aui-field"><label class="aui-label" for="keyword">Кейворд</label>
                <input class="aui-input" id="keyword" name="keyword" value="<?= $isEdit ? esc(old('keyword', $resource['keyword'] ?? '')) : esc(old('keyword')) ?>"></div>
            <div class="aui-field"><label class="aui-label" for="icon_text">Иконка (эмоджи)</label>
                <input class="aui-input" id="icon_text" name="icon_text" value="<?= $isEdit ? esc(old('icon_text', $resource['icon_text'] ?? '')) : esc(old('icon_text')) ?>"></div>
        </div>

        <div class="aui-field">
            <label class="aui-label">Тип</label>
            <div class="aui-checkrow">
                <?php
                $typeOptions = ['crafting' => 'Крафтовые', 'food' => 'Еда', 'water' => 'Вода', 'material' => 'Материалы'];
                foreach ($typeOptions as $val => $label): ?>
                    <label class="aui-check">
                        <input type="checkbox" id="type_<?= esc($val) ?>" name="type[]" value="<?= esc($val) ?>" <?= in_array($val, $selectedTypes, true) ? 'checked' : '' ?>>
                        <span class="aui-check__box"></span> <?= esc($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="aui-formgrid">
            <div class="aui-field"><label class="aui-label" for="biome_id">Принадлежность к биому</label>
                <select class="aui-select" id="biome_id" name="biome_id[]" multiple>
                    <option value="">Не привязан к биому</option>
                    <?php foreach ($biomes as $biome): ?>
                        <option value="<?= esc($biome['id']) ?>" <?= in_array((int) $biome['id'], $selectedBiomes, true) ? 'selected' : '' ?>><?= esc($biome['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="aui-hint">Ctrl/Cmd для нескольких.</div></div>
            <div class="aui-field"><label class="aui-label" for="price">Цена <span class="req">*</span></label>
                <input type="number" class="aui-input" id="price" name="price" value="<?= $isEdit ? esc(old('price', $resource['price'] ?? '')) : esc(old('price')) ?>" required></div>
            <div class="aui-field"><label class="aui-label" for="rarity">Редкость <span class="req">*</span></label>
                <input type="number" class="aui-input" id="rarity" name="rarity" min="0" max="10" value="<?= $isEdit ? esc(old('rarity', $resource['rarity'] ?? '')) : esc(old('rarity')) ?>" required></div>
            <div class="aui-field"><label class="aui-label" for="initial_quantity">Для торговца <span class="req">*</span></label>
                <input type="number" class="aui-input" id="initial_quantity" name="initial_quantity" min="1" max="100000000" value="<?= $isEdit ? esc(old('initial_quantity', $resource['initial_quantity'] ?? '')) : esc(old('initial_quantity')) ?>" required></div>
            <div class="aui-field"><label class="aui-label" for="level_required">Требуемый уровень <span class="req">*</span></label>
                <input type="number" class="aui-input" id="level_required" name="level_required" value="<?= $isEdit ? esc(old('level_required', $resource['level_required'] ?? '')) : esc(old('level_required')) ?>" required></div>
            <div class="aui-field"><label class="aui-label" for="is_tradeable">Торгуемый <span class="req">*</span></label>
                <select class="aui-select" id="is_tradeable" name="is_tradeable" required>
                    <?= $renderOption('1', 'Да', $currentIsTradeable) ?>
                    <?= $renderOption('0', 'Нет', $currentIsTradeable) ?>
                </select></div>
        </div>

        <div class="aui-field">
            <label class="aui-label" for="description">Описание</label>
            <textarea class="aui-textarea" id="description" name="description" rows="3"><?= $isEdit ? esc(old('description', $resource['description'] ?? '')) : esc(old('description')) ?></textarea>
        </div>

        <div class="aui-form-actions">
            <button type="submit" class="aui-btn aui-btn--primary"><i class="ri-save-line"></i> <?= esc($submitLabel) ?></button>
            <a class="aui-btn aui-btn--ghost" href="<?= site_url('admin/resources') ?>">Отмена</a>
        </div>
    </form>
</div></div>

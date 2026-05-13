<?php
/**
 * Унифицированный form-partial для создания и редактирования ресурса.
 * Phase D, 2026-05-13. См. `inbox/2026-05-13-admin-refactor-roadmap.md` (C1).
 *
 * Resource хранит multi-select поля как **CSV-строки** в БД
 * (`resources.biome_id`, `resources.type`) — формат legacy F3. Phase C tail
 * нормализует это в pivot-таблицы.
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

// Multi-select state:
//   edit  → CSV string из БД → array через explode
//   create → массив из old() (или [] на свежей форме)
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

<div class="card">
    <div class="card-body">
        <?php if (session()->getFlashdata('errors')): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach (session()->getFlashdata('errors') as $error): ?>
                    <?= esc($error) ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            <strong>Важно — </strong> Редкость ресурса, от 1 до 10 (10 — наиболее частый, 1 — наиболее редкий).<br>
            Поле <strong>цена</strong> формируется так: <code>11 − редкость = цена</code>.<br>
            Поле <strong>Для торговца</strong>: <code>1000 × редкость</code>.<br>
            Поле <strong>Требуемый уровень</strong>: редкость 8–10 → 1; 5–7 → 2; 3–4 → 3; 2 → 5; 1 → 10.
        </div>

        <form action="<?= $formAction ?>" method="post">
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="mb-3 col-md-3">
                    <label for="name" class="form-label">Название</label>
                    <input type="text" class="form-control" id="name" name="name"
                           value="<?= $isEdit ? esc(old('name', $resource['name'] ?? '')) : esc(old('name')) ?>" required>
                </div>
                <div class="mb-3 col-md-3">
                    <label for="name_en" class="form-label">Название (English)</label>
                    <input type="text" class="form-control" id="name_en" name="name_en"
                           value="<?= $isEdit ? esc(old('name_en', $resource['name_en'] ?? '')) : esc(old('name_en')) ?>" required>
                </div>
                <div class="mb-3 col-md-3">
                    <label for="keyword" class="form-label">Кейворд</label>
                    <input type="text" class="form-control" id="keyword" name="keyword"
                           value="<?= $isEdit ? esc(old('keyword', $resource['keyword'] ?? '')) : esc(old('keyword')) ?>">
                </div>
                <div class="mb-3 col-md-3">
                    <label for="icon_text" class="form-label">Иконка (эмоджи)</label>
                    <input type="text" class="form-control" id="icon_text" name="icon_text"
                           value="<?= $isEdit ? esc(old('icon_text', $resource['icon_text'] ?? '')) : esc(old('icon_text')) ?>">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Тип</label>
                <div>
                    <?php
                    $typeOptions = [
                        'crafting' => 'Крафтовые',
                        'food'     => 'Еда',
                        'water'    => 'Вода',
                        'material' => 'Материалы',
                    ];
                    foreach ($typeOptions as $val => $label): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox"
                                   id="type_<?= esc($val) ?>" name="type[]" value="<?= esc($val) ?>"
                                   <?= in_array($val, $selectedTypes, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="type_<?= esc($val) ?>"><?= esc($label) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row g-2">
                <div class="mb-3 col-md-3">
                    <label for="biome_id" class="form-label">Принадлежность к биому</label>
                    <select class="form-select" id="biome_id" name="biome_id[]" multiple>
                        <option value="">Не привязан к биому</option>
                        <?php foreach ($biomes as $biome): ?>
                            <option value="<?= esc($biome['id']) ?>"
                                <?= in_array((int) $biome['id'], $selectedBiomes, true) ? 'selected' : '' ?>>
                                <?= esc($biome['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Ctrl/Cmd для нескольких.</small>
                </div>
                <div class="mb-3 col-md-2">
                    <label for="price" class="form-label">Цена</label>
                    <input type="number" class="form-control" id="price" name="price"
                           value="<?= $isEdit ? esc(old('price', $resource['price'] ?? '')) : esc(old('price')) ?>" required>
                </div>
                <div class="mb-3 col-md-2">
                    <label for="rarity" class="form-label">Редкость</label>
                    <input type="number" class="form-control" id="rarity" name="rarity" min="0" max="10"
                           value="<?= $isEdit ? esc(old('rarity', $resource['rarity'] ?? '')) : esc(old('rarity')) ?>" required>
                </div>
                <div class="mb-3 col-md-2">
                    <label for="initial_quantity" class="form-label">Для торговца</label>
                    <input type="number" class="form-control" id="initial_quantity" name="initial_quantity" min="1" max="100000000"
                           value="<?= $isEdit ? esc(old('initial_quantity', $resource['initial_quantity'] ?? '')) : esc(old('initial_quantity')) ?>" required>
                </div>
                <div class="mb-3 col-md-2">
                    <label for="level_required" class="form-label">Требуемый уровень</label>
                    <input type="number" class="form-control" id="level_required" name="level_required"
                           value="<?= $isEdit ? esc(old('level_required', $resource['level_required'] ?? '')) : esc(old('level_required')) ?>" required>
                </div>
                <div class="mb-3 col-md-1">
                    <label for="is_tradeable" class="form-label">Торг.</label>
                    <select class="form-select" id="is_tradeable" name="is_tradeable" required>
                        <?= $renderOption('1', 'Да',  $currentIsTradeable) ?>
                        <?= $renderOption('0', 'Нет', $currentIsTradeable) ?>
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">Описание</label>
                <textarea class="form-control" id="description" name="description" rows="3"><?= $isEdit ? esc(old('description', $resource['description'] ?? '')) : esc(old('description')) ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary"><?= esc($submitLabel) ?></button>
        </form>
    </div>
</div>

<?php
/**
 * Унифицированный form-partial для создания и редактирования world-object.
 * Phase D, 2026-05-13. См. `inbox/2026-05-13-admin-refactor-roadmap.md` (C1).
 *
 * WorldObject хранит **JSON-поля** в БД (TEXT-колонки):
 *   - `biome_id`         — JSON array, e.g. `[1,3]`
 *   - `discovery_tools`  — JSON object, e.g. `["Лом","Фонарь"]`
 *   - `contents`         — JSON, e.g. `{"resources":{"Галька":350}}`
 *
 * Phase C tail нормализует эти JSON-поля в pivot-таблицы. Сейчас (Phase D) —
 * только форма унифицирована, не схема. Defensive decode: JSON-first,
 * fallback на CSV (legacy data перед F1.4).
 *
 * **Bug-fix:** legacy `edit` view использовал `explode(',', $object['biome_id'])`
 * для JSON-строки `[1,3]` → давало мусор `['[1', '3]']`. Теперь json_decode
 * с CSV fallback.
 *
 * @param string                                $mode    'create' | 'edit'
 * @param array<string, mixed>|null             $object  Запись из БД (только при mode='edit')
 * @param array<int, array<string, mixed>>      $biomes  Список биомов
 */

$isEdit = ($mode ?? 'create') === 'edit';
$object = $object ?? null;

$formAction  = $isEdit
    ? site_url('admin/world-objects/update/' . ($object['id'] ?? 0))
    : site_url('admin/world-objects/store');
$submitLabel = $isEdit ? 'Обновить объект' : 'Добавить объект';

// Defensive biome_id decode: JSON → fallback CSV → fallback array от old().
$selectedBiomes = [];
if ($isEdit && $object !== null) {
    $raw = $object['biome_id'] ?? '';
    if (is_array($raw)) {
        $selectedBiomes = array_map('intval', $raw);
    } elseif (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $selectedBiomes = array_map('intval', $decoded);
        } else {
            // Legacy CSV-формат («1,2,3»).
            $selectedBiomes = array_map('intval', array_filter(array_map('trim', explode(',', $raw))));
        }
    }
} else {
    $oldBiome = old('biome_id');
    if (is_array($oldBiome)) {
        $selectedBiomes = array_map('intval', $oldBiome);
    }
}

// JSON-поля: discovery_tools, contents. В edit показываем как JSON-encoded string.
$encodeJsonField = static function (mixed $value): string {
    if ($value === null || $value === '') return '';
    if (is_array($value)) {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
        return $encoded !== false ? $encoded : '';
    }
    if (is_string($value)) {
        // Если уже JSON-string — оставляем; если plain text — encode.
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    return '';
};

$discoveryToolsValue = $isEdit && $object !== null
    ? $encodeJsonField($object['discovery_tools'] ?? '')
    : (string) old('discovery_tools', '');
$contentsValue = $isEdit && $object !== null
    ? $encodeJsonField($object['contents'] ?? '')
    : (string) old('contents', '');

$renderOption = static function (string $value, string $label, ?string $current): string {
    $selected = $current !== null && $current === $value ? ' selected' : '';
    return sprintf('<option value="%s"%s>%s</option>', esc($value), $selected, esc($label));
};

$currentStatus = $isEdit && $object !== null ? (string) ($object['status'] ?? '') : null;
?>

<?php /* Phase B5 (ADR-023, 2026-05-14) — зарегистрированные world-object handlers (динамически из HandlerRegistry). */ ?>
<?= view('admin/partials/_handler_options_panel', [
    'options' => $objectHandlers ?? [],
    'title'   => 'Зарегистрированные world-object handlers (HandlerRegistry, ADR-023)',
    'hint'    => 'world_objects.name_en → handler-key → handler-класс. Сейчас resolve через ObjectDiscoveryService::$objectHandlerKeyMap (Bunker/Technopark/GhostCity → strategic_loot, остальные 1:1).',
    'emoji'   => '🌐',
]) ?>

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
            <div class="row g-2">
                <div class="mb-3 col-md-5">
                    <label for="name" class="form-label">Название объекта</label>
                    <input type="text" class="form-control" id="name" name="name"
                           value="<?= $isEdit ? esc(old('name', $object['name'] ?? '')) : esc(old('name')) ?>" required>
                </div>
                <div class="mb-3 col-md-5">
                    <label for="name_en" class="form-label">Название (English)</label>
                    <input type="text" class="form-control" id="name_en" name="name_en"
                           value="<?= $isEdit ? esc(old('name_en', $object['name_en'] ?? '')) : esc(old('name_en')) ?>" required>
                </div>
                <div class="mb-3 col-md-2">
                    <label for="status1" class="form-label">Статус</label>
                    <select class="form-select" id="status1" name="status">
                        <?= $renderOption('active',   'Активный',    $currentStatus) ?>
                        <?= $renderOption('inactive', 'Неактивный',  $currentStatus) ?>
                    </select>
                </div>

                <div class="mb-3 col-md-12">
                    <label for="description" class="form-label">Описание</label>
                    <textarea class="form-control" id="description" name="description" rows="3"><?= $isEdit ? esc(old('description', $object['description'] ?? '')) : esc(old('description')) ?></textarea>
                </div>

                <div class="mb-3 col-md-6">
                    <label for="biome_id" class="form-label">Принадлежность к биомам</label>
                    <select class="form-select" id="biome_id" name="biome_id[]" multiple aria-label="Выберите один или несколько биомов">
                        <option value="">Не привязан к биому</option>
                        <?php foreach ($biomes as $biome): ?>
                            <option value="<?= esc($biome['id']) ?>"
                                <?= in_array((int) $biome['id'], $selectedBiomes, true) ? 'selected' : '' ?>>
                                <?= esc($biome['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Удерживайте Ctrl (Cmd на Mac) для нескольких.</small>
                </div>
                <div class="mb-3 col-md-3">
                    <label for="max_count" class="form-label">Максимальное количество</label>
                    <input type="number" class="form-control" id="max_count" name="max_count"
                           value="<?= $isEdit ? esc(old('max_count', $object['max_count'] ?? '')) : esc(old('max_count')) ?>">
                </div>
                <div class="mb-3 col-md-3">
                    <label for="respawn_time" class="form-label">Респаун (часы)</label>
                    <input type="number" class="form-control" id="respawn_time" name="respawn_time"
                           value="<?= $isEdit ? esc(old('respawn_time', $object['respawn_time'] ?? '')) : esc(old('respawn_time')) ?>">
                </div>

                <div class="mb-3 col-md-12">
                    <label for="discovery_tools" class="form-label">Инструменты для обнаружения (JSON)</label>
                    <input type="text" class="form-control" id="discovery_tools" name="discovery_tools"
                           value="<?= esc($discoveryToolsValue) ?>"
                           placeholder='Пример: ["Лом", "Фонарь"]'>
                </div>
                <div class="mb-3 col-md-12">
                    <label for="contents" class="form-label">Содержимое (JSON)</label>
                    <input type="text" class="form-control" id="contents" name="contents"
                           value="<?= esc($contentsValue) ?>"
                           placeholder='Пример: {"resources":{"Галька":350},"crafted_items":{"LumberjackAxe":3}}'>
                </div>
            </div>

            <button type="submit" class="btn btn-primary"><?= esc($submitLabel) ?></button>
        </form>
    </div>
</div>

<?php
/**
 * Унифицированный form-partial для создания и редактирования события.
 * Phase D, ADR-023 / admin-refactor-roadmap, 2026-05-13.
 *
 * Заменяет дублирующий ~90% код event_create_form.php + event_edit_form.php
 * (252 LOC → один partial + 2 thin wrappers).
 *
 * @param string                                $mode     'create' | 'edit'
 * @param array<string, mixed>|null             $event    Запись из БД (только при mode='edit')
 * @param array<int, array<string, mixed>>      $biomes   Список биомов для checkbox-группы
 */

$isEdit  = ($mode ?? 'create') === 'edit';
$event   = $event ?? null;

// При create — все биомы checked по умолчанию (legacy-поведение).
// При edit — checked только те, что в JSON event.biome_ids.
$selectedBiomes = $isEdit && $event !== null
    ? (json_decode($event['biome_ids'] ?? '[]', true) ?? [])
    : array_map(static fn (array $b): int => (int) $b['id'], $biomes);

$formAction = $isEdit
    ? site_url('admin/events/update/' . ($event['event_id'] ?? 0))
    : site_url('admin/events/store');

$submitLabel = $isEdit ? 'Обновить событие' : 'Создать событие';

/**
 * Mini-helper для select-options с заранее выбранным значением.
 *
 * @param array<string, string> $options  value => label
 */
$renderSelectOption = static function (string $value, string $label, ?string $current): string {
    $selected = $current !== null && $current === $value ? ' selected' : '';
    return sprintf('<option value="%s"%s>%s</option>', esc($value), $selected, esc($label));
};
?>

<?php if (!$isEdit): ?>
<div class="alert alert-info" role="alert">
    <strong>ℹ️ F7 архитектура:</strong> создание новой подии в админке = создание DB-row.
    Чтобы подія реально что-то делала, после её сохранения нужно <strong>добавить запись в
    <code>app/Config/WorldEvents.php</code></strong> по ключу <code>name_english</code> с полями
    <code>effect_kind</code>, <code>effect_params</code>, <code>tick_chance</code>,
    <code>frequency_weight</code> и т.п., затем задеплоить.
    <br><br>
    <strong>10 валидных effect_kind:</strong>
    <code>damage_health</code>, <code>damage_resources</code>, <code>heal</code>,
    <code>attribute_boost</code>, <code>reveal_cells</code>, <code>gold_grant</code>,
    <code>rare_resource_grant</code>, <code>task_extend</code>, <code>gather_debuff</code>,
    <code>noop</code>.
    <br><br>
    Без записи в WorldEvents.php: NotificationPolicy упадёт в legacy-broadcast, EventDispatcher пропустит эффекты.
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <?php if (session()->getFlashdata('errors')): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach (session()->getFlashdata('errors') as $error): ?>
                    <?= esc($error) ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$isEdit): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            <strong>Комментарии к полям:</strong><br>
            <strong>biome_ids:</strong> Хранение ID биомов в виде строки с разделителем (например, запятой) облегчает адаптацию к событиям, которые могут затрагивать несколько биомов. Для глобальных событий можно использовать специальное значение 'all', что упрощает запросы на выборку.<br>
            <strong>event_type:</strong> Позволяет разграничивать события по масштабу действия. Локальные события влияют только на определенный биом, в то время как глобальные — на всю игровую карту.<br>
            <strong>random_coverage:</strong> Добавляет гибкость в организации событий, позволяя некоторым событиям происходить в рандомизированных местах внутри биома или зоне действия.<br>
            <strong>effect_type и effect_value:</strong> Универсализируют обработку эффектов событий, позволяя легко добавлять новые типы воздействия на игрока или среду без изменения основной структуры таблицы.<br>
            <strong>protection_item_id:</strong> Связь с таблицей предметов или действий, которые могут нейтрализовать или смягчить эффекты события, добавляет слой стратегического планирования в игровой процесс.<br>
            <strong>start_time и end_time:</strong> Опциональные поля для событий, имеющих строго определенное время действия в течение суток, что добавляет элементы реального времени в динамику игрового процесса.
        </div>
        <?php endif; ?>

        <form action="<?= $formAction ?>" method="post">
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="mb-3 col-md-3">
                    <label for="name" class="form-label">Название события</label>
                    <input type="text" class="form-control" id="name" name="name"
                           value="<?= $isEdit ? esc($event['name'] ?? '') : old('name') ?>" required>
                </div>
                <div class="mb-3 col-md-3">
                    <label for="name_english" class="form-label">Название (English Translation)</label>
                    <input type="text" class="form-control" id="name_english" name="name_english"
                           value="<?= $isEdit ? esc($event['name_english'] ?? '') : old('name_english') ?>" required>
                </div>

                <?php if (!$isEdit): ?>
                <div class="mb-3 col-md-3">
                    <label for="start_time" class="form-label">Время начала</label>
                    <input type="time" class="form-control" id="start_time" name="start_time" value="<?= old('start_time') ?>">
                </div>
                <div class="mb-3 col-md-3">
                    <label for="end_time" class="form-label">Время окончания</label>
                    <input type="time" class="form-control" id="end_time" name="end_time" value="<?= old('end_time') ?>">
                </div>
                <?php endif; ?>

                <div class="mb-3 col-md-<?= $isEdit ? '6' : '6' ?>">
                    <label for="event_type" class="form-label">Тип события</label>
                    <?php if (!$isEdit): ?>
                    <p class="text-muted font-12">
                        event_type: Локальные события влияют только на определенный биом, в то время как глобальные — на всю игровую карту.
                    </p>
                    <?php endif; ?>
                    <select class="form-select" id="event_type" name="event_type" required>
                        <?= $renderSelectOption('local', 'Локальное', $isEdit ? (string) ($event['event_type'] ?? '') : null) ?>
                        <?= $renderSelectOption('global', 'Глобальное', $isEdit ? (string) ($event['event_type'] ?? '') : null) ?>
                    </select>
                </div>

                <div class="mb-3 col-md-6">
                    <label for="img_path" class="form-label">Путь к изображению сообщения в телеге</label>
                    <input type="text" class="form-control" id="img_path" name="img_path"
                           value="<?= $isEdit ? esc($event['img_path'] ?? '') : old('img_path') ?>"
                           <?= $isEdit ? '' : 'required' ?>
                           placeholder="uploads/default_event_image.png">
                </div>
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">Описание</label>
                <textarea class="form-control" id="description" name="description" rows="4"><?= $isEdit ? esc($event['description'] ?? '') : old('description') ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label">Биомы</label>
                <div class="d-flex flex-wrap">
                    <?php foreach ($biomes as $biome): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox"
                                   id="biome_<?= esc($biome['id']) ?>"
                                   name="biome_ids[]" value="<?= esc($biome['id']) ?>"
                                   <?= in_array((int) $biome['id'], array_map('intval', $selectedBiomes), true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="biome_<?= esc($biome['id']) ?>"><?= esc($biome['name']) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row g-2">
                <div class="mb-3 col-md-4">
                    <label for="duration" class="form-label">Длительность (минуты)</label>
                    <input type="number" class="form-control" id="duration" name="duration"
                           value="<?= $isEdit ? esc($event['duration'] ?? '') : old('duration') ?>">
                </div>
                <div class="mb-3 col-md-4">
                    <label for="frequency_per_week" class="form-label">Частота в неделю</label>
                    <input type="text" class="form-control" id="frequency_per_week" name="frequency_per_week"
                           value="<?= $isEdit ? esc($event['frequency_per_week'] ?? '') : old('frequency_per_week') ?>">
                </div>
                <div class="mb-3 col-md-4">
                    <label for="effect_type" class="form-label">Тип эффекта</label>
                    <select class="form-select" id="effect_type" name="effect_type" required>
                        <?php $current = $isEdit ? (string) ($event['effect_type'] ?? '') : null; ?>
                        <?= $renderSelectOption('damage', 'Урон', $current) ?>
                        <?= $renderSelectOption('heal', 'Лечение', $current) ?>
                        <?= $renderSelectOption('buff', 'Усиление', $current) ?>
                        <?= $renderSelectOption('debuff', 'Ослабление', $current) ?>
                        <?= $renderSelectOption('none', 'Без эффекта', $current) ?>
                    </select>
                </div>
            </div>

            <div class="row g-2">
                <div class="mb-3 col-md-4">
                    <label for="effect_value" class="form-label">Значение эффекта</label>
                    <input type="number" class="form-control" id="effect_value" name="effect_value"
                           value="<?= $isEdit ? esc($event['effect_value'] ?? '') : old('effect_value') ?>">
                </div>
                <div class="mb-3 col-md-4">
                    <label for="protection_item_id" class="form-label">ID предмета защиты</label>
                    <input type="number" class="form-control" id="protection_item_id" name="protection_item_id"
                           value="<?= $isEdit ? esc($event['protection_item_id'] ?? '') : old('protection_item_id') ?>">
                </div>
                <div class="mb-3 col-md-4 form-check">
                    <?php if (!$isEdit): ?>
                    <p class="text-muted font-12">
                        random_coverage: Добавляет гибкость в организации событий, позволяя некоторым событиям происходить в рандомизированных местах внутри биома или зоне действия.
                    </p>
                    <?php endif; ?>
                    <input type="hidden" name="random_coverage" value="0">
                    <input type="checkbox" class="form-check-input" id="random_coverage" name="random_coverage" value="1"
                           <?= ($isEdit && !empty($event['random_coverage'])) || (!$isEdit && set_checkbox('random_coverage', '1')) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="random_coverage">Случайное покрытие</label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary"><?= esc($submitLabel) ?></button>
        </form>
    </div>
</div>

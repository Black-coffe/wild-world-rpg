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
// $biomes — list<BiomeEntity|array> (F1.4 migration: BiomeModel returnType=Entity, доступ через ArrayAccess).
$selectedBiomes = $isEdit && $event !== null
    ? (json_decode($event['biome_ids'] ?? '[]', true) ?? [])
    : array_map(static fn ($b): int => (int) $b['id'], $biomes);

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
<div class="aui-alert aui-alert--warning" style="margin-bottom:var(--sp-4)"><i class="ri-alert-line"></i><div>
    <div class="aui-alert__title">ℹ️ F7 архитектура</div>
    создание новой подии в админке = создание DB-row.
    Чтобы подія реально что-то делала, после её сохранения нужно <strong>добавить запись в
    <code class="aui-mono">app/Config/WorldEvents.php</code></strong> по ключу <code class="aui-mono">name_english</code> с полями
    <code class="aui-mono">effect_kind</code>, <code class="aui-mono">effect_params</code>, <code class="aui-mono">tick_chance</code>,
    <code class="aui-mono">frequency_weight</code> и т.п., затем задеплоить.
    <br><br>
    Без записи в WorldEvents.php: NotificationPolicy упадёт в legacy-broadcast, EventDispatcher пропустит эффекты.
</div></div>
<?php endif; ?>

<?php /* Phase B5 (ADR-023, 2026-05-14) — registered effect-handlers (динамически из HandlerRegistry, не hardcoded). */ ?>
<?= view('admin/partials/_handler_options_panel', [
    'options' => $effectHandlers ?? [],
    'title'   => 'Зарегистрированные effect_kind (HandlerRegistry, ADR-023)',
    'hint'    => 'Используй один из этих ключей в `WorldEvents.php → effect_kind` для соответствующего name_english. Программист добавляет новый Effect-класс с #[HandlerKey] — он автоматически появится в этом списке.',
    'emoji'   => '🎲',
]) ?>

<div class="aui-card"><div class="aui-card__body">
        <?php if (session()->getFlashdata('errors')): ?>
            <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div>
                <?php foreach (session()->getFlashdata('errors') as $error): ?>
                    <?= esc($error) ?><br>
                <?php endforeach; ?>
            </div></div>
        <?php endif; ?>

        <?php if (!$isEdit): ?>
        <div class="aui-alert aui-alert--info" style="margin-bottom:var(--sp-5)"><i class="ri-information-line"></i><div>
            <div class="aui-alert__title">Комментарии к полям:</div>
            <strong>biome_ids:</strong> Хранение ID биомов в виде строки с разделителем (например, запятой) облегчает адаптацию к событиям, которые могут затрагивать несколько биомов. Для глобальных событий можно использовать специальное значение 'all', что упрощает запросы на выборку.<br>
            <strong>event_type:</strong> Позволяет разграничивать события по масштабу действия. Локальные события влияют только на определенный биом, в то время как глобальные — на всю игровую карту.<br>
            <strong>random_coverage:</strong> Добавляет гибкость в организации событий, позволяя некоторым событиям происходить в рандомизированных местах внутри биома или зоне действия.<br>
            <strong>effect_type и effect_value:</strong> Универсализируют обработку эффектов событий, позволяя легко добавлять новые типы воздействия на игрока или среду без изменения основной структуры таблицы.<br>
            <strong>protection_item_id:</strong> Связь с таблицей предметов или действий, которые могут нейтрализовать или смягчить эффекты события, добавляет слой стратегического планирования в игровой процесс.<br>
            <strong>start_time и end_time:</strong> Опциональные поля для событий, имеющих строго определенное время действия в течение суток, что добавляет элементы реального времени в динамику игрового процесса.
        </div></div>
        <?php endif; ?>

        <form action="<?= $formAction ?>" method="post">
            <?= csrf_field() ?>
            <div class="aui-formgrid">
                <div class="aui-field">
                    <label for="name" class="aui-label">Название события <span class="req">*</span></label>
                    <input type="text" class="aui-input" id="name" name="name"
                           value="<?= $isEdit ? esc($event['name'] ?? '') : old('name') ?>" required>
                </div>
                <div class="aui-field">
                    <label for="name_english" class="aui-label">Название (English Translation) <span class="req">*</span></label>
                    <input type="text" class="aui-input" id="name_english" name="name_english"
                           value="<?= $isEdit ? esc($event['name_english'] ?? '') : old('name_english') ?>" required>
                </div>
                <?php
                /** Phase B6 (ADR-023) — handler_key dropdown. NULL = legacy fallback. */
                $rawEditHk = $isEdit && $event !== null ? ($event['handler_key'] ?? '') : old('handler_key', '');
                $currentHandlerKey = is_string($rawEditHk) ? $rawEditHk : '';
                ?>
                <div class="aui-field">
                    <label for="handler_key" class="aui-label">Handler (effect_kind)</label>
                    <select class="aui-select" id="handler_key" name="handler_key">
                        <option value=""<?= $currentHandlerKey === '' ? ' selected' : '' ?>>— legacy fallback (NULL) —</option>
                        <?php foreach (($effectHandlers ?? []) as $opt): ?>
                            <?php
                            $optKey = is_string($opt['key'] ?? null) ? (string) $opt['key'] : '';
                            $optDisplay = is_string($opt['displayName'] ?? null) ? (string) $opt['displayName'] : '';
                            ?>
                            <option value="<?= esc($optKey) ?>"<?= $currentHandlerKey === $optKey ? ' selected' : '' ?>>
                                <?= esc($optKey) ?> — <?= esc($optDisplay) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="aui-hint">B6: dispatcher предпочитает handler_key. NULL = старый путь через WorldEvents.php.</div>
                </div>

                <?php if (!$isEdit): ?>
                <div class="aui-field">
                    <label for="start_time" class="aui-label">Время начала</label>
                    <input type="time" class="aui-input" id="start_time" name="start_time" value="<?= old('start_time') ?>">
                </div>
                <div class="aui-field">
                    <label for="end_time" class="aui-label">Время окончания</label>
                    <input type="time" class="aui-input" id="end_time" name="end_time" value="<?= old('end_time') ?>">
                </div>
                <?php endif; ?>

                <div class="aui-field">
                    <label for="event_type" class="aui-label">Тип события <span class="req">*</span></label>
                    <?php if (!$isEdit): ?>
                    <p class="aui-hint">
                        event_type: Локальные события влияют только на определенный биом, в то время как глобальные — на всю игровую карту.
                    </p>
                    <?php endif; ?>
                    <select class="aui-select" id="event_type" name="event_type" required>
                        <?= $renderSelectOption('local', 'Локальное', $isEdit ? (string) ($event['event_type'] ?? '') : null) ?>
                        <?= $renderSelectOption('global', 'Глобальное', $isEdit ? (string) ($event['event_type'] ?? '') : null) ?>
                    </select>
                </div>

                <div class="aui-field">
                    <label for="img_path" class="aui-label">Путь к изображению сообщения в телеге</label>
                    <input type="text" class="aui-input" id="img_path" name="img_path"
                           value="<?= $isEdit ? esc($event['img_path'] ?? '') : old('img_path') ?>"
                           <?= $isEdit ? '' : 'required' ?>
                           placeholder="uploads/default_event_image.png">
                </div>
            </div>

            <div class="aui-field">
                <label for="description" class="aui-label">Описание</label>
                <textarea class="aui-textarea" id="description" name="description" rows="4"><?= $isEdit ? esc($event['description'] ?? '') : old('description') ?></textarea>
            </div>

            <div class="aui-field">
                <label class="aui-label">Биомы</label>
                <div class="aui-checkrow">
                    <?php foreach ($biomes as $biome): ?>
                        <label class="aui-check" for="biome_<?= esc($biome['id']) ?>">
                            <input class="form-check-input" type="checkbox"
                                   id="biome_<?= esc($biome['id']) ?>"
                                   name="biome_ids[]" value="<?= esc($biome['id']) ?>"
                                   <?= in_array((int) $biome['id'], array_map('intval', $selectedBiomes), true) ? 'checked' : '' ?>>
                            <span class="aui-check__box"></span> <?= esc($biome['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="aui-formgrid">
                <div class="aui-field">
                    <label for="duration" class="aui-label">Длительность (минуты)</label>
                    <input type="number" class="aui-input" id="duration" name="duration"
                           value="<?= $isEdit ? esc($event['duration'] ?? '') : old('duration') ?>">
                </div>
                <div class="aui-field">
                    <label for="frequency_per_week" class="aui-label">Частота в неделю</label>
                    <input type="text" class="aui-input" id="frequency_per_week" name="frequency_per_week"
                           value="<?= $isEdit ? esc($event['frequency_per_week'] ?? '') : old('frequency_per_week') ?>">
                </div>
                <div class="aui-field">
                    <label for="effect_type" class="aui-label">Тип эффекта <span class="req">*</span></label>
                    <select class="aui-select" id="effect_type" name="effect_type" required>
                        <?php $current = $isEdit ? (string) ($event['effect_type'] ?? '') : null; ?>
                        <?= $renderSelectOption('damage', 'Урон', $current) ?>
                        <?= $renderSelectOption('heal', 'Лечение', $current) ?>
                        <?= $renderSelectOption('buff', 'Усиление', $current) ?>
                        <?= $renderSelectOption('debuff', 'Ослабление', $current) ?>
                        <?= $renderSelectOption('none', 'Без эффекта', $current) ?>
                    </select>
                </div>
            </div>

            <div class="aui-formgrid">
                <div class="aui-field">
                    <label for="effect_value" class="aui-label">Значение эффекта</label>
                    <input type="number" class="aui-input" id="effect_value" name="effect_value"
                           value="<?= $isEdit ? esc($event['effect_value'] ?? '') : old('effect_value') ?>">
                </div>
                <div class="aui-field">
                    <label for="protection_item_id" class="aui-label">ID предмета защиты</label>
                    <input type="number" class="aui-input" id="protection_item_id" name="protection_item_id"
                           value="<?= $isEdit ? esc($event['protection_item_id'] ?? '') : old('protection_item_id') ?>">
                </div>
                <div class="aui-field">
                    <?php if (!$isEdit): ?>
                    <p class="aui-hint">
                        random_coverage: Добавляет гибкость в организации событий, позволяя некоторым событиям происходить в рандомизированных местах внутри биома или зоне действия.
                    </p>
                    <?php endif; ?>
                    <input type="hidden" name="random_coverage" value="0">
                    <div class="aui-checkrow">
                        <label class="aui-check" for="random_coverage">
                            <input type="checkbox" class="form-check-input" id="random_coverage" name="random_coverage" value="1"
                                   <?= ($isEdit && !empty($event['random_coverage'])) || (!$isEdit && set_checkbox('random_coverage', '1')) ? 'checked' : '' ?>>
                            <span class="aui-check__box"></span> Случайное покрытие
                        </label>
                    </div>
                </div>
            </div>

            <div class="aui-form-actions">
                <button type="submit" class="aui-btn aui-btn--primary"><i class="ri-save-line"></i> <?= esc($submitLabel) ?></button>
            </div>
        </form>
</div></div>

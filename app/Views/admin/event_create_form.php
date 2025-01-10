<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Создать новое событие</h2>

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
            <strong>Комментарии к полям:</strong><br>
            <strong>biome_ids:</strong> Хранение ID биомов в виде строки с разделителем (например, запятой) облегчает адаптацию к событиям, которые могут затрагивать несколько биомов. Для глобальных событий можно использовать специальное значение 'all', что упрощает запросы на выборку.<br>
            <strong>event_type:</strong> Позволяет разграничивать события по масштабу действия. Локальные события влияют только на определенный биом, в то время как глобальные — на всю игровую карту.<br>
            <strong>random_coverage:</strong> Добавляет гибкость в организации событий, позволяя некоторым событиям происходить в рандомизированных местах внутри биома или зоне действия.<br>
            <strong>effect_type и effect_value:</strong> Универсализируют обработку эффектов событий, позволяя легко добавлять новые типы воздействия на игрока или среду без изменения основной структуры таблицы.<br>
            <strong>protection_item_id:</strong> Связь с таблицей предметов или действий, которые могут нейтрализовать или смягчить эффекты события, добавляет слой стратегического планирования в игровой процесс.<br>
            <strong>start_time и end_time:</strong> Опциональные поля для событий, имеющих строго определенное время действия в течение суток, что добавляет элементы реального времени в динамику игрового процесса.
        </div>
        <form action="<?= site_url('admin/events/store') ?>" method="post">
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="mb-3 col-md-3">
                    <label for="name" class="form-label">Название события</label>
                    <input type="text" class="form-control" id="name" name="name" value="<?= old('name') ?>" required>
                </div>
                <div class="mb-3 col-md-3">
                    <label for="name_english" class="form-label">Название (English Translation)</label>
                    <input type="text" class="form-control" id="name_english" name="name_english" value="<?= old('name_english') ?>" required>
                </div>

                <div class="mb-3 col-md-3">
                    <label for="start_time" class="form-label">Время начала</label>
                    <input type="time" class="form-control" id="start_time" name="start_time" value="<?= old('start_time') ?>">
                </div>

                <div class="mb-3 col-md-3">
                    <label for="end_time" class="form-label">Время окончания</label>
                    <input type="time" class="form-control" id="end_time" name="end_time" value="<?= old('end_time') ?>">
                </div>
                <div class="mb-3 col-md-6">
                    <label for="event_type" class="form-label">Тип события</label>
                    <p class="text-muted font-12">
                        event_type: Локальные события влияют только на определенный биом, в то время как глобальные — на всю игровую карту.
                    </p>
                    <select class="form-select" id="event_type" name="event_type" required>
                        <option value="local">Локальное</option>
                        <option value="global">Глобальное</option>
                    </select>
                </div>
                <div class="mb-3 col-md-6">
                    <label for="img_path" class="form-label">Путь к изображению сообщения в телеге</label>
                    <input type="text" class="form-control" id="img_path" name="img_path" value="<?= old('img_path') ?>" required placeholder="uploads/default_event_image.png">
                </div>
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">Описание</label>
                <textarea class="form-control" id="description" name="description" rows="4"><?= old('description') ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label">Биомы</label>
                <div class="d-flex flex-wrap">
                    <?php foreach ($bioms as $biom): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="biome_<?= esc($biom['id']) ?>" name="biome_ids[]" value="<?= esc($biom['id']) ?>" checked>
                            <label class="form-check-label" for="biome_<?= esc($biom['id']) ?>"><?= esc($biom['name']) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row g-2">
                <div class="mb-3 col-md-4">
                    <label for="duration" class="form-label">Длительность (минуты)</label>
                    <input type="number" class="form-control" id="duration" name="duration" value="<?= old('duration') ?>">
                </div>
                <div class="mb-3 col-md-4">
                    <label for="frequency_per_week" class="form-label">Частота в неделю</label>
                    <input type="text" class="form-control" id="frequency_per_week" name="frequency_per_week" value="<?= old('frequency_per_week') ?>">
                </div>
                <div class="mb-3 col-md-4">
                    <label for="effect_type" class="form-label">Тип эффекта</label>
                    <select class="form-select" id="effect_type" name="effect_type" required>
                        <option value="damage">Урон</option>
                        <option value="heal">Лечение</option>
                        <option value="buff">Усиление</option>
                        <option value="debuff">Ослабление</option>
                        <option value="none">Без эффекта</option>
                    </select>
                </div>
            </div>

            <div class="row g-2">
                <div class="mb-3 col-md-4">
                    <label for="effect_value" class="form-label">Значение эффекта</label>
                    <input type="number" class="form-control" id="effect_value" name="effect_value" value="<?= old('effect_value') ?>">
                </div>
                <div class="mb-3 col-md-4">
                    <label for="protection_item_id" class="form-label">ID предмета защиты</label>
                    <input type="number" class="form-control" id="protection_item_id" name="protection_item_id" value="<?= old('protection_item_id') ?>">
                </div>
                <div class="mb-3 col-md-4 form-check">
                    <p class="text-muted font-12">
                        random_coverage: Добавляет гибкость в организации событий, позволяя некоторым событиям происходить в рандомизированных местах внутри биома или зоне действия.
                    </p>
                    <input type="hidden" name="random_coverage" value="0">
                    <input type="checkbox" class="form-check-input" id="random_coverage" name="random_coverage" value="1" <?= set_checkbox('random_coverage', '1'); ?>>
                    <label class="form-check-label" for="random_coverage">Случайное покрытие</label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Создать событие</button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>


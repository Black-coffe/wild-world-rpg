<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Редактировать событие: <?= esc($event['name']) ?></h2>

<div class="card">
    <div class="card-body">
        <?php if (session()->getFlashdata('errors')): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach (session()->getFlashdata('errors') as $error): ?>
                    <?= esc($error) ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form action="<?= site_url('admin/events/update/' . $event['event_id']) ?>" method="post">
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="mb-3 col-md-4">
                    <label for="name" class="form-label">Название события</label>
                    <input type="text" class="form-control" id="name" name="name" value="<?= esc($event['name']) ?>" required>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="name_english" class="form-label">Название (English Translation)</label>
                    <input type="text" class="form-control" id="name_english" name="name_english" value="<?= esc($event['name_english']) ?>" required>
                </div>

                <div class="mb-3 col-md-4">
                    <label for="event_type" class="form-label">Тип события</label>
                    <select class="form-select" id="event_type" name="event_type" required>
                        <option value="local" <?= $event['event_type'] == 'local' ? 'selected' : '' ?>>Локальное</option>
                        <option value="global" <?= $event['event_type'] == 'global' ? 'selected' : '' ?>>Глобальное</option>
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">Описание</label>
                <textarea class="form-control" id="description" name="description" rows="4"><?= esc($event['description']) ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label">Биомы</label>
                <div class="d-flex flex-wrap">
                    <?php foreach ($biomes as $biome): ?>
                        <?php
                        // Декодирование списка ID биомов из JSON
                        $selectedBiomes = json_decode($event['biome_ids'], true);
                        ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="biome_<?= esc($biome['id']) ?>" name="biome_ids[]" value="<?= esc($biome['id']) ?>" <?= in_array($biome['id'], $selectedBiomes) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="biome_<?= esc($biome['id']) ?>"><?= esc($biome['name']) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row g-2">
                <div class="mb-3 col-md-4">
                    <label for="duration" class="form-label">Длительность (минуты)</label>
                    <input type="number" class="form-control" id="duration" name="duration" value="<?= esc($event['duration']) ?>">
                </div>

                <div class="mb-3 col-md-4">
                    <label for="frequency_per_week" class="form-label">Частота в неделю</label>
                    <input type="text" class="form-control" id="frequency_per_week" name="frequency_per_week" value="<?= esc($event['frequency_per_week']) ?>">
                </div>

                <div class="mb-3 col-md-4">
                    <label for="effect_type" class="form-label">Тип эффекта</label>
                    <select class="form-select" id="effect_type" name="effect_type" required>
                        <option value="damage" <?= $event['effect_type'] == 'damage' ? 'selected' : '' ?>>Урон</option>
                        <option value="heal" <?= $event['effect_type'] == 'heal' ? 'selected' : '' ?>>Лечение</option>
                        <option value="buff" <?= $event['effect_type'] == 'buff' ? 'selected' : '' ?>>Усиление</option>
                        <option value="debuff" <?= $event['effect_type'] == 'debuff' ? 'selected' : '' ?>>Ослабление</option>
                        <option value="none" <?= $event['effect_type'] == 'none' ? 'selected' : '' ?>>Без эффекта</option>
                    </select>
                </div>
                <div class="mb-3 col-md-12">
                    <label for="img_path" class="form-label">Путь к изображению сообщения в телеге</label>
                    <input type="text" class="form-control" id="img_path" name="img_path" value="<?= esc($event['img_path']) ?>">
                </div>
            </div>

            <div class="row g-2">
                <div class="mb-3 col-md-4">
                    <label for="effect_value" class="form-label">Значение эффекта</label>
                    <input type="number" class="form-control" id="effect_value" name="effect_value" value="<?= esc($event['effect_value']) ?>">
                </div>

                <div class="mb-3 col-md-4">
                    <label for="protection_item_id" class="form-label">ID предмета защиты</label>
                    <input type="number" class="form-control" id="protection_item_id" name="protection_item_id" value="<?= esc($event['protection_item_id']) ?>">
                </div>

                <div class="mb-3 col-md-4 form-check">
                    <!-- Скрытое поле, которое отправляет значение 0, если чекбокс не отмечен -->
                    <input type="hidden" name="random_coverage" value="0">
                    <!-- Чекбокс, который изменяет значение на 1, если отмечен -->
                    <input type="checkbox" class="form-check-input" id="random_coverage" name="random_coverage" value="1" <?= set_value('random_coverage', isset($event) && $event['random_coverage'] ? '1' : '0') == 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="random_coverage">Случайное покрытие</label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Обновить событие</button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

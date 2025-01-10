<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Редактировать объект в игровом мире</h2>

<div class="card">
    <div class="card-body">
        <?php if (session()->getFlashdata('errors')): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach (session()->getFlashdata('errors') as $error): ?>
                    <?= esc($error) ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Форма редактирования объекта -->
        <form action="<?= site_url('admin/world-objects/update/' . $object['id']) ?>" method="post">
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="mb-3 col-md-5">
                    <label for="name" class="form-label">Название объекта</label>
                    <input type="text" class="form-control" id="name" name="name" value="<?= esc($object['name']) ?>" required>
                </div>
                <div class="mb-3 col-md-5">
                    <label for="name_en" class="form-label">Название объекта (англ.)</label>
                    <input type="text" class="form-control" id="name_en" name="name_en" value="<?= esc($object['name_en']) ?>" required>
                </div>
                <div class="mb-3 col-md-2">
                    <label for="status1" class="form-label">Статус</label>
                    <select class="form-select" id="status1" name="status">
                        <option value="active" <?= $object['status'] == 'active' ? 'selected' : '' ?>>Активный</option>
                        <option value="inactive" <?= $object['status'] == 'inactive' ? 'selected' : '' ?>>Неактивный</option>
                    </select>
                </div>
                <div class="mb-3 col-md-12">
                    <label for="description" class="form-label">Описание</label>
                    <textarea class="form-control" id="description" name="description" rows="3"><?= esc($object['description']) ?></textarea>
                </div>
                <div class="mb-3 col-md-6">
                    <label for="biome_id" class="form-label">Принадлежность к биомам</label>
                    <select class="form-select" id="biome_id" name="biome_id[]" multiple aria-label="Выберите один или несколько биомов">
                        <option value="">Не привязан к биому</option>
                        <?php foreach ($biomes as $biome): ?>
                            <option value="<?= $biome['id'] ?>" <?= in_array($biome['id'], explode(',', $object['biome_id'])) ? 'selected' : '' ?>><?= esc($biome['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Удерживайте Ctrl (Cmd на Mac) для выбора нескольких опций.</small>
                </div>
                <div class="mb-3 col-md-3">
                    <label for="max_count" class="form-label">Максимальное количество</label>
                    <input type="number" class="form-control" id="max_count" name="max_count" value="<?= esc($object['max_count']) ?>">
                </div>
                <div class="mb-3 col-md-3">
                    <label for="respawn_time" class="form-label">Время респауна (часы)</label>
                    <input type="number" class="form-control" id="respawn_time" name="respawn_time" value="<?= esc($object['respawn_time']) ?>">
                </div>
                <div class="mb-3 col-md-12">
                    <label for="discovery_tools" class="form-label">Инструменты для обнаружения</label>
                    <input type="text" class="form-control" id="discovery_tools" name="discovery_tools" value="<?= esc(json_encode($object['discovery_tools'])) ?>" placeholder='Пример: ["Лом", "Фонарь"]'>
                </div>
                <div class="mb-3 col-md-12">
                    <label for="contents" class="form-label">Содержимое</label>
                    <input type="text" class="form-control" id="contents" name="contents" value="<?= esc(json_encode($object['contents'] ?? [])) ?>" placeholder='Пример: {"resources": {"Галька": 350}, "crafted_items": {"LumberjackAxe": 3}}'>
                </div>

            </div>
            <button type="submit" class="btn btn-primary">Обновить объект</button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

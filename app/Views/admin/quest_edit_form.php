<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Редактировать квест: <?= esc($quest['title_ru']) ?></h2>

<div class="card">
    <div class="card-body">
        <?php if (session()->getFlashdata('errors')): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach (session()->getFlashdata('errors') as $error): ?>
                    <?= esc($error) ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form action="<?= site_url('admin/quests/update/' . $quest['id']) ?>" method="post">
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="mb-3 col-md-5">
                    <label for="title_ru" class="form-label">Название квеста (Русский)</label>
                    <input type="text" class="form-control" id="title_ru" name="title_ru" value="<?= esc($quest['title_ru']) ?>" required>
                </div>
                <div class="mb-3 col-md-5">
                    <label for="title_en" class="form-label">Название квеста (Английский)</label>
                    <input type="text" class="form-control" id="title_en" name="title_en" value="<?= esc($quest['title_en']) ?>" required>
                </div>
                <div class="mb-3 col-md-2">
                    <label for="status_line" class="form-label">Статус квеста</label>
                    <select class="form-select" id="status_line" name="status" required>
                        <option value="active" <?= $quest['status'] == 'active' ? 'selected' : '' ?>>Активный</option>
                        <option value="completed" <?= $quest['status'] == 'completed' ? 'selected' : '' ?>>Завершенный</option>
                        <option value="available" <?= $quest['status'] == 'available' ? 'selected' : '' ?>>Доступный</option>
                    </select>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="reward" class="form-label">Награда за квест</label>
                    <input type="number" class="form-control" id="reward" name="reward" value="<?= esc($quest['reward']) ?>" required>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="reward_type" class="form-label">Тип награды</label>
                    <select class="form-select" id="reward_type" name="reward_type" required>
                        <option value="gold">Золото</option>
                        <option value="resources">Ресурсы</option>
                        <option value="Craft tools">Крафтовые инструменты</option>
                        <option value="recipes">Рецепты</option>
                        <option value="experience">Опыт</option>
                        <option value="another">Другое</option>
                    </select>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="min_level" class="form-label">Минимальный уровень</label>
                    <input type="number" class="form-control" id="min_level" name="min_level" value="<?= esc($quest['min_level']) ?>" required>
                </div>
                <div class="mb-3  col-md-12">
                    <label for="description" class="form-label">Описание квеста</label>
                    <textarea class="form-control" id="description" name="description" rows="4"><?= esc($quest['description']) ?></textarea>
                </div>
                <div class="mb-3 col-md-12">
                    <button type="submit" class="btn btn-primary">Обновить квест</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

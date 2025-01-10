<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Добавить новый квест</h2>

<div class="card">
    <div class="card-body">
        <?php if (session()->getFlashdata('errors')): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach (session()->getFlashdata('errors') as $error): ?>
                    <?= esc($error) ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Форма создания нового квеста -->
        <form action="<?= site_url('admin/quests/store') ?>" method="post">
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="mb-3 col-md-5">
                    <label for="title_ru" class="form-label">Название квеста (рус.)</label>
                    <input type="text" class="form-control" id="title_ru" name="title_ru" required value="<?= old('title_ru') ?>">
                </div>
                <div class="mb-3 col-md-5">
                    <label for="title_en" class="form-label">Название квеста (англ.)</label>
                    <input type="text" class="form-control" id="title_en" name="title_en" required value="<?= old('title_en') ?>">
                </div>
                <div class="mb-3 col-md-2">
                    <label for="status_line" class="form-label">Статус</label>
                    <select class="form-select" id="status_line" name="status">
                        <option value="active">Активный</option>
                        <option value="completed">Завершенный</option>
                        <option value="available">Доступный</option>
                    </select>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="reward" class="form-label">Награда (количество)</label>
                    <select class="form-select" id="reward" name="reward">
                        <option value="gold">Золото</option>
                        <option value="resources">Ресурсы</option>
                        <option value="Craft tools">Крафтовые инструменты</option>
                        <option value="recipes">Рецепты</option>
                        <option value="experience">Опыт</option>
                        <option value="another">Другое</option>
                    </select>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="reward" class="form-label">Награда</label>
                    <input type="number" class="form-control" id="reward" name="reward" value="<?= old('reward') ?>">
                </div>
                <div class="mb-3 col-md-4">
                    <label for="min_level" class="form-label">Мин. Уровень персонажа</label>
                    <input type="number" class="form-control" id="min_level" name="min_level" value="<?= old('min_level') ?>">
                </div>
                <div class="mb-3 col-md-12">
                    <label for="description" class="form-label">Описание</label>
                    <textarea class="form-control" id="description" name="description" rows="3"><?= old('description') ?></textarea>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Добавить квест</button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

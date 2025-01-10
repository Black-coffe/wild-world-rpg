<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Редактировать совет: <?= esc($tip['title_ru']) ?></h2>

<div class="card">
    <div class="card-body">
        <?php if (session()->getFlashdata('errors')): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach (session()->getFlashdata('errors') as $error): ?>
                    <?= esc($error) ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form action="<?= site_url('admin/tips/update/' . $tip['id']) ?>" method="post">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="title_ru" class="form-label">Название совета (Русский)</label>
                <input type="text" class="form-control" id="title_ru" name="title_ru" value="<?= esc($tip['title_ru']) ?>" required>
            </div>
            <div class="mb-3">
                <label for="title_en" class="form-label">Название совета (Английский)</label>
                <input type="text" class="form-control" id="title_en" name="title_en" value="<?= esc($tip['title_en']) ?>" required>
            </div>
            <div class="mb-3">
                <label for="tip_type" class="form-label">Тип совета</label>
                <select class="form-select" id="tip_type" name="tip_type" required>
                    <option value="биомы" <?= $tip['tip_type'] == 'биомы' ? 'selected' : '' ?>>Биомы</option>
                    <option value="ресурсы" <?= $tip['tip_type'] == 'ресурсы' ? 'selected' : '' ?>>Ресурсы</option>
                    <option value="крафт" <?= $tip['tip_type'] == 'крафт' ? 'selected' : '' ?>>Крафт</option>
                    <option value="персонаж" <?= $tip['tip_type'] == 'персонаж' ? 'selected' : '' ?>>Персонаж</option>
                    <option value="события" <?= $tip['tip_type'] == 'события' ? 'selected' : '' ?>>События</option>
                    <option value="NPC" <?= $tip['tip_type'] == 'NPC' ? 'selected' : '' ?>>NPC</option>
                    <option value="общие" <?= $tip['tip_type'] == 'общие' ? 'selected' : '' ?>>Общие</option>
                </select>
            </div>
            <div class="mb-3">
                <label for="content" class="form-label">Содержимое совета</label>
                <textarea class="form-control" id="content" name="content" rows="4"><?= esc($tip['content']) ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Обновить совет</button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

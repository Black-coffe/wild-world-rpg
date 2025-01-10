<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Создать новый совет</h2>

<div class="card">
    <div class="card-body">
        <?php if (session()->getFlashdata('errors')): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach (session()->getFlashdata('errors') as $error): ?>
                    <?= esc($error) ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form action="<?= site_url('admin/tips/store') ?>" method="post">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="title_ru" class="form-label">Название совета (русский)</label>
                <input type="text" class="form-control" id="title_ru" name="title_ru" value="<?= old('title_ru') ?>" required>
            </div>
            <div class="mb-3">
                <label for="title_en" class="form-label">Название совета (английский)</label>
                <input type="text" class="form-control" id="title_en" name="title_en" value="<?= old('title_en') ?>" required>
            </div>
            <div class="mb-3">
                <label for="tip_type" class="form-label">Тип совета</label>
                <select class="form-select" id="tip_type" name="tip_type" required>
                    <option value="биомы">Биомы</option>
                    <option value="ресурсы">Ресурсы</option>
                    <option value="крафт">Крафт</option>
                    <option value="персонаж">Персонаж</option>
                    <option value="события">События</option>
                    <option value="NPC">NPC</option>
                    <option value="общие">Общие</option>
                </select>
            </div>
            <div class="mb-3">
                <label for="content" class="form-label">Содержимое совета</label>
                <textarea class="form-control" id="content" name="content" rows="6"><?= old('content') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Создать совет</button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

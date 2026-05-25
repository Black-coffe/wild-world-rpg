<?php
/**
 * @var string $mode  'create' | 'edit'
 * @var array<string,mixed>|null $page
 */
$isEdit = ($mode ?? 'create') === 'edit';
$page   = $page ?? [];
$id     = (int) ($page['id'] ?? 0);
$action = $isEdit ? site_url('admin/site/pages/update/' . $id) : site_url('admin/site/pages/store');
$val    = static fn (string $k): string => esc((string) (old($k) ?? ($page[$k] ?? '')));
?>
<?= $this->extend('admin/layouts/default') ?>
<?= $this->section('content') ?>

<div class="container-fluid mt-3">
<h2><?= $isEdit ? 'Редактирование страницы' : 'Новая страница' ?></h2>

<?php if (session()->getFlashdata('errors')): ?>
    <div class="alert alert-danger"><?php foreach ((array) session()->getFlashdata('errors') as $e): ?><?= esc($e) ?><br><?php endforeach; ?></div>
<?php endif; ?>

<form action="<?= $action ?>" method="post">
    <?= csrf_field() ?>
    <div class="card"><div class="card-body">
        <div class="mb-3">
            <label class="form-label">Заголовок</label>
            <input type="text" name="title" class="form-control" value="<?= $val('title') ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Slug <small class="text-muted">(пусто = из заголовка)</small></label>
            <input type="text" name="slug" class="form-control" value="<?= $val('slug') ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">Содержимое (HTML)</label>
            <textarea name="content_html" class="form-control" rows="16" style="font-family:ui-monospace,monospace"><?= esc((string) (old('content_html') ?? ($page['content_html'] ?? ''))) ?></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label">Meta description (SEO)</label>
            <textarea name="meta_description" class="form-control" rows="2" maxlength="320"><?= $val('meta_description') ?></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label">Статус</label>
            <select name="status" class="form-select" style="max-width:240px">
                <?php $st = (string) (old('status') ?? ($page['status'] ?? 'draft')); ?>
                <option value="draft" <?= $st === 'draft' ? 'selected' : '' ?>>Черновик</option>
                <option value="published" <?= $st === 'published' ? 'selected' : '' ?>>Опубликована</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Сохранить' : 'Создать' ?></button>
        <a href="<?= site_url('admin/site/pages') ?>" class="btn btn-link">Отмена</a>
    </div></div>
</form>
</div>

<?= $this->endSection() ?>

<?php
/**
 * @var string $mode  'create' | 'edit'
 * @var array<string,mixed>|null $post
 * @var list<array<string,mixed>> $allCategories
 * @var list<int> $selectedCats
 */
$isEdit = ($mode ?? 'create') === 'edit';
$post   = $post ?? [];
$id     = (int) ($post['id'] ?? 0);
$action = $isEdit ? site_url('admin/site/posts/update/' . $id) : site_url('admin/site/posts/store');
$sel    = $selectedCats ?? [];
$val    = static fn (string $k): string => esc((string) (old($k) ?? ($post[$k] ?? '')));
?>
<?= $this->extend('admin/layouts/default') ?>
<?= $this->section('content') ?>

<div class="container-fluid mt-3">
<h2><?= $isEdit ? 'Редактирование поста' : 'Новый пост' ?></h2>

<?php if (session()->getFlashdata('errors')): ?>
    <div class="alert alert-danger"><?php foreach ((array) session()->getFlashdata('errors') as $e): ?><?= esc($e) ?><br><?php endforeach; ?></div>
<?php endif; ?>

<form action="<?= $action ?>" method="post">
    <?= csrf_field() ?>
    <div class="row">
        <div class="col-lg-8">
            <div class="card"><div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Заголовок</label>
                    <input type="text" name="title" class="form-control" value="<?= $val('title') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Slug <small class="text-muted">(пусто = из заголовка; меняет URL — осторожно для SEO)</small></label>
                    <input type="text" name="slug" class="form-control" value="<?= $val('slug') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Краткое описание (excerpt)</label>
                    <textarea name="excerpt" class="form-control" rows="2"><?= $val('excerpt') ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Содержимое (HTML)</label>
                    <textarea name="content_html" class="form-control" rows="18" style="font-family:ui-monospace,monospace"><?= esc((string) (old('content_html') ?? ($post['content_html'] ?? ''))) ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Meta description (SEO, до 320)</label>
                    <textarea name="meta_description" class="form-control" rows="2" maxlength="320"><?= $val('meta_description') ?></textarea>
                </div>
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="card"><div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Статус</label>
                    <select name="status" class="form-select">
                        <?php $st = (string) (old('status') ?? ($post['status'] ?? 'draft')); ?>
                        <option value="draft" <?= $st === 'draft' ? 'selected' : '' ?>>Черновик</option>
                        <option value="published" <?= $st === 'published' ? 'selected' : '' ?>>Опубликован</option>
                    </select>
                </div>
                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="canon" name="canon_reviewed" value="1" <?= (int) ($post['canon_reviewed'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="canon">Сверено с каноном (ADR-010)</label>
                </div>
                <div class="mb-3">
                    <label class="form-label">Дата публикации</label>
                    <input type="text" name="published_at" class="form-control" placeholder="ГГГГ-ММ-ДД ЧЧ:ММ:СС" value="<?= esc((string) (old('published_at') ?? ($post['published_at'] ?? ''))) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Картинка (путь)</label>
                    <input type="text" name="featured_image" class="form-control" value="<?= $val('featured_image') ?>" placeholder="uploads/site/...">
                </div>
                <div class="mb-3">
                    <label class="form-label">Категории</label>
                    <select name="categories[]" class="form-select" multiple size="8">
                        <?php foreach ($allCategories as $c): ?>
                            <?php $cid = (int) ($c['id'] ?? 0); ?>
                            <option value="<?= $cid ?>" <?= in_array($cid, $sel, true) ? 'selected' : '' ?>><?= esc($c['name'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary w-100"><?= $isEdit ? 'Сохранить' : 'Создать' ?></button>
                <a href="<?= site_url('admin/site/posts') ?>" class="btn btn-link w-100">Отмена</a>
            </div></div>
        </div>
    </div>
</form>
</div>

<?= $this->endSection() ?>

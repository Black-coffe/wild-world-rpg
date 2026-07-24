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
<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Контент сайта<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Контент сайта</p>
        <h1 class="aui-display"><?= $isEdit ? 'Редактирование страницы' : 'Новая страница' ?></h1>
    </div>
    <a class="aui-btn aui-btn--ghost" href="<?= site_url('admin/site/pages') ?>"><i class="ri-arrow-left-line"></i> К списку</a>
</div>

<div class="aui-card"><div class="aui-card__body">
    <?php if (session()->getFlashdata('errors')): ?>
        <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div>
            <?php foreach ((array) session()->getFlashdata('errors') as $e): ?><?= esc($e) ?><br><?php endforeach; ?>
        </div></div>
    <?php endif; ?>

    <form action="<?= $action ?>" method="post">
        <?= csrf_field() ?>
        <div class="aui-field">
            <label class="aui-label">Заголовок <span class="req">*</span></label>
            <input type="text" name="title" class="aui-input" value="<?= $val('title') ?>" required>
        </div>
        <div class="aui-field">
            <label class="aui-label">Короткий заголовок для выдачи (SEO)</label>
            <input type="text" name="seo_title" class="aui-input" maxlength="190" value="<?= $val('seo_title') ?>">
            <div class="aui-hint">
                Пусто = берётся обычный заголовок. Заполняйте, если тот длиннее ~50 символов:
                поисковик обрезает <code>&lt;title&gt;</code> примерно на 65. Заголовок на самой
                странице (H1) не меняется.
            </div>
        </div>
        <div class="aui-field">
            <label class="aui-label">Slug</label>
            <input type="text" name="slug" class="aui-input" value="<?= $val('slug') ?>">
            <div class="aui-hint">(пусто = из заголовка)</div>
        </div>
        <div class="aui-field">
            <label class="aui-label">Содержимое (HTML)</label>
            <textarea name="content_html" class="aui-textarea aui-input--mono" rows="16"><?= esc((string) (old('content_html') ?? ($page['content_html'] ?? ''))) ?></textarea>
        </div>
        <div class="aui-field">
            <label class="aui-label">Meta description (SEO)</label>
            <textarea name="meta_description" class="aui-textarea" rows="2" maxlength="320"><?= $val('meta_description') ?></textarea>
            <div class="aui-hint">
                Целься в <b>150–160 символов</b>: в выдачу уйдут первые ~160, остальное сайт
                обрежет сам — по концу предложения, иначе по границе слова с многоточием.
            </div>
        </div>
        <div class="aui-field">
            <label class="aui-label">Статус</label>
            <select name="status" class="aui-select" style="max-width:240px">
                <?php $st = (string) (old('status') ?? ($page['status'] ?? 'draft')); ?>
                <option value="draft" <?= $st === 'draft' ? 'selected' : '' ?>>Черновик</option>
                <option value="published" <?= $st === 'published' ? 'selected' : '' ?>>Опубликована</option>
            </select>
        </div>
        <div class="aui-form-actions">
            <button type="submit" class="aui-btn aui-btn--primary"><i class="ri-save-line"></i> <?= $isEdit ? 'Сохранить' : 'Создать' ?></button>
            <a href="<?= site_url('admin/site/pages') ?>" class="aui-btn aui-btn--ghost">Отмена</a>
        </div>
    </form>
</div></div>

<?= $this->endSection() ?>

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
<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Контент сайта<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Контент сайта</p>
        <h1 class="aui-display"><?= $isEdit ? 'Редактирование поста' : 'Новый пост' ?></h1>
    </div>
    <a class="aui-btn aui-btn--ghost" href="<?= site_url('admin/site/posts') ?>"><i class="ri-arrow-left-line"></i> К списку</a>
</div>

<?php if (session()->getFlashdata('errors')): ?>
    <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div>
        <?php foreach ((array) session()->getFlashdata('errors') as $e): ?><?= esc($e) ?><br><?php endforeach; ?>
    </div></div>
<?php endif; ?>

<form action="<?= $action ?>" method="post">
    <?= csrf_field() ?>
    <div class="aui-grid aui-grid--2-1">
        <div class="aui-card"><div class="aui-card__body">
            <div class="aui-field">
                <label class="aui-label">Заголовок <span class="req">*</span></label>
                <input type="text" name="title" class="aui-input" value="<?= $val('title') ?>" required>
            </div>
            <div class="aui-field">
                <label class="aui-label">Короткий заголовок для выдачи (SEO)</label>
                <input type="text" name="seo_title" class="aui-input" maxlength="190" value="<?= $val('seo_title') ?>">
                <div class="aui-hint">
                    Пусто = берётся обычный заголовок. Заполняйте, если тот длиннее ~50 символов:
                    поисковик обрезает <code>&lt;title&gt;</code> примерно на 65, а к нему ещё
                    добавляется «— Wild World» (и не добавляется, если не влезает).
                    Заголовок на самой странице (H1) при этом не меняется.
                </div>
            </div>
            <div class="aui-field">
                <label class="aui-label">Slug</label>
                <input type="text" name="slug" class="aui-input" value="<?= $val('slug') ?>">
                <div class="aui-hint">(пусто = из заголовка; меняет URL — осторожно для SEO)</div>
            </div>
            <div class="aui-field">
                <label class="aui-label">Краткое описание (excerpt)</label>
                <textarea name="excerpt" class="aui-textarea" rows="2"><?= $val('excerpt') ?></textarea>
            </div>
            <div class="aui-field">
                <label class="aui-label">Содержимое (HTML)</label>
                <textarea name="content_html" class="aui-textarea aui-input--mono" rows="18"><?= esc((string) (old('content_html') ?? ($post['content_html'] ?? ''))) ?></textarea>
            </div>
            <div class="aui-field">
                <label class="aui-label">Meta description (SEO)</label>
                <textarea name="meta_description" class="aui-textarea" rows="2" maxlength="320"><?= $val('meta_description') ?></textarea>
                <div class="aui-hint">
                    Целься в <b>150–160 символов</b>. Поле принимает до 320, но в выдачу уйдут
                    первые ~160: длинное описание сайт обрежет сам — по концу предложения,
                    иначе по границе слова с многоточием.
                </div>
            </div>
        </div></div>
        <div class="aui-card"><div class="aui-card__body">
            <div class="aui-field">
                <label class="aui-label">Статус</label>
                <select name="status" class="aui-select">
                    <?php $st = (string) (old('status') ?? ($post['status'] ?? 'draft')); ?>
                    <option value="draft" <?= $st === 'draft' ? 'selected' : '' ?>>Черновик</option>
                    <option value="published" <?= $st === 'published' ? 'selected' : '' ?>>Опубликован</option>
                </select>
            </div>
            <div class="aui-field">
                <div class="aui-checkrow">
                    <label class="aui-check">
                        <input type="checkbox" id="canon" name="canon_reviewed" value="1" <?= (int) ($post['canon_reviewed'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <span class="aui-check__box"></span> Сверено с каноном (ADR-010)
                    </label>
                </div>
            </div>
            <div class="aui-field">
                <label class="aui-label">Дата публикации</label>
                <input type="text" name="published_at" class="aui-input" placeholder="ГГГГ-ММ-ДД ЧЧ:ММ:СС" value="<?= esc((string) (old('published_at') ?? ($post['published_at'] ?? ''))) ?>">
            </div>
            <div class="aui-field">
                <label class="aui-label">Картинка (путь)</label>
                <input type="text" name="featured_image" class="aui-input" value="<?= $val('featured_image') ?>" placeholder="uploads/site/...">
            </div>
            <div class="aui-field">
                <label class="aui-label">Категории</label>
                <select name="categories[]" class="aui-select" multiple size="8">
                    <?php foreach ($allCategories as $c): ?>
                        <?php $cid = (int) ($c['id'] ?? 0); ?>
                        <option value="<?= $cid ?>" <?= in_array($cid, $sel, true) ? 'selected' : '' ?>><?= esc($c['name'] ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="aui-form-actions">
                <button type="submit" class="aui-btn aui-btn--primary"><i class="ri-save-line"></i> <?= $isEdit ? 'Сохранить' : 'Создать' ?></button>
                <a href="<?= site_url('admin/site/posts') ?>" class="aui-btn aui-btn--ghost">Отмена</a>
            </div>
        </div></div>
    </div>
</form>

<?= $this->endSection() ?>

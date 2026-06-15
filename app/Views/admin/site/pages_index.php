<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Контент сайта — страницы<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Контент сайта</p>
        <h1 class="aui-display">Страницы</h1>
    </div>
    <div class="aui-toolbar">
        <input class="aui-input" type="search" placeholder="Поиск страницы…" data-table-search="#tbl-pages">
        <a class="aui-btn aui-btn--primary" href="<?= site_url('admin/site/pages/create') ?>"><i class="ri-add-line"></i> Создать</a>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="aui-alert aui-alert--success" style="margin-bottom:var(--sp-4)"><i class="ri-checkbox-circle-line"></i><div><?= esc(session()->getFlashdata('success')) ?></div></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div><?= esc(session()->getFlashdata('error')) ?></div></div>
<?php endif; ?>

<div class="aui-card"><div class="aui-tablewrap">
    <table class="aui-table" id="tbl-pages" data-enhance>
        <thead><tr>
            <th data-sort>Заголовок</th><th data-sort>Slug</th><th data-sort>Статус</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($pages as $p): ?>
            <tr>
                <td class="strong"><?= esc($p['title'] ?? '') ?></td>
                <td><code class="aui-mono"><?= esc($p['slug'] ?? '') ?></code></td>
                <td><?= ($p['status'] ?? '') === 'published' ? '<span class="aui-badge aui-badge--success">опубликована</span>' : '<span class="aui-badge">черновик</span>' ?></td>
                <td>
                    <div class="aui-actions">
                        <a class="aui-btn aui-btn--ghost aui-btn--icon" href="<?= site_url('admin/site/pages/edit/' . (int) ($p['id'] ?? 0)) ?>" title="Редактировать"><i class="ri-pencil-line"></i></a>
                        <a class="aui-btn aui-btn--ghost aui-btn--icon" href="<?= base_url(esc($p['slug'] ?? '', 'url')) ?>" target="_blank" rel="noopener" title="Открыть на сайте"><i class="ri-external-link-line"></i></a>
                        <form action="<?= site_url('admin/site/pages/delete/' . (int) ($p['id'] ?? 0)) ?>" method="post" onsubmit="return confirm('Удалить страницу?');"><?= csrf_field() ?><button class="aui-btn aui-btn--danger aui-btn--icon" title="Удалить"><i class="ri-delete-bin-line"></i></button></form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($pages)): ?><tr><td colspan="4" class="aui-table__empty">Страниц нет</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?= $this->endSection() ?>

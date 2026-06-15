<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Контент сайта — посты<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Контент сайта</p>
        <h1 class="aui-display">Посты</h1>
    </div>
    <div class="aui-toolbar">
        <input class="aui-input" type="search" placeholder="Поиск поста…" data-table-search="#tbl-posts">
        <div class="aui-pills">
            <a class="aui-pill <?= ($review ?? 'all') === 'all' ? 'is-active' : '' ?>" href="<?= site_url('admin/site/posts') ?>">Все (<?= (int) ($totalCount ?? 0) ?>)</a>
            <a class="aui-pill <?= ($review ?? 'all') === 'pending' ? 'is-active' : '' ?>" href="<?= site_url('admin/site/posts?review=pending') ?>">Не сверено (<?= (int) ($pendingCount ?? 0) ?>)</a>
        </div>
        <a class="aui-btn aui-btn--primary" href="<?= site_url('admin/site/posts/create') ?>"><i class="ri-add-line"></i> Создать</a>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="aui-alert aui-alert--success" style="margin-bottom:var(--sp-4)"><i class="ri-checkbox-circle-line"></i><div><?= esc(session()->getFlashdata('success')) ?></div></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div><?= esc(session()->getFlashdata('error')) ?></div></div>
<?php endif; ?>

<div class="aui-card"><div class="aui-tablewrap">
    <table class="aui-table" id="tbl-posts" data-enhance>
        <thead><tr>
            <th data-sort>Заголовок</th><th data-sort>Slug</th><th data-sort>Статус</th><th data-sort>Канон</th><th data-sort>Дата</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($posts as $p): ?>
            <?php $reviewed = (int) ($p['canon_reviewed'] ?? 0) === 1; ?>
            <tr>
                <td class="strong"><?= esc($p['title'] ?? '') ?></td>
                <td><code class="aui-mono"><?= esc($p['slug'] ?? '') ?></code></td>
                <td><?= ($p['status'] ?? '') === 'published' ? '<span class="aui-badge aui-badge--success">опубликован</span>' : '<span class="aui-badge">черновик</span>' ?></td>
                <td><?= $reviewed ? '<span class="aui-badge aui-badge--success">канон ✓</span>' : '<span class="aui-badge aui-badge--danger">не сверено</span>' ?></td>
                <td class="aui-muted"><?= esc($p['published_at'] ? date('d.m.Y', (int) strtotime((string) $p['published_at'])) : '') ?></td>
                <td>
                    <div class="aui-actions">
                        <a class="aui-btn aui-btn--ghost aui-btn--icon" href="<?= site_url('admin/site/posts/edit/' . (int) ($p['id'] ?? 0)) ?>" title="Редактировать"><i class="ri-pencil-line"></i></a>
                        <?php if (! $reviewed): ?>
                            <form action="<?= site_url('admin/site/posts/review/' . (int) ($p['id'] ?? 0)) ?>" method="post"><?= csrf_field() ?><button class="aui-btn aui-btn--ghost aui-btn--icon" style="color:var(--success)" title="Отметить сверённым с каноном"><i class="ri-checkbox-circle-line"></i></button></form>
                        <?php endif; ?>
                        <a class="aui-btn aui-btn--ghost aui-btn--icon" href="<?= base_url(esc($p['slug'] ?? '', 'url')) ?>" target="_blank" rel="noopener" title="Открыть на сайте"><i class="ri-external-link-line"></i></a>
                        <form action="<?= site_url('admin/site/posts/delete/' . (int) ($p['id'] ?? 0)) ?>" method="post" onsubmit="return confirm('Удалить пост?');"><?= csrf_field() ?><button class="aui-btn aui-btn--danger aui-btn--icon" title="Удалить"><i class="ri-delete-bin-line"></i></button></form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($posts)): ?><tr><td colspan="6" class="aui-table__empty">Постов нет</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?= $this->endSection() ?>

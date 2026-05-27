<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<div class="container-fluid mt-3">
<h2>Контент сайта — посты</h2>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success" role="alert"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-danger" role="alert"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>

<div class="row mb-2">
    <div class="col-sm-6">
        <a href="<?= site_url('admin/site/posts/create') ?>" class="btn btn-primary rounded-pill mb-2"><i class="mdi mdi-plus"></i> Новый пост</a>
    </div>
    <div class="col-sm-6 text-sm-end">
        <div class="btn-group mb-2" role="group">
            <a href="<?= site_url('admin/site/posts') ?>" class="btn btn-sm <?= ($review ?? 'all') === 'all' ? 'btn-secondary' : 'btn-outline-secondary' ?>">Все (<?= (int) ($totalCount ?? 0) ?>)</a>
            <a href="<?= site_url('admin/site/posts?review=pending') ?>" class="btn btn-sm <?= ($review ?? 'all') === 'pending' ? 'btn-danger' : 'btn-outline-danger' ?>">Не сверено с каноном (<?= (int) ($pendingCount ?? 0) ?>)</a>
        </div>
    </div>
</div>

<table id="selection-datatable" class="table dt-responsive nowrap w-100">
    <thead>
    <tr>
        <th>Заголовок</th>
        <th>Slug</th>
        <th>Статус</th>
        <th>Канон</th>
        <th>Дата</th>
        <th>Действия</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($posts as $p): ?>
        <?php $reviewed = (int) ($p['canon_reviewed'] ?? 0) === 1; ?>
        <tr>
            <td><?= esc($p['title'] ?? '') ?></td>
            <td><code><?= esc($p['slug'] ?? '') ?></code></td>
            <td>
                <?php if (($p['status'] ?? '') === 'published'): ?>
                    <span class="badge bg-success">опубликован</span>
                <?php else: ?>
                    <span class="badge bg-secondary">черновик</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($reviewed): ?>
                    <span class="badge bg-success">Канон ✓</span>
                <?php else: ?>
                    <span class="badge bg-danger">Не сверено</span>
                <?php endif; ?>
            </td>
            <td><?= esc($p['published_at'] ? date('d.m.Y', (int) strtotime((string) $p['published_at'])) : '') ?></td>
            <td class="text-nowrap">
                <a href="<?= site_url('admin/site/posts/edit/' . (int) ($p['id'] ?? 0)) ?>" class="action-icon" title="Редактировать"><i class="mdi mdi-pencil"></i></a>
                <?php if (! $reviewed): ?>
                    <form action="<?= site_url('admin/site/posts/review/' . (int) ($p['id'] ?? 0)) ?>" method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <button type="submit" class="action-icon text-success" style="background:none;border:0;padding:0;cursor:pointer" title="Отметить сверённым с каноном"><i class="mdi mdi-check-decagram"></i></button>
                    </form>
                <?php endif; ?>
                <a href="<?= base_url(esc($p['slug'] ?? '', 'url')) ?>" target="_blank" class="action-icon" title="Открыть на сайте"><i class="mdi mdi-open-in-new"></i></a>
                <form action="<?= site_url('admin/site/posts/delete/' . (int) ($p['id'] ?? 0)) ?>" method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="action-icon text-danger" style="background:none;border:0;padding:0;cursor:pointer" title="Удалить" onclick="return confirm('Удалить пост?');"><i class="mdi mdi-delete"></i></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?= $this->endSection() ?>

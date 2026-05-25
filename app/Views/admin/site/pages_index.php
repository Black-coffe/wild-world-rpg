<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<div class="container-fluid mt-3">
<h2>Контент сайта — страницы</h2>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-danger"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>

<a href="<?= site_url('admin/site/pages/create') ?>" class="btn btn-primary rounded-pill mb-3"><i class="mdi mdi-plus"></i> Новая страница</a>

<table id="selection-datatable" class="table dt-responsive nowrap w-100">
    <thead><tr><th>Заголовок</th><th>Slug</th><th>Статус</th><th>Действия</th></tr></thead>
    <tbody>
    <?php foreach ($pages as $p): ?>
        <tr>
            <td><?= esc($p['title'] ?? '') ?></td>
            <td><code><?= esc($p['slug'] ?? '') ?></code></td>
            <td>
                <?php if (($p['status'] ?? '') === 'published'): ?>
                    <span class="badge bg-success">опубликована</span>
                <?php else: ?>
                    <span class="badge bg-secondary">черновик</span>
                <?php endif; ?>
            </td>
            <td class="text-nowrap">
                <a href="<?= site_url('admin/site/pages/edit/' . (int) ($p['id'] ?? 0)) ?>" class="action-icon"><i class="mdi mdi-pencil"></i></a>
                <a href="<?= base_url(esc($p['slug'] ?? '', 'url')) ?>" target="_blank" class="action-icon"><i class="mdi mdi-open-in-new"></i></a>
                <a href="<?= site_url('admin/site/pages/delete/' . (int) ($p['id'] ?? 0)) ?>" class="action-icon text-danger" onclick="return confirm('Удалить страницу?');"><i class="mdi mdi-delete"></i></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?= $this->endSection() ?>

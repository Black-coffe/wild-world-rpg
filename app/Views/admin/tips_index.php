<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Список советов</h2>

<!-- Display flash messages for successful actions -->
<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success" role="alert">
        <?= session()->getFlashdata('success') ?>
    </div>
<?php endif; ?>
<div class="row mb-2">
    <div class="col-sm-4">
        <a href="<?= site_url('admin/tips/create') ?>" class="btn btn-primary rounded-pill mb-3"><i class="mdi mdi-plus"></i> Добавить совет</a>
    </div>
    <div class="col-sm-8">
        <!-- Space for additional control elements if needed -->
    </div><!-- end col-->
</div>
<table id="selection-datatable" class="table dt-responsive nowrap w-100">
    <thead>
    <tr>
        <th>Название (RU)</th>
        <th>Название (EN)</th>
        <th>Тип</th>
        <th>Содержание</th>
        <th>Действия</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($tips as $tip): ?>
        <tr>
            <td><?= esc($tip['title_ru']) ?></td>
            <td><?= esc($tip['title_en']) ?></td>
            <td><?= esc($tip['tip_type']) ?></td>
            <td><?= esc(substr($tip['content'], 0, 110)) ?>...</td> <!-- Show a snippet -->
            <td>
                <a href="<?= site_url('admin/tips/edit/' . $tip['id']) ?>" class="action-icon"> <i class="mdi mdi-pencil"></i></a>
                <a href="<?= site_url('admin/tips/delete/' . $tip['id']) ?>" class="action-icon" onclick="return confirm('Вы уверены, что хотите удалить этот совет?');"> <i class="mdi mdi-delete"></i></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?= $this->endSection() ?>

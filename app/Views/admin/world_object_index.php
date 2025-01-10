<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Список объектов в игровом мире</h2>

<!-- Отображение флеш-сообщения о успешных действиях -->
<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success" role="alert">
        <?= session()->getFlashdata('success') ?>
    </div>
<?php endif; ?>
<div class="row mb-2">
    <div class="col-sm-4">
        <a href="<?= site_url('admin/world-objects/create') ?>" class="btn btn-danger rounded-pill mb-3"><i class="mdi mdi-plus"></i> Добавить объект</a>
    </div>
    <div class="col-sm-8">
        <!-- Additional control elements can go here -->
    </div><!-- end col-->
</div>
<table id="selection-datatable" class="table dt-responsive nowrap w-100">
    <thead>
    <tr>
        <th>Название</th>
        <th>Название (англ.)</th>
        <th>Биом</th>
        <th>Макс. количество</th>
        <th>Время респауна</th>
        <th>Статус</th>
        <th>Действия</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($objects as $object): ?>
        <tr>
            <td><?= esc($object['name']) ?></td>
            <td><?= esc($object['name_en']) ?></td>
            <td><?= esc($object['biome_name']) ?></td>
            <td><?= esc($object['max_count']) ?></td>
            <td><?= esc($object['respawn_time']) ?> час(ов)</td>
            <td><?= $object['status'] === 'active' ? 'Активный' : 'Неактивный' ?></td>
            <td>
                <a href="<?= site_url('admin/world-objects/edit/' . $object['id']) ?>" class="action-icon"> <i class="mdi mdi-pencil"></i></a>
                <a href="<?= site_url('admin/world-objects/delete/' . $object['id']) ?>" class="action-icon" onclick="return confirm('Вы уверены, что хотите удалить этот объект?');"> <i class="mdi mdi-delete"></i></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?= $this->endSection() ?>

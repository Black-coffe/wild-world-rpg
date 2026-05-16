<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Список ресурсов</h2>

<!-- Отображение флеш-сообщения о успешных действиях -->
<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success" role="alert">
        <?= session()->getFlashdata('success') ?>
    </div>
<?php endif; ?>
<div class="row mb-2">
    <div class="col-sm-4">
        <a href="<?= site_url('admin/resources/create') ?>" class="btn btn-danger rounded-pill mb-3"><i class="mdi mdi-plus"></i> Create resources</a>
    </div>
    <div class="col-sm-8">

    </div><!-- end col-->
</div>
<table id="selection-datatable" class="table dt-responsive w-100 table-centered mb-0">
    <thead>
    <tr>
        <th>Название</th>
        <th>Название</th>
        <th>Иконка</th>
        <th>Тип</th>
        <th>Биом id</th>
        <th>Цена</th>
        <th>Редкость</th>
        <th>Для торговца</th>
        <th>Мин. уровень</th>
        <th>Личное/Публичное</th>
        <th>Действия</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($resources as $resource): ?>
        <tr>
            <td><?= esc($resource['name']) ?></td>
            <td><?= esc($resource['name_en']) ?></td>
            <td class="admin-resource-emoji"><?=$resource['icon_text'] ?></td>
            <td><?= esc($resource['translated_types']) ?></td>
            <td><?= esc($resource['biome_names']) ?></td>
            <td><?= esc($resource['price']) ?></td>
            <td><?= esc($resource['rarity']) ?></td>
            <td><?= esc($resource['initial_quantity']) ?></td>
            <td><?= esc($resource['level_required']) ?></td>
            <td><?= $resource['is_tradeable'] ? 'Да' : 'Нет' ?></td>
            <td>
                <a href="<?= site_url('admin/resources/edit/' . $resource['id']) ?>" class="action-icon"> <i class="mdi mdi-pencil"></i></a>
                <!-- Добавьте здесь ссылку для удаления ресурса -->
                <a href="<?= site_url('admin/resources/delete/' . $resource['id']) ?>" class="action-icon" onclick="return confirm('Вы уверены, что хотите удалить этот ресурс?');"> <i class="mdi mdi-delete"></i></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?= $this->endSection() ?>

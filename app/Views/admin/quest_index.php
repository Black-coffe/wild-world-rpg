<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Список квестов</h2>

<!-- Отображение флеш-сообщения о успешных действиях -->
<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success" role="alert">
        <?= session()->getFlashdata('success') ?>
    </div>
<?php endif; ?>
<div class="row mb-2">
    <div class="col-sm-4">
        <a href="<?= site_url('admin/quests/create') ?>" class="btn btn-danger rounded-pill mb-3"><i class="mdi mdi-plus"></i> Добавить квест</a>
    </div>
    <div class="col-sm-8">
        <!-- Дополнительные элементы управления могут находиться здесь -->
    </div><!-- end col-->
</div>
<table id="selection-datatable" class="table dt-responsive nowrap w-100">
    <thead>
    <tr>
        <th>Название</th>
        <th>Название (англ.)</th>
        <th>Статус</th>
        <th>Описание</th>
        <th>Награда</th>
        <th>Тип награды</th>
        <th>Действия</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($quests as $quest): ?>
        <tr>
            <td><?= esc($quest['title_ru']) ?></td>
            <td><?= esc($quest['title_en']) ?></td>
            <td><?= esc($quest['status']) ?></td>
            <td><?= esc(substr($quest['description'], 0, 100)) ?>...</td>
            <td><?= esc($quest['reward']) ?></td>
            <td><?= esc($quest['reward_type']) ?></td>
            <td>
                <a href="<?= site_url('admin/quests/edit/' . $quest['id']) ?>" class="action-icon"> <i class="mdi mdi-pencil"></i></a>
                <form action="<?= site_url('admin/quests/delete/' . $quest['id']) ?>" method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="action-icon" style="background:none;border:0;padding:0;cursor:pointer" onclick="return confirm('Вы уверены, что хотите удалить этот квест?');" title="Удалить"><i class="mdi mdi-delete"></i></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?= $this->endSection() ?>

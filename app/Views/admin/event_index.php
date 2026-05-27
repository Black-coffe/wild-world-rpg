<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Список событий</h2>

<!-- Отображение флеш-сообщения о успешных действиях -->
<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success" role="alert">
        <?= session()->getFlashdata('success') ?>
    </div>
<?php endif; ?>
<div class="row mb-2">
    <div class="col-sm-4">
        <a href="<?= site_url('admin/events/create') ?>" class="btn btn-primary rounded-pill mb-3"><i class="mdi mdi-plus"></i> Добавить событие</a>
    </div>
    <div class="col-sm-8">
        <!-- Место для дополнительных элементов управления, если необходимо -->
    </div><!-- end col-->
</div>
<table id="selection-datatable" class="table dt-responsive nowrap w-100">
    <thead>
    <tr>
        <th>Название</th>
        <th>English</th>
        <th>Тип события</th>
        <th>Биомы</th>
        <th>Продолжительность</th>
        <th>Тип эффекта</th>
        <th>Значение эффекта</th>
        <th>Действия</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($events as $event): ?>
        <tr>
            <td><?= esc($event['name']) ?></td>
            <td><?= esc($event['name_english']) ?></td>
            <td><?= esc($event['event_type']) ?></td>
            <td><?= esc($event['biome_ids']) // Возможно, потребуется обработка для вывода имен биомов ?></td>
            <td><?= esc($event['duration']) ?> минут</td>
            <td><?= esc($event['effect_type']) ?></td>
            <td><?= esc($event['effect_value']) ?></td>
            <td>
                <a href="<?= site_url('admin/events/edit/' . $event['event_id']) ?>" class="action-icon"> <i class="mdi mdi-pencil"></i></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?= $this->endSection() ?>

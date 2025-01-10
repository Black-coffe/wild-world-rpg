<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Список задач</h2>

<!-- Отображение флеш-сообщения о успешных действиях -->
<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success" role="alert">
        <?= session()->getFlashdata('success') ?>
    </div>
<?php endif; ?>
<div class="row mb-2">
    <div class="col-sm-4">
        <a href="<?= site_url('admin/tasks/create') ?>" class="btn btn-primary rounded-pill mb-3"><i class="mdi mdi-plus"></i> Создать задачу</a>
    </div>
</div>
<table class="table table-striped table-centered mb-0">
    <thead>
    <tr>
        <th>Название</th>
        <th>Описание</th>
        <th>Минимальное время</th>
        <th>Максимальное время</th>
        <th>Тип</th>
        <th>Уровень сложности</th>
        <th>Ограничение выполнения</th>
        <th>Параллельное выполнение</th>
        <th>Прерываемая</th>
        <th>Действия</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($tasks as $task): ?>
        <tr>
            <td><?= esc($task['name']) ?></td>
            <td><?= esc($task['description']) ?></td>
            <td><?= esc($task['min_duration']) ?> мин.</td>
            <td><?= esc($task['max_duration']) ?> мин.</td>
            <td><?= esc($task['type']) ?></td>
            <td><?= esc($task['difficulty_level']) ?></td>
            <td><?= $task['execution_limit'] == 0 ? 'Без ограничений' : esc($task['execution_limit']) ?></td>
            <td><?= $task['parallel_execution_allowed'] ? 'Да' : 'Нет' ?></td>
            <td><?= $task['interruptible'] ? 'Да' : 'Нет' ?></td>
            <td>
                <a href="<?= site_url('admin/tasks/edit/' . $task['id']) ?>" class="action-icon"> <i class="mdi mdi-pencil"></i></a>
                <a href="<?= site_url('admin/tasks/delete/' . $task['id']) ?>" class="action-icon" onclick="return confirm('Вы уверены, что хотите удалить эту задачу?');"> <i class="mdi mdi-delete"></i></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?= $this->endSection() ?>

<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>
<div class="container">
    <h2>Генерация игрового мира</h2>

    <!-- Отображение флеш-сообщения о успешном обновлении -->
    <?php if (session()->getFlashdata('success')): ?>
        <div class="alert alert-success" role="alert">
            <?= session()->getFlashdata('success') ?>
        </div>
    <?php endif; ?>


    <table class="table table-striped">
        <thead>
        <tr>
            <th>#</th>
            <th>Название генерации</th>
            <th>Статус</th>
            <th>Действие</th>
            <th>Дата действия</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($statusLog as $status): ?>
            <tr>
                <td><?= $status['id'] ?></td>
                <td><?= $status['action_name'] ?></td>
                <td><?= $status['action_status'] ?></td>
                <td>
                    <?php if ($status['action_status'] === 'None'): ?>
                        <a href="<?= base_url('admin/generate/').$status['action_name'] ?>" type="button" class="btn btn-secondary">Генерировать</a>
                    <?php endif; ?>
                </td>
                <td><?= $status['created_at'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?= $this->endSection() ?>

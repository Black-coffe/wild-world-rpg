<?= $this->extend('templates/admin') ?>

<?= $this->section('content') ?>
<div class="container-fluid">
    <h1 class="mt-4">Список опросов</h1>
    <?php if (session()->getFlashdata('message')): ?>
        <div class="alert alert-success"><?= session()->getFlashdata('message') ?></div>
    <?php endif; ?>
    <?php if (session()->getFlashdata('error')): ?>
        <div class="alert alert-danger"><?= session()->getFlashdata('error') ?></div>
    <?php endif; ?>

    <div class="mb-3">
        <a href="<?= site_url('admin/polls/create') ?>" class="btn btn-primary">Создать новый опрос</a>
    </div>

    <table class="table table-striped">
        <thead>
        <tr>
            <th>ID</th>
            <th>Вопрос</th>
            <th>Создан</th>
            <th>Статус</th>
            <th>Действия</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!empty($polls) && is_array($polls)): ?>
            <?php foreach ($polls as $poll): ?>
                <tr>
                    <td><?= esc($poll['id']) ?></td>
                    <td><?= esc(character_limiter(strip_tags($poll['question']), 80)) ?></td>
                    <td><?= esc($poll['created_at']) ?></td>
                    <td>
                        <?php if ($poll['active'] == 1): ?>
                            <span class="badge bg-success">Отправлен</span>
                        <?php else: ?>
                            <span class="badge bg-warning">Черновик</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($poll['active'] == 0): ?>
                            <!-- Если опрос не активен — кнопки "Запустить" и "Удалить" через POST-формы (защита от случайного клика по URL) -->
                            <form action="<?= site_url('admin/polls/send/' . $poll['id']) ?>" method="post" style="display:inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-success"
                                        onclick="return confirm('Отправить опрос всем игрокам?')">
                                    Запустить опрос
                                </button>
                            </form>
                            <a href="<?= site_url('admin/polls/edit/' . $poll['id']) ?>" class="btn btn-sm btn-info">Редактировать</a>
                            <form action="<?= site_url('admin/polls/delete/' . $poll['id']) ?>" method="post" style="display:inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-danger"
                                        onclick="return confirm('Удалить опрос?')">
                                    Удалить
                                </button>
                            </form>
                        <?php else: ?>
                            <!-- Активный опрос — кнопка "Остановить" через POST -->
                            <form action="<?= site_url('admin/polls/stop/' . $poll['id']) ?>" method="post" style="display:inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-warning"
                                        onclick="return confirm('Остановить опрос?')">
                                    Остановить
                                </button>
                            </form>
                        <?php endif; ?>

                        <!-- Кнопка "Статистика" общая -->
                        <a href="<?= site_url('admin/polls/statistics/' . $poll['id']) ?>" class="btn btn-sm btn-secondary">Статистика</a>

                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="5">Опросы не найдены.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>

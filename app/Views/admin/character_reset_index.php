<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Создать новое событие</h2>

<div class="card">
    <div class="card-body">
        <?php if (session()->getFlashdata('errors')): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach (session()->getFlashdata('errors') as $error): ?>
                    <?= esc($error) ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            <strong>Комментарии к функционалу:</strong><br>
            Это система удаления, полного обнуления игрового персонажа в игре. Чистятся все таблицы, удаление всех строк имеющих отношение к текущему персонажу в игре.<br>
            <strong>ID телеграм пользователя:</strong> Берем в таблице:`telegram_users` поле: `telegram_id`<br>
            <strong>ID персонажа в игре:</strong> Берем в таблице:`characters` поле: `id`<br>
        </div>

        <form action="<?= site_url('admin/character-reset/check') ?>" method="post">
            <?= csrf_field() ?>
            <div class="row">
                <div class="mb-3 col-md-6">
                    <label for="id_telegram" class="form-label">ID телеграм пользователя</label>
                    <input type="number" class="form-control" id="id_telegram" name="id_telegram" required value="">
                </div>
                <div class="mb-3 col-md-6">
                    <label for="id_character" class="form-label">ID персонажа в игре</label>
                    <input type="number" class="form-control" id="id_character" name="characterId" <?= $characterId !== null ? 'required value="' . esc($characterId) . '"' : '' ?>>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Проверить данные</button>
        </form>

        <?php if (isset($affectedRows)): ?>
            <div class="mt-4">
                <h4>Информация о затрагиваемых таблицах:</h4>
                <table class="table">
                    <thead>
                    <tr>
                        <th>Таблица</th>
                        <th>Найдено строк</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($affectedRows as $table => $count): ?>
                        <tr>
                            <td><?= esc($table) ?></td>
                            <td><?= esc($count) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <form action="<?= site_url('admin/character-reset/confirm') ?>" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="characterId" value="<?= esc($characterId) ?>">
                    <input type="hidden" name="telegramId" value="<?= esc($telegramId) ?>">
                    <button type="submit" class="btn btn-danger">Обнулить персонажа</button>
                </form>
            </div>
        <?php endif; ?>
        <form action="<?= site_url('admin/character-reset/reset-all') ?>" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="reset_all" value="yes">
            <button type="submit" class="btn btn-danger">Обнулить все персонажи</button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

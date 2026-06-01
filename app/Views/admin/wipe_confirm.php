<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2 class="text-danger">🔥 Финальное подтверждение вайпа</h2>

<div class="alert alert-danger">
    <strong>Это последний шаг.</strong> После ввода пароля и подтверждения прогресс
    <strong><?= esc((string) $preview['characters']) ?></strong> персонажей будет сброшен,
    <strong class="text-danger"><?= esc((string) $preview['totals']['rows_deleted']) ?></strong> строк удалено.
    Операция необратима. Сразу после вайпа всем <strong><?= esc((string) $preview['players']) ?></strong>
    игрокам уйдёт сообщение ниже.
</div>

<div class="card mb-3">
    <div class="card-header">Сообщение, которое получат игроки</div>
    <div class="card-body">
        <div class="border p-3 bg-light" style="white-space: pre-wrap;"><strong>ℹ️ <?= esc($broadcastTitle) ?> ℹ️</strong>

<?= esc($broadcastMessage) ?></div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form action="<?= site_url('admin/wipe/execute') ?>" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="broadcast_title" value="<?= esc($broadcastTitle) ?>">
            <input type="hidden" name="broadcast_message" value="<?= esc($broadcastMessage) ?>">

            <div class="mb-3 col-md-6">
                <label class="form-label">Пароль администратора (повторный ввод)</label>
                <input type="password" class="form-control" name="admin_password" autocomplete="off" required autofocus>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="confirm" value="yes" id="confirmWipe" required>
                <label class="form-check-label" for="confirmWipe">
                    Я понимаю, что прогресс всех игроков будет необратимо сброшен.
                </label>
            </div>
            <button type="submit" class="btn btn-danger btn-lg">🔥 Подтвердить и вайпнуть</button>
            <a href="<?= site_url('admin/wipe') ?>" class="btn btn-secondary">Отмена</a>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

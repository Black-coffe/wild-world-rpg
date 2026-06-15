<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Подтверждение вайпа<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Опасная зона · финальный шаг</p>
        <h1 class="aui-display" style="color:var(--danger)">🔥 Подтверждение вайпа</h1>
    </div>
</div>

<div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-5)">
    <i class="ri-alert-line"></i>
    <div>
        <div class="aui-alert__title">Это последний шаг</div>
        После ввода пароля и подтверждения прогресс
        <strong><?= esc((string) $preview['characters']) ?></strong> персонажей будет сброшен,
        <strong><?= esc((string) $preview['totals']['rows_deleted']) ?></strong> строк удалено.
        Операция необратима. Сразу после вайпа всем <strong><?= esc((string) $preview['players']) ?></strong>
        игрокам уйдёт сообщение ниже.
    </div>
</div>

<div class="aui-card" style="margin-bottom:var(--sp-4)">
    <div class="aui-card__head"><i class="ri-message-2-line"></i><span class="aui-card__title">Сообщение, которое получат игроки</span></div>
    <div class="aui-card__body">
        <div class="aui-code" style="white-space:pre-wrap; font-family:var(--font-body)"><strong>ℹ️ <?= esc($broadcastTitle) ?> ℹ️</strong>

<?= esc($broadcastMessage) ?></div>
    </div>
</div>

<div class="aui-card">
    <div class="aui-card__body">
        <form action="<?= site_url('admin/wipe/execute') ?>" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="broadcast_title" value="<?= esc($broadcastTitle) ?>">
            <input type="hidden" name="broadcast_message" value="<?= esc($broadcastMessage) ?>">

            <div class="aui-field" style="max-width:420px">
                <label class="aui-label">Пароль администратора (повторный ввод)</label>
                <input type="password" class="aui-input" name="admin_password" autocomplete="off" required autofocus>
            </div>
            <div class="aui-field">
                <label class="aui-check">
                    <input type="checkbox" name="confirm" value="yes" id="confirmWipe" required>
                    <span class="aui-check__box"></span>
                    Я понимаю, что прогресс всех игроков будет необратимо сброшен.
                </label>
            </div>
            <div class="aui-form-actions">
                <button type="submit" class="aui-btn aui-btn--danger-solid aui-btn--lg">🔥 Подтвердить и вайпнуть</button>
                <a href="<?= site_url('admin/wipe') ?>" class="aui-btn aui-btn--ghost">Отмена</a>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

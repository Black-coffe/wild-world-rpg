<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Сброс персонажа<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Опасная зона</p>
        <h1 class="aui-display">Сброс персонажа</h1>
    </div>
</div>

<?php if (session()->getFlashdata('errors')): ?>
    <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div>
        <?php foreach (session()->getFlashdata('errors') as $error): ?><?= esc($error) ?><br><?php endforeach; ?>
    </div></div>
<?php endif; ?>

<div class="aui-alert aui-alert--info" style="margin-bottom:var(--sp-5)">
    <i class="ri-information-line"></i>
    <div>
        <div class="aui-alert__title">Комментарии к функционалу</div>
        Это система удаления, полного обнуления игрового персонажа в игре. Чистятся все таблицы,
        удаление всех строк имеющих отношение к текущему персонажу в игре.<br>
        <strong>ID телеграм пользователя:</strong> таблица <span class="aui-mono">telegram_users</span>, поле <span class="aui-mono">telegram_id</span>.<br>
        <strong>ID персонажа в игре:</strong> таблица <span class="aui-mono">characters</span>, поле <span class="aui-mono">id</span>.
    </div>
</div>

<div class="aui-card" style="margin-bottom:var(--sp-4)">
    <div class="aui-card__head"><i class="ri-search-line"></i><span class="aui-card__title">Проверить персонажа</span></div>
    <div class="aui-card__body">
        <form action="<?= site_url('admin/character-reset/check') ?>" method="post">
            <?= csrf_field() ?>
            <div class="aui-formgrid">
                <div class="aui-field">
                    <label for="id_telegram" class="aui-label">ID телеграм пользователя</label>
                    <input type="number" class="aui-input" id="id_telegram" name="id_telegram" required value="">
                </div>
                <div class="aui-field">
                    <label for="id_character" class="aui-label">ID персонажа в игре</label>
                    <input type="number" class="aui-input" id="id_character" name="characterId" <?= $characterId !== null ? 'required value="' . esc((string) $characterId) . '"' : '' ?>>
                </div>
            </div>
            <button type="submit" class="aui-btn aui-btn--primary">Проверить данные</button>
        </form>

        <?php if (isset($affectedRows)): ?>
            <div style="margin-top:var(--sp-5)">
                <h3 class="aui-h3">Информация о затрагиваемых таблицах</h3>
                <div class="aui-tablewrap">
                <table class="aui-table">
                    <thead><tr><th>Таблица</th><th class="num">Найдено строк</th></tr></thead>
                    <tbody>
                    <?php foreach ($affectedRows as $table => $count): ?>
                        <tr>
                            <td><span class="aui-mono"><?= esc($table) ?></span></td>
                            <td class="num"><?= esc($count) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <form action="<?= site_url('admin/character-reset/confirm') ?>" method="post" style="margin-top:var(--sp-4)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="characterId" value="<?= esc($characterId) ?>">
                    <input type="hidden" name="telegramId" value="<?= esc($telegramId) ?>">
                    <button type="submit" class="aui-btn aui-btn--danger">Обнулить персонажа</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="aui-card aui-card--flat" style="border-color:color-mix(in srgb,var(--danger) 35%,var(--line))">
    <div class="aui-card__body">
        <form action="<?= site_url('admin/character-reset/reset-all') ?>" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="reset_all" value="yes">
            <p class="aui-muted aui-small" style="margin:0 0 var(--sp-3)">🔴 Необратимо обнуляет ВСЕХ персонажей сразу.</p>
            <button type="submit" class="aui-btn aui-btn--danger-solid">Обнулить все персонажи</button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

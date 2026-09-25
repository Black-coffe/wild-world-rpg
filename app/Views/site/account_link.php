<?php
/**
 * web-accounts-p0-06 (ADR-188) — ввод одноразового кода из бота (`/web` или «🌐 Играть на сайте»
 * в «⚙️ Настройках»). Компоненты — story 03 (`wildworld-ui.css`: .auth-form, .field, .notice).
 *
 * @var string|null $error
 * @var string      $code
 * @var bool        $loggedIn
 */
$error    = is_string($error ?? null) ? $error : null;
$code     = is_string($code ?? null) ? $code : '';
$loggedIn = ($loggedIn ?? false) === true;
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<section class="block">
    <div class="container">
        <div class="section-head">
            <div><h1 class="mt-1 mb-0">Код из бота</h1></div>
            <div class="desc">Войди в аккаунт своего персонажа одноразовым кодом из Telegram-бота.</div>
        </div>

        <div class="grid grid-2">
            <div class="card stack">
                <?php if ($error !== null): ?>
                    <div class="notice error" role="alert"><span class="notice-title">Ошибка</span><?= esc($error) ?></div>
                <?php endif ?>
                <?php if ($loggedIn): ?>
                    <?php /* web-bridge-p1-17: код у вошедшего в другой аккаунт — отказ до траты кода (LinkCodeService, F1); путь — через выход. */ ?>
                    <div class="notice info" role="status"><span class="notice-title">Инфо</span>Ты уже вошёл на сайте. Код из бота пускает в персонажа только без другого входа: выйди из другого входа и введи код — войдёшь в персонажа из бота.</div>
                    <form class="auth-form" action="<?= esc(base_url('account/logout'), 'attr') ?>" method="post">
                        <?= csrf_field() ?>
                        <div class="auth-actions">
                            <button class="btn ghost" type="submit">Выйти, чтобы ввести код</button>
                        </div>
                    </form>
                <?php endif ?>

                <form class="auth-form" action="<?= esc(base_url('account/link'), 'attr') ?>" method="post">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="label" for="link-code">Код</label>
                        <input id="link-code" class="input" type="text" name="code" required
                               autocomplete="one-time-code" autocapitalize="characters" spellcheck="false"
                               maxlength="32" value="<?= esc($code, 'attr') ?>">
                    </div>
                    <div class="auth-actions">
                        <button class="btn primary" type="submit">Войти по коду</button>
                        <a class="btn ghost" href="<?= esc(base_url('account/login'), 'attr') ?>">Другие способы входа</a>
                    </div>
                </form>
            </div>

            <div class="card stack">
                <div class="label">Где взять код</div>
                <p>Напиши боту команду /web или открой «⚙️ Настройки» и нажми «🌐 Играть на сайте». Роби пришлёт код.</p>
                <p>Код одноразовый и живёт несколько минут — срок указан в сообщении с кодом. Новый код отменяет прежний.</p>
                <p>Не вошёл на сайте — код впустит тебя в аккаунт персонажа. Вошёл почтой, Google или Яндексом в другой аккаунт — выйди из другого входа и введи код.</p>
            </div>
        </div>
    </div>
</section>

<?= $this->endSection() ?>

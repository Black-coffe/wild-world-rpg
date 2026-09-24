<?php
/**
 * web-accounts-p0-08 (ADR-188) — регистрация по почте и паролю.
 *
 * За флагом `web.open_registration`: выключен — только объяснение закрытой беты и путь через бота
 * (`/web` → код → `/account/link`), формы нет. Компоненты — story 03 (`wildworld-ui.css`). Без JS.
 *
 * @var bool        $closed
 * @var string|null $error
 * @var string|null $errorField  код ошибки AccountAuthService (подсветка поля)
 * @var string      $email
 * @var int         $minLength
 * @var string      $botLink
 */
$closed     = ($closed ?? false) === true;
$error      = is_string($error ?? null) ? $error : null;
$errorField = is_string($errorField ?? null) ? $errorField : null;
$email      = is_string($email ?? null) ? $email : '';
$minLength  = is_int($minLength ?? null) ? $minLength : 8;
$botLink    = is_string($botLink ?? null) ? $botLink : '';
$pwdError   = $errorField === 'weak_password';
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<section class="block">
    <div class="container">
        <div class="section-head">
            <div><h1 class="mt-1 mb-0">Регистрация</h1></div>
            <div class="desc">Аккаунт на сайте: почта и пароль, Telegram не нужен.</div>
        </div>

        <div class="grid grid-2">
            <div class="card stack">
                <?php if ($closed): ?>
                    <div class="notice info" role="status">
                        <span class="notice-title">Закрытая бета</span>
                        Регистрация на сайте пока закрыта. Новые игроки заходят через Telegram-бота: начни игру там, отправь боту команду /web — он даст одноразовый код, и по нему ты войдёшь на сайт в своего персонажа.
                    </div>
                    <div class="auth-actions">
                        <?php if ($botLink !== ''): ?>
                            <a class="btn primary" href="<?= esc($botLink, 'attr') ?>" target="_blank" rel="noopener">Открыть бота</a>
                        <?php endif ?>
                        <a class="btn ghost" href="<?= esc(base_url('account/link'), 'attr') ?>">Ввести код из бота</a>
                        <a class="btn ghost" href="<?= esc(base_url('account/login'), 'attr') ?>">Войти</a>
                    </div>
                <?php else: ?>
                    <?php if ($error !== null): ?>
                        <div class="notice error" role="alert"><span class="notice-title">Ошибка</span><?= esc($error) ?></div>
                    <?php endif ?>

                    <form class="auth-form" action="<?= esc(base_url('account/register'), 'attr') ?>" method="post">
                        <?= csrf_field() ?>
                        <div class="field<?= $error !== null && ! $pwdError ? ' has-error' : '' ?>">
                            <label class="label" for="reg-email">Почта</label>
                            <input id="reg-email" class="input" type="email" name="email" autocomplete="email" required value="<?= esc($email, 'attr') ?>">
                        </div>
                        <div class="field<?= $pwdError ? ' has-error' : '' ?>">
                            <label class="label" for="reg-password">Пароль</label>
                            <input id="reg-password" class="input" type="password" name="password" autocomplete="new-password" required minlength="<?= $minLength ?>">
                            <span class="help">Не меньше <?= $minLength ?> символов.</span>
                        </div>
                        <div class="auth-actions">
                            <button class="btn primary" type="submit">Создать аккаунт</button>
                            <a class="btn ghost" href="<?= esc(base_url('account/login'), 'attr') ?>">Уже есть аккаунт</a>
                        </div>
                    </form>
                <?php endif ?>
            </div>

            <div class="card stack">
                <div class="label">Что дальше</div>
                <p class="mb-0">После регистрации придумай имя персонажа — он появится в мире сразу. Уже играешь в Telegram? Не регистрируйся заново: возьми код командой /web в боте и войди им — это тот же персонаж.</p>
            </div>
        </div>
    </div>
</section>

<?= $this->endSection() ?>

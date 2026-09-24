<?php
/**
 * web-accounts-p0-08 (ADR-188) — сброс пароля email-входа.
 *
 * Режимы: request (форма почты) · requested (один ответ на любой исход — известная/неизвестная
 * почта, письмо ушло/не ушло; всегда с оговоркой и входом кодом из бота, story 10) · form (новый пароль по ссылке) · invalid (ссылка
 * неверна/устарела/использована) · done. Компоненты — story 03. Без JS.
 *
 * @var string      $mode
 * @var string      $token
 * @var string|null $error
 * @var int         $minLength
 * @var int         $ttlMin
 * @var string      $botLink
 */
$mode      = is_string($mode ?? null) ? $mode : 'request';
$token     = is_string($token ?? null) ? $token : '';
$error     = is_string($error ?? null) ? $error : null;
$minLength = is_int($minLength ?? null) ? $minLength : 8;
$ttlMin    = is_int($ttlMin ?? null) ? $ttlMin : 60;
$botLink   = is_string($botLink ?? null) ? $botLink : '';
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<section class="block">
    <div class="container">
        <div class="section-head">
            <div><h1 class="mt-1 mb-0">Сброс пароля</h1></div>
            <div class="desc">Для входа по почте и паролю.</div>
        </div>

        <div class="grid grid-2">
            <div class="card stack">
                <?php if ($error !== null): ?>
                    <div class="notice error" role="alert"><span class="notice-title">Ошибка</span><?= esc($error) ?></div>
                <?php endif ?>

                <?php if ($mode === 'requested'): ?>
                    <div class="notice info" role="status"><span class="notice-title">Запрос принят</span>Если эта почта привязана к аккаунту, мы отправили на неё ссылку для нового пароля. Ссылка действует <?= $ttlMin ?> мин. и срабатывает один раз. Письма нет — загляни в «Спам». Наша почта иногда не доходит: если письма так и нет, войди без пароля — отправь Telegram-боту команду /web, он даст одноразовый код, введи его на странице привязки. После входа пароль можно задать заново в аккаунте.</div>
                    <div class="auth-actions">
                        <a class="btn primary" href="<?= esc(base_url('account/link'), 'attr') ?>">Ввести код из бота</a>
                        <?php if ($botLink !== ''): ?>
                            <a class="btn ghost" href="<?= esc($botLink, 'attr') ?>" target="_blank" rel="noopener">Открыть бота</a>
                        <?php endif ?>
                        <a class="btn ghost" href="<?= esc(base_url('account/login'), 'attr') ?>">Ко входу</a>
                    </div>

                <?php elseif ($mode === 'form'): ?>
                    <form class="auth-form" action="<?= esc(base_url('account/reset/' . rawurlencode($token)), 'attr') ?>" method="post">
                        <?= csrf_field() ?>
                        <div class="field<?= $error !== null ? ' has-error' : '' ?>">
                            <label class="label" for="reset-password">Новый пароль</label>
                            <input id="reset-password" class="input" type="password" name="password" autocomplete="new-password" required minlength="<?= $minLength ?>">
                            <span class="help">Не меньше <?= $minLength ?> символов.</span>
                        </div>
                        <div class="auth-actions">
                            <button class="btn primary" type="submit">Сохранить пароль</button>
                        </div>
                    </form>

                <?php elseif ($mode === 'invalid'): ?>
                    <div class="notice error" role="alert"><span class="notice-title">Ссылка не работает</span>Ссылка неверна, устарела или уже использована. Запроси новую.</div>
                    <div class="auth-actions">
                        <a class="btn primary" href="<?= esc(base_url('account/reset'), 'attr') ?>">Запросить ссылку</a>
                    </div>

                <?php elseif ($mode === 'done'): ?>
                    <div class="notice ok" role="status"><span class="notice-title">Готово</span>Пароль изменён. Войди с новым паролем; запомненные устройства вышли из аккаунта.</div>
                    <div class="auth-actions">
                        <a class="btn primary" href="<?= esc(base_url('account/login'), 'attr') ?>">Войти</a>
                    </div>

                <?php else: ?>
                    <form class="auth-form" action="<?= esc(base_url('account/reset'), 'attr') ?>" method="post">
                        <?= csrf_field() ?>
                        <div class="field">
                            <label class="label" for="reset-email">Почта</label>
                            <input id="reset-email" class="input" type="email" name="email" autocomplete="email" required>
                        </div>
                        <div class="auth-actions">
                            <button class="btn primary" type="submit">Прислать ссылку</button>
                            <a class="btn ghost" href="<?= esc(base_url('account/login'), 'attr') ?>">Ко входу</a>
                        </div>
                    </form>
                <?php endif ?>
            </div>

            <div class="card stack">
                <div class="label">Играешь через Telegram?</div>
                <p class="mb-0">Пароль не нужен: команда /web в боте даёт одноразовый код для входа на сайт.</p>
            </div>
        </div>
    </div>
</section>

<?= $this->endSection() ?>

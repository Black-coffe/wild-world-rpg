<?php
/**
 * web-accounts-p0-05 (ADR-188) — вход на сайт.
 *
 * Форма email+пароль рисуется ВСЕГДА (не зависит ни от флагов, ни от env) — это путь, который
 * доступен всегда. Telegram Login Widget — дополнительно, если задан бот (единственный JS страницы).
 * Компоненты — story 03 (`wildworld-ui.css`: .auth-form, .notice, .field, .provider-*).
 *
 * @var string|null $error
 * @var string|null $notice
 * @var string      $email
 * @var string      $botUsername
 */
$error       = is_string($error ?? null) ? $error : null;
$notice      = is_string($notice ?? null) ? $notice : null;
$email       = is_string($email ?? null) ? $email : '';
$botUsername = is_string($botUsername ?? null) ? $botUsername : '';
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<section class="block">
    <div class="container">
        <div class="section-head">
            <div><h1 class="mt-1 mb-0">Вход</h1></div>
            <div class="desc">Один аккаунт на все способы входа: почта с паролем или Telegram.</div>
        </div>

        <div class="grid grid-2">
            <div class="card stack">
                <?php if ($error !== null): ?>
                    <div class="notice error" role="alert"><span class="notice-title">Ошибка</span><?= esc($error) ?></div>
                <?php endif ?>
                <?php if ($notice !== null): ?>
                    <div class="notice info" role="status"><span class="notice-title">Инфо</span><?= esc($notice) ?></div>
                <?php endif ?>

                <form class="auth-form" action="<?= esc(base_url('account/login'), 'attr') ?>" method="post">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="label" for="login-email">Почта</label>
                        <input id="login-email" class="input" type="email" name="email" autocomplete="email" required value="<?= esc($email, 'attr') ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="login-password">Пароль</label>
                        <input id="login-password" class="input" type="password" name="password" autocomplete="current-password" required>
                    </div>
                    <label class="check"><input type="checkbox" name="remember" value="1"> <span class="box"></span> Запомнить меня</label>
                    <div class="auth-actions">
                        <button class="btn primary" type="submit">Войти</button>
                        <a class="btn ghost" href="<?= esc(base_url('account/reset'), 'attr') ?>">Забыл пароль</a>
                    </div>
                </form>
            </div>

            <div class="card stack">
                <div class="label">Войти через Telegram</div>
                <?php if ($botUsername !== ''): ?>
                    <div class="provider-list" id="tg-login-slot"></div>
                    <noscript><p class="provider-note">Вход через Telegram требует JavaScript. Войди почтой и паролем.</p></noscript>
                <?php else: ?>
                    <div class="provider-list">
                        <span class="provider-btn is-unavailable" aria-disabled="true"><span class="provider-mark" aria-hidden="true">TG</span><span class="provider-name">Telegram</span><span class="provider-state">недоступно</span></span>
                        <p class="provider-note">Вход через Telegram сейчас не настроен. Войди почтой и паролем.</p>
                    </div>
                <?php endif ?>
            </div>
        </div>
    </div>
</section>

<?= $this->endSection() ?>

<?php if ($botUsername !== ''): ?>
<?= $this->section('scripts') ?>
<script>
(function(){
    window.onTelegramAuthAccount = function(user){
        if (!user || typeof user !== 'object') return;
        var qs = Object.keys(user)
            .filter(function(k){ return user[k] !== null && user[k] !== undefined; })
            .map(function(k){ return encodeURIComponent(k) + '=' + encodeURIComponent(user[k]); })
            .join('&');
        window.location.href = <?= json_encode(base_url('login/telegram/callback'), JSON_UNESCAPED_SLASHES) ?> + '?' + qs + '&next=' + encodeURIComponent('/account');
    };
    var slot = document.getElementById('tg-login-slot');
    if (!slot) return;
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://telegram.org/js/telegram-widget.js?22';
    s.setAttribute('data-telegram-login', <?= json_encode($botUsername) ?>);
    s.setAttribute('data-size', 'large');
    s.setAttribute('data-onauth', 'onTelegramAuthAccount(user)');
    s.setAttribute('data-request-access', 'write');
    s.crossOrigin = 'anonymous';
    s.referrerPolicy = 'no-referrer';
    slot.appendChild(s);
})();
</script>
<?= $this->endSection() ?>
<?php endif ?>

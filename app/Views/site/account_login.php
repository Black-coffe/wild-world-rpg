<?php
/**
 * web-accounts-p0-05 (ADR-188) — вход на сайт. Story 07: кнопки Google / Яндекс (partial
 * `account_oauth_buttons`; без env — видны как «недоступно», не ссылками).
 *
 * web-accounts-oauth-only (2026-09-30): формы почты и пароля нет. Путь, доступный всегда, — код `/web`
 * из бота (`/account/link`). Telegram Login Widget — если задан бот (единственный JS страницы).
 * Компоненты — story 03 (`wildworld-ui.css`: .auth-form, .notice, .field, .provider-*).
 *
 * @var string|null $error
 * @var string|null $notice
 * @var string      $botUsername
 */
$error       = is_string($error ?? null) ? $error : null;
$notice      = is_string($notice ?? null) ? $notice : null;
$botUsername = is_string($botUsername ?? null) ? $botUsername : '';
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<section class="block">
    <div class="container">
        <div class="section-head">
            <div><h1 class="mt-1 mb-0">Вход</h1></div>
            <div class="desc">Один аккаунт на все способы входа: Telegram, Google или Яндекс. Паролей у сайта нет.</div>
        </div>

        <div class="grid grid-2">
            <div class="card stack">
                <?php if ($error !== null): ?>
                    <div class="notice error" role="alert"><span class="notice-title">Ошибка</span><?= esc($error) ?></div>
                <?php endif ?>
                <?php if ($notice !== null): ?>
                    <div class="notice info" role="status"><span class="notice-title">Инфо</span><?= esc($notice) ?></div>
                <?php endif ?>

                <div class="label">Войти кодом из бота</div>
                <p class="mb-0">Играешь в Telegram? Отправь боту команду /web — он даст одноразовый код. Введи его на сайте, и ты войдёшь в своего персонажа. Этот путь работает всегда.</p>
                <div class="auth-actions">
                    <a class="btn primary" href="<?= esc(base_url('account/link'), 'attr') ?>">Ввести код из бота</a>
                </div>
            </div>

            <div class="card stack">
                <div class="label">Войти через Telegram</div>
                <?php if ($botUsername !== ''): ?>
                    <div class="provider-list" id="tg-login-slot"></div>
                    <noscript><p class="provider-note">Вход через Telegram требует JavaScript. Войди кодом из бота.</p></noscript>
                <?php else: ?>
                    <div class="provider-list">
                        <span class="provider-btn is-unavailable" aria-disabled="true"><span class="provider-mark" aria-hidden="true">TG</span><span class="provider-name">Telegram</span><span class="provider-state">недоступно</span></span>
                        <p class="provider-note">Вход через Telegram сейчас не настроен. Войди кодом из бота.</p>
                    </div>
                <?php endif ?>

                <div class="label">Войти через Google или Яндекс</div>
                <?= view('site/account_oauth_buttons', ['oauthMode' => 'login', 'oauthLinked' => []]) ?>
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

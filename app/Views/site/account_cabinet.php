<?php
/**
 * web-accounts-p0-07 (ADR-188) — кабинет аккаунта: персонаж, способы входа, добавление
 * (почта+пароль, Google, Яндекс, Telegram), отвязка (кроме последнего), привязка кодом, выход.
 * Компоненты — story 03 (`wildworld-ui.css`: .identity-*, .provider-*, .notice, .auth-form).
 * Без JS работает всё, кроме Telegram-виджета (он сам — сторонний JS).
 *
 * @var string|null                       $characterName
 * @var bool                              $hasCharacter
 * @var list<array<string, mixed>>        $identities
 * @var list<string>                      $linked       провайдеры, уже привязанные к аккаунту
 * @var string                            $botUsername
 * @var string                            $linkNonce    одноразовый nonce привязки Telegram (story 09)
 * @var array{0:string,1:string}|null     $notice       [ok|error, текст]
 * @var string|null                       $emailError
 * @var string                            $emailValue
 */
$characterName = is_string($characterName ?? null) ? $characterName : null;
$hasCharacter  = ($hasCharacter ?? false) === true;
$identities    = is_array($identities ?? null) ? $identities : [];
$linked        = is_array($linked ?? null) ? $linked : [];
$botUsername   = is_string($botUsername ?? null) ? $botUsername : '';
$linkNonce     = is_string($linkNonce ?? null) ? $linkNonce : '';
$notice        = is_array($notice ?? null) ? $notice : null;
$emailError    = is_string($emailError ?? null) ? $emailError : null;
$emailValue    = is_string($emailValue ?? null) ? $emailValue : '';

$marks  = ['email' => '@', 'google' => 'G', 'yandex' => 'Я', 'telegram' => 'TG'];
$names  = ['email' => 'Почта', 'google' => 'Google', 'yandex' => 'Яндекс', 'telegram' => 'Telegram'];
$hasEmail    = in_array('email', $linked, true);
$hasTelegram = in_array('telegram', $linked, true);
$canUnlink   = count($identities) > 1;
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<section class="block">
    <div class="container">
        <div class="section-head">
            <div><h1 class="mt-1 mb-0">Аккаунт</h1></div>
            <div class="desc">Персонаж и способы входа. Держи любые, какие удобно, — но хотя бы один.</div>
        </div>

        <div class="grid grid-2">
            <div class="card stack">
                <?php if ($notice !== null): ?>
                    <?php if ($notice[0] === 'ok'): ?>
                        <div class="notice ok" role="status"><span class="notice-title">Готово</span><?= esc($notice[1]) ?></div>
                    <?php else: ?>
                        <div class="notice error" role="alert"><span class="notice-title">Ошибка</span><?= esc($notice[1]) ?></div>
                    <?php endif ?>
                <?php endif ?>

                <div class="label">Персонаж</div>
                <?php if ($hasCharacter): ?>
                    <h2 class="mb-0"><?= esc($characterName ?? 'Без имени') ?></h2>
                <?php else: ?>
                    <div class="notice info"><span class="notice-title">Инфо</span>К аккаунту ещё не привязан персонаж. Играешь в Telegram-боте? Возьми там код командой /web и введи его на странице привязки.</div>
                    <div><a class="btn primary" href="<?= esc(base_url('account/link'), 'attr') ?>">Привязать персонажа из бота</a></div>
                <?php endif ?>

                <div class="label">Способы входа</div>
                <div class="identity-list">
                    <?php foreach ($identities as $identity): ?>
                        <?php
                        $provider = is_string($identity['provider'] ?? null) ? $identity['provider'] : '';
                        $subject  = is_scalar($identity['subject'] ?? null) ? (string) $identity['subject'] : '';
                        $mail     = is_string($identity['email'] ?? null) && $identity['email'] !== '' ? $identity['email'] : null;
                        $caption  = match ($provider) {
                            'email'    => $mail ?? $subject,
                            'telegram' => 'id ' . $subject,
                            default    => $mail ?? 'подключён',
                        };
                        $identityId = is_numeric($identity['id'] ?? null) ? (int) $identity['id'] : 0;
                        ?>
                        <div class="identity-row">
                            <span class="provider-mark" aria-hidden="true"><?= esc($marks[$provider] ?? '?') ?></span>
                            <div class="identity-main"><span class="identity-provider"><?= esc($names[$provider] ?? $provider) ?></span><span class="identity-subject"><?= esc($caption) ?></span></div>
                            <div class="identity-action">
                                <?php if ($canUnlink): ?>
                                    <form action="<?= esc(base_url('account/identity/' . $identityId . '/unlink'), 'attr') ?>" method="post">
                                        <?= csrf_field() ?>
                                        <button class="btn sm ghost" type="submit">Отвязать</button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge" title="Последний способ входа отвязать нельзя">последний</span>
                                <?php endif ?>
                            </div>
                        </div>
                    <?php endforeach ?>
                </div>
                <?php if (! $canUnlink): ?>
                    <p class="provider-note">Это единственный способ входа — его нельзя отвязать. Добавь ещё один, чтобы не потерять доступ.</p>
                <?php endif ?>

                <form class="auth-form" action="<?= esc(base_url('account/logout'), 'attr') ?>" method="post">
                    <?= csrf_field() ?>
                    <div class="auth-actions">
                        <button class="btn ghost" type="submit">Выйти</button>
                        <?php if ($hasCharacter): ?>
                            <a class="btn ghost" href="<?= esc(base_url('account/link'), 'attr') ?>">Привязка кодом из бота</a>
                        <?php endif ?>
                    </div>
                </form>
            </div>

            <div class="card stack">
                <div class="label"><?= $hasEmail ? 'Сменить пароль' : 'Добавить почту и пароль' ?></div>
                <form class="auth-form" action="<?= esc(base_url('account/identity/email'), 'attr') ?>" method="post">
                    <?= csrf_field() ?>
                    <?php if ($emailError !== null): ?>
                        <div class="notice error" role="alert"><span class="notice-title">Ошибка</span><?= esc($emailError) ?></div>
                    <?php endif ?>
                    <div class="field<?= $emailError !== null ? ' has-error' : '' ?>">
                        <label class="label" for="cabinet-email">Почта</label>
                        <input id="cabinet-email" class="input" type="email" name="email" autocomplete="email" required value="<?= esc($emailValue, 'attr') ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="cabinet-password"><?= $hasEmail ? 'Новый пароль' : 'Пароль' ?></label>
                        <input id="cabinet-password" class="input" type="password" name="password" autocomplete="new-password" required>
                    </div>
                    <div class="auth-actions">
                        <button class="btn primary" type="submit">Сохранить</button>
                    </div>
                </form>

                <div class="label">Привязать Google или Яндекс</div>
                <?= view('site/account_oauth_buttons', ['oauthMode' => 'link', 'oauthLinked' => $linked]) ?>

                <div class="label">Telegram</div>
                <?php if ($hasTelegram): ?>
                    <p class="provider-note">Telegram уже привязан — он в списке способов входа.</p>
                <?php elseif ($botUsername !== ''): ?>
                    <div class="provider-list" id="tg-login-slot"></div>
                    <noscript><p class="provider-note">Привязка Telegram требует JavaScript. Или возьми код в боте командой /web.</p></noscript>
                <?php else: ?>
                    <div class="provider-list">
                        <span class="provider-btn is-unavailable" aria-disabled="true"><span class="provider-mark" aria-hidden="true">TG</span><span class="provider-name">Telegram</span><span class="provider-state">недоступно</span></span>
                        <p class="provider-note">Вход через Telegram сейчас не настроен на сервере.</p>
                    </div>
                <?php endif ?>
            </div>
        </div>
    </div>
</section>

<?= $this->endSection() ?>

<?php if (! $hasTelegram && $botUsername !== ''): ?>
<?= $this->section('scripts') ?>
<script>
(function(){
    window.onTelegramAuthAccount = function(user){
        if (!user || typeof user !== 'object') return;
        var qs = Object.keys(user)
            .filter(function(k){ return user[k] !== null && user[k] !== undefined; })
            .map(function(k){ return encodeURIComponent(k) + '=' + encodeURIComponent(user[k]); })
            .join('&');
        window.location.href = <?= json_encode(base_url('login/telegram/callback'), JSON_UNESCAPED_SLASHES) ?> + '?' + qs + '&next=' + encodeURIComponent('/account') + '&link_nonce=' + encodeURIComponent(<?= json_encode($linkNonce) ?>);
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

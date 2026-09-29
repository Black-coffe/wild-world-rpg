<?php
/**
 * web-accounts-p0-08 (ADR-188) — регистрация. web-accounts-oauth-only (2026-09-30): почты с паролем нет —
 * аккаунт создаёт первый вход через Google или Яндекс (кнопки `account_oauth_buttons`).
 *
 * За флагом `web.open_registration`: выключен — только объяснение закрытой беты и путь через бота
 * (`/web` → код → `/account/link`), формы нет. Компоненты — story 03 (`wildworld-ui.css`). Без JS.
 *
 * @var bool        $closed
 * @var string      $botLink
 */
$closed     = ($closed ?? false) === true;
$botLink    = is_string($botLink ?? null) ? $botLink : '';
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<section class="block">
    <div class="container">
        <div class="section-head">
            <div><h1 class="mt-1 mb-0">Регистрация</h1></div>
            <div class="desc">Аккаунт на сайте — через Google или Яндекс. Паролей у сайта нет.</div>
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
                    <div class="label">Зарегистрироваться через Google или Яндекс</div>
                    <p class="mb-0">Первый вход через Google или Яндекс создаёт аккаунт. Доступ к нему восстанавливает сам Google или Яндекс.</p>
                    <?= view('site/account_oauth_buttons', ['oauthMode' => 'login', 'oauthLinked' => []]) ?>
                    <div class="auth-actions">
                        <a class="btn ghost" href="<?= esc(base_url('account/login'), 'attr') ?>">Уже есть аккаунт</a>
                    </div>
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

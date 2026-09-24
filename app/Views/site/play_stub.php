<?php
/**
 * web-bridge-p1-06 — заглушка `/play`, когда играть на сайте ещё нельзя: вместо пустоты —
 * объяснение и путь дальше.
 *  - `flag_off`     — игра на сайте за флагом `web.play_enabled` (выключен): путь в бота и в кабинет.
 *  - `no_character` — у аккаунта нет персонажа: создать (`can_register`) или взять код /web в боте.
 *
 * @var string $reason 'flag_off'|'no_character'
 * @var bool   $can_register
 */
$why         = ($reason ?? '') === 'no_character' ? 'no_character' : 'flag_off';
$canRegister = ($can_register ?? false) === true;
$botLink     = config('Social')->botStart('src_site_play');
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<section class="block">
    <div class="container">
        <div class="section-head">
            <div><h1 class="mt-1 mb-0">Играть на сайте</h1></div>
        </div>

        <div class="play-lock">
            <?php if ($why === 'flag_off'): ?>
                <span class="play-lock-title">🔒 Игра на сайте скоро</span>
                <span class="play-lock-why">Игра на сайте скоро: включается после проверки. Пока играй в Telegram-боте — персонаж тот же.</span>
                <span class="play-lock-path">Сейчас: бот в Telegram · кабинет — /account</span>
            <?php elseif ($canRegister): ?>
                <span class="play-lock-title">🔒 Нет персонажа</span>
                <span class="play-lock-why">У этого аккаунта ещё нет персонажа. Создай его — и игра откроется здесь.</span>
                <span class="play-lock-path">Как открыть: кабинет → «Создать персонажа»</span>
            <?php else: ?>
                <span class="play-lock-title">🔒 Нет персонажа</span>
                <span class="play-lock-why">У этого аккаунта нет персонажа. Начни игру в Telegram-боте, отправь боту команду /web и введи полученный код на сайте — войдёшь в своего персонажа.</span>
                <span class="play-lock-path">Как открыть: бот → /web → код на странице «Ввести код»</span>
            <?php endif ?>
        </div>

        <div class="auth-actions mt-2">
            <?php if ($why === 'no_character' && $canRegister): ?>
                <a class="btn primary" href="<?= esc(base_url('account/character'), 'attr') ?>">Создать персонажа</a>
            <?php elseif ($why === 'no_character'): ?>
                <a class="btn primary" href="<?= esc(base_url('account/link'), 'attr') ?>">Ввести код из бота</a>
                <a class="btn ghost" href="<?= esc($botLink, 'attr') ?>" target="_blank" rel="noopener">Открыть бота</a>
            <?php else: ?>
                <a class="btn primary" href="<?= esc($botLink, 'attr') ?>" target="_blank" rel="noopener">Открыть бота</a>
                <a class="btn ghost" href="<?= esc(base_url('account'), 'attr') ?>">Кабинет</a>
            <?php endif ?>
        </div>
    </div>
</section>

<?= $this->endSection() ?>

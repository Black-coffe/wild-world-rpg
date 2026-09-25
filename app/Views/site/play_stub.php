<?php
/**
 * web-bridge-p1-06 — заглушка `/play`, когда играть на сайте ещё нельзя: вместо пустоты —
 * объяснение и путь дальше.
 *  - `flag_off`     — игра на сайте за флагом `web.play_enabled` (выключен): путь в бота и в кабинет.
 *  - `no_character` — у аккаунта нет персонажа: создать (`can_register`) или взять код /web в боте.
 *    Состояние видно только вошедшему, а код у вошедшего в другой аккаунт отказывает (F1,
 *    LinkCodeService) — поэтому путь бот-игрока идёт через выход (web-bridge-p1-17).
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
                <span class="play-lock-why">У этого аккаунта ещё нет персонажа. Создай его — и игра откроется здесь. Играешь в Telegram-боте? Этот вход — другой: выйди из другого входа и введи код из команды /web — войдёшь в персонажа из бота.</span>
                <span class="play-lock-path">Как открыть: «Создать персонажа» · или выйти → бот → /web → код на странице ввода кода</span>
            <?php else: ?>
                <span class="play-lock-title">🔒 Нет персонажа</span>
                <span class="play-lock-why">У этого аккаунта нет персонажа. Играешь в Telegram-боте? Этот вход — другой: выйди из другого входа и введи код из команды /web — войдёшь в персонажа из бота.</span>
                <span class="play-lock-path">Как открыть: выйти → бот → /web → код на странице ввода кода</span>
            <?php endif ?>
        </div>

        <?php if ($why === 'no_character'): ?>
            <form class="auth-form mt-2" action="<?= esc(base_url('account/logout'), 'attr') ?>" method="post">
                <?= csrf_field() ?>
                <div class="auth-actions">
                    <?php if ($canRegister): ?>
                        <a class="btn primary" href="<?= esc(base_url('account/character'), 'attr') ?>">Создать персонажа</a>
                    <?php endif ?>
                    <button class="btn <?= $canRegister ? 'ghost' : 'primary' ?>" type="submit">Выйти, чтобы ввести код</button>
                    <a class="btn ghost" href="<?= esc(base_url('account/link'), 'attr') ?>">Страница ввода кода</a>
                    <a class="btn ghost" href="<?= esc($botLink, 'attr') ?>" target="_blank" rel="noopener">Открыть бота</a>
                </div>
            </form>
        <?php else: ?>
            <div class="auth-actions mt-2">
                <a class="btn primary" href="<?= esc($botLink, 'attr') ?>" target="_blank" rel="noopener">Открыть бота</a>
                <a class="btn ghost" href="<?= esc(base_url('account'), 'attr') ?>">Кабинет</a>
            </div>
        <?php endif ?>
    </div>
</section>

<?= $this->endSection() ?>

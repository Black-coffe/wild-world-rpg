<?php
/**
 * web-accounts-p0-08 (ADR-188) — создание персонажа без Telegram (один на аккаунт, plan A1).
 *
 * За флагом `web.open_registration`: выключен — объяснение закрытой беты и путь через бота.
 * Правило имени — то же, что у `/name` в боте (NameService). Компоненты — story 03. Без JS.
 *
 * @var bool        $closed
 * @var string|null $error
 * @var string      $name
 * @var string      $ruleText
 * @var string      $botLink
 */
$closed   = ($closed ?? false) === true;
$error    = is_string($error ?? null) ? $error : null;
$name     = is_string($name ?? null) ? $name : '';
$ruleText = is_string($ruleText ?? null) ? $ruleText : '';
$botLink  = is_string($botLink ?? null) ? $botLink : '';
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<section class="block">
    <div class="container">
        <div class="section-head">
            <div><h1 class="mt-1 mb-0">Новый персонаж</h1></div>
            <div class="desc">Один аккаунт — один персонаж.</div>
        </div>

        <div class="grid grid-2">
            <div class="card stack">
                <?php if ($closed): ?>
                    <div class="notice info" role="status">
                        <span class="notice-title">Закрытая бета</span>
                        Создать персонажа на сайте пока нельзя. Начни игру в Telegram-боте, затем отправь боту команду /web — он даст одноразовый код, и по нему ты войдёшь на сайт в своего персонажа.
                    </div>
                    <div class="auth-actions">
                        <?php if ($botLink !== ''): ?>
                            <a class="btn primary" href="<?= esc($botLink, 'attr') ?>" target="_blank" rel="noopener">Открыть бота</a>
                        <?php endif ?>
                        <a class="btn ghost" href="<?= esc(base_url('account/link'), 'attr') ?>">Ввести код из бота</a>
                    </div>
                <?php else: ?>
                    <?php if ($error !== null): ?>
                        <div class="notice error" role="alert"><span class="notice-title">Ошибка</span><?= esc($error) ?></div>
                    <?php endif ?>

                    <form class="auth-form" action="<?= esc(base_url('account/character'), 'attr') ?>" method="post">
                        <?= csrf_field() ?>
                        <div class="field<?= $error !== null ? ' has-error' : '' ?>">
                            <label class="label" for="char-name">Имя персонажа</label>
                            <input id="char-name" class="input" type="text" name="name" autocomplete="off" required maxlength="20" value="<?= esc($name, 'attr') ?>">
                            <span class="help"><?= esc($ruleText) ?></span>
                        </div>
                        <div class="auth-actions">
                            <button class="btn primary" type="submit">Создать персонажа</button>
                        </div>
                    </form>
                <?php endif ?>
            </div>

            <div class="card stack">
                <div class="label">Уже играешь в Telegram?</div>
                <p class="mb-0">Не создавай второго персонажа: возьми код командой /web в боте и введи его на странице привязки — сайт откроет твоего персонажа из Telegram.</p>
            </div>
        </div>
    </div>
</section>

<?= $this->endSection() ?>

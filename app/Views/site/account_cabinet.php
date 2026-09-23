<?php
/**
 * web-accounts-p0-05 (ADR-188) — кабинет аккаунта, заглушка: персонаж и выход.
 * Способы входа и их управление — story 07. Без JS.
 *
 * @var string|null                  $characterName
 * @var bool                         $hasCharacter
 * @var array{0:string,1:string}|null $notice  [ok|error, текст]
 */
$characterName = is_string($characterName ?? null) ? $characterName : null;
$hasCharacter  = ($hasCharacter ?? false) === true;
$notice        = is_array($notice ?? null) ? $notice : null;
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<section class="block">
    <div class="container">
        <div class="section-head">
            <div><h1 class="mt-1 mb-0">Аккаунт</h1></div>
            <div class="desc">Твой персонаж и выход с сайта.</div>
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
                    <div class="notice info"><span class="notice-title">Инфо</span>К аккаунту ещё не привязан персонаж. Возьми код в боте командой /web и введи его на странице привязки.</div>
                <?php endif ?>

                <form class="auth-form" action="<?= esc(base_url('account/logout'), 'attr') ?>" method="post">
                    <?= csrf_field() ?>
                    <div class="auth-actions">
                        <button class="btn ghost" type="submit">Выйти</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>

<?= $this->endSection() ?>

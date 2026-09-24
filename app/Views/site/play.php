<?php
/**
 * web-bridge-p1-06 — страница `/play`: игра в браузере через маршруты бота (ADR-189).
 *
 * Верхняя строка — имя персонажа и колокол со счётчиком непрочитанного; `#play-state` — экран,
 * история, ввод и док (`site/_play/state`); слот панели входящих заполняет JS из GET `/play/inbox`.
 * Без JS все кнопки работают через PRG. JS — `wildworld-play.js`, только улучшение.
 * Telegram/chat id сюда не передаётся (ADR-189 инв. 6).
 *
 * @var array<string,mixed> $state
 * @var int                 $unread
 * @var int                 $poll_seconds
 * @var string              $character_name
 */
$unreadCount = is_int($unread ?? null) && $unread > 0 ? $unread : 0;
$pollMin     = config(\Config\WebPlay::class)->inboxPollMinSeconds;
$poll        = max($pollMin, is_int($poll_seconds ?? null) ? $poll_seconds : $pollMin);
$charName    = is_string($character_name ?? null) ? $character_name : '';
$countLabel  = $unreadCount > 99 ? '99+' : ($unreadCount > 0 ? (string) $unreadCount : '');
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<section class="block">
    <div class="container">
        <div id="play-root"
             data-act-url="<?= esc(base_url('play/act'), 'attr') ?>"
             data-inbox-url="<?= esc(base_url('play/inbox'), 'attr') ?>"
             data-read-url="<?= esc(base_url('play/inbox/read'), 'attr') ?>"
             data-poll-seconds="<?= $poll ?>"
             data-poll-min="<?= $pollMin ?>"
             data-csrf-name="<?= esc(csrf_token(), 'attr') ?>">
            <div class="play-bar">
                <span class="play-bar-title"><?= esc($charName) ?></span>
                <a class="play-bell<?= $unreadCount > 0 ? ' is-unread' : '' ?>" id="play-bell" href="#play-inbox"
                   aria-controls="play-inbox" aria-expanded="false"
                   aria-label="Входящие: <?= $unreadCount > 0 ? esc($countLabel) . ' непрочитанных' : 'нет новых' ?>"><span aria-hidden="true">🔔</span><span class="play-bell-count"><?= esc($countLabel) ?></span></a>
            </div>
            <noscript><p class="play-input-hint">Без JavaScript кнопки работают, а входящие откроются только со включённым JavaScript.</p></noscript>

            <section id="play-inbox" class="stack" aria-label="Входящие" hidden>
                <div class="play-history-head">Входящие</div>
                <div id="play-inbox-list"></div>
            </section>

            <div id="play-state">
                <?= view('site/_play/state', ['state' => is_array($state ?? null) ? $state : [], 'alert' => is_string($alert ?? null) ? $alert : null]) ?>
            </div>
        </div>
    </div>
</section>

<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/wildworld-play.js') ?>?v=1" defer></script>
<?= $this->endSection() ?>

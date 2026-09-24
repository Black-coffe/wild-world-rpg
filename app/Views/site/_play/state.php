<?php
/**
 * web-bridge-p1-06 — состояние `/play`: текущий экран, лента истории, строка ввода, нижний док,
 * всплывашка ответа кнопки. Отдаётся целиком в `#play-state` и в JSON `html` на POST `/play/act`.
 *
 * Работает без JS: каждая кнопка — форма POST `/play/act` (PRG). Каждая форма несёт CSRF и свой
 * случайный `intent_id`. Текст и подписи — только через TelegramMarkupRenderer::toHtml()
 * (экранирует всё, кроме белого списка). Telegram/chat id сюда не передаётся и не выводится
 * (ADR-189 инв. 6). Классы — только `.play-*` из wildworld-ui.css (story 02).
 *
 * @var array{screen?:mixed, history?:mixed, dock?:mixed, input?:mixed} $state
 * @var string|null $alert
 */

use App\Services\Web\TelegramMarkupRenderer;

$st      = is_array($state ?? null) ? $state : [];
$screen  = is_array($st['screen'] ?? null) ? $st['screen'] : [];
$history = is_array($st['history'] ?? null) ? $st['history'] : [];
$dock    = is_array($st['dock'] ?? null) ? $st['dock'] : [];
$input   = is_array($st['input'] ?? null) ? $st['input'] : null;
$alertText = is_string($alert ?? null) && $alert !== '' ? $alert : null;
$actUrl  = base_url('play/act');
$maxLen  = config(\Config\WebPlay::class)->textMaxLength;

/** Скрытые поля одной формы действия: CSRF + уникальный intent_id. */
$hidden = static function (string $kind, string $data, ?int $messageId): string {
    $html = csrf_field()
        . '<input type="hidden" name="intent_id" value="' . bin2hex(random_bytes(16)) . '">'
        . '<input type="hidden" name="kind" value="' . esc($kind, 'attr') . '">'
        . '<input type="hidden" name="data" value="' . esc($data, 'attr') . '">';
    if ($messageId !== null) {
        $html .= '<input type="hidden" name="message_id" value="' . $messageId . '">';
    }

    return $html;
};

/** Inline-клавиатура сообщения: callback → форма, url → внешняя ссылка. */
$keyboard = static function (array $msg) use ($hidden, $actUrl): string {
    $rows = is_array($msg['inline_keyboard'] ?? null) ? $msg['inline_keyboard'] : [];
    $messageId = is_int($msg['message_id'] ?? null) ? $msg['message_id'] : null;
    $out = '';
    foreach ($rows as $row) {
        if (! is_array($row) || $row === []) {
            continue;
        }
        $out .= '<div class="play-kb-row">';
        foreach ($row as $btn) {
            if (! is_array($btn)) {
                continue;
            }
            $label = is_string($btn['text'] ?? null) ? $btn['text'] : '';
            if (is_string($btn['callback_data'] ?? null)) {
                $out .= '<form action="' . esc($actUrl, 'attr') . '" method="post">'
                    . $hidden('callback', $btn['callback_data'], $messageId)
                    . '<button class="play-kb-btn" type="submit">' . esc($label) . '</button></form>';
            } elseif (is_string($btn['url'] ?? null) && preg_match('~^https?://~i', $btn['url']) === 1) {
                $out .= '<a class="play-kb-btn is-url" href="' . esc($btn['url'], 'attr') . '" target="_blank" rel="noopener">' . esc($label) . '</a>';
            } else {
                $out .= '<button class="play-kb-btn is-disabled" type="button" disabled>' . esc($label) . '</button>';
            }
        }
        $out .= '</div>';
    }

    return $out === '' ? '' : '<div class="play-kb">' . $out . '</div>';
};

/** Тело сообщения: текст, либо фото + полная подпись (media-off: подпись несёт весь смысл). */
$body = static function (array $msg): string {
    $parse   = is_string($msg['parse_mode'] ?? null) ? $msg['parse_mode'] : null;
    $text    = is_string($msg['text'] ?? null) ? $msg['text'] : null;
    $caption = is_string($msg['caption'] ?? null) ? $msg['caption'] : null;
    // Только http(s):// или путь сайта; `//host` и `/\host` браузер читает как чужой хост (p1-13).
    $photo   = is_string($msg['photo_url'] ?? null) && preg_match('~^(https?://|/(?![/\\\\]))~i', $msg['photo_url']) === 1 ? $msg['photo_url'] : null;
    $out = '';
    if ($photo !== null || $caption !== null) {
        $out .= '<figure class="play-msg-figure">';
        if ($photo !== null) {
            $out .= '<img src="' . esc($photo, 'attr') . '" alt="" loading="lazy">';
        }
        if ($caption !== null && $caption !== '') {
            $out .= '<figcaption class="play-msg-caption">' . TelegramMarkupRenderer::toHtml($caption, $parse) . '</figcaption>';
        }
        $out .= '</figure>';
    }
    if ($text !== null && $text !== '') {
        $out .= '<div class="play-msg-text">' . TelegramMarkupRenderer::toHtml($text, $parse) . '</div>';
    }

    return $out;
};
?>
<div class="play-shell">
    <div class="play-screen">
        <?php if ($alertText !== null): ?>
            <div class="play-alert" role="status"><span class="play-alert-title">Ответ кнопки</span><?= esc($alertText) ?></div>
        <?php endif ?>

        <?php foreach ($screen as $msg): ?>
            <?php if (! is_array($msg)) { continue; } ?>
            <article class="play-msg is-current">
                <?= $body($msg) ?>
                <?= $keyboard($msg) ?>
            </article>
        <?php endforeach ?>

        <form class="play-input-form" action="<?= esc($actUrl, 'attr') ?>" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="intent_id" value="<?= bin2hex(random_bytes(16)) ?>">
            <input type="hidden" name="kind" value="text">
            <?php if ($input !== null && is_int($input['reply_to'] ?? null)): ?>
                <input type="hidden" name="message_id" value="<?= $input['reply_to'] ?>">
                <p class="play-input-hint">Бот ждёт ответ.</p>
            <?php endif ?>
            <div class="play-input">
                <input class="input" type="text" name="data" required maxlength="<?= $maxLen ?>" autocomplete="off"
                       placeholder="<?= esc($input !== null && is_string($input['placeholder'] ?? null) && $input['placeholder'] !== '' ? $input['placeholder'] : 'Сообщение или команда, например /menu', 'attr') ?>"
                       aria-label="Сообщение боту">
                <button class="btn primary" type="submit">Отправить</button>
            </div>
        </form>

        <nav class="play-dock" aria-label="Меню игры">
            <?php $dockCount = 0; ?>
            <?php foreach ($dock as $row): ?>
                <?php foreach (is_array($row) ? $row : [] as $label): ?>
                    <?php if (! is_string($label) || $label === '') { continue; } $dockCount++; ?>
                    <form action="<?= esc($actUrl, 'attr') ?>" method="post"><?= $hidden('text', $label, null) ?><button class="play-dock-btn" type="submit"><?= esc($label) ?></button></form>
                <?php endforeach ?>
            <?php endforeach ?>
            <?php if ($dockCount === 0): ?>
                <form action="<?= esc($actUrl, 'attr') ?>" method="post"><?= $hidden('command', '/menu', null) ?><button class="play-dock-btn" type="submit">Меню</button></form>
            <?php endif ?>
        </nav>
    </div>

    <aside class="play-history" aria-label="История экранов">
        <div class="play-history-head">История</div>
        <?php if ($history === []): ?>
            <p class="play-inbox-empty">Предыдущих экранов пока нет.</p>
        <?php else: ?>
            <ol class="play-history-list">
                <?php foreach ($history as $i => $past): ?>
                    <?php if (! is_array($past)) { continue; } ?>
                    <li class="play-history-item<?= $i > 0 ? ' is-older' : '' ?>">
                        <?php foreach ($past as $msg): ?>
                            <?php if (! is_array($msg)) { continue; } ?>
                            <?php $txt = is_string($msg['text'] ?? null) && $msg['text'] !== '' ? $msg['text'] : (is_string($msg['caption'] ?? null) ? $msg['caption'] : ''); ?>
                            <div class="play-msg-text"><?= TelegramMarkupRenderer::toHtml($txt, is_string($msg['parse_mode'] ?? null) ? $msg['parse_mode'] : null) ?></div>
                            <?= $keyboard($msg) ?>
                        <?php endforeach ?>
                    </li>
                <?php endforeach ?>
            </ol>
        <?php endif ?>
    </aside>
</div>

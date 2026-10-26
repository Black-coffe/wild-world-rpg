<?php
/**
 * web-onboarding-03 — плашка новых сообщений над экраном `/play`: непрочитанные фоновые сообщения
 * целиком (текст или подпись к фото, все кнопки). Рендерится сервером при загрузке страницы и в
 * JSON `banner_html` опроса `/play/inbox`. Кнопки — те же формы `/play/act` с `message_id`, что и
 * во входящих; «✕ Скрыть» — форма POST `/play/inbox/read` (без JS работает через PRG).
 * W-REG.5: каждое сообщение свёрнуто в строку `<details>` (первая строка текста или подписи, иначе
 * «Сообщение»), тап раскрывает его целиком — плашка не съедает первый экран телефона.
 *
 * @var list<array<string,mixed>> $items
 * @var int                       $more
 */

use App\Services\Web\TelegramMarkupRenderer;

$list   = is_array($items ?? null) ? $items : [];
$more   = is_int($more ?? null) && $more > 0 ? $more : 0;
$actUrl = base_url('play/act');

/** W-REG.5: первая непустая строка текста или подписи без разметки, не длиннее 80 символов. */
$summary = static function (string $text, string $caption, ?string $parse): string {
    foreach ([$text, $caption] as $src) {
        $html  = preg_replace('~<br\s*/?>~i', "\n", TelegramMarkupRenderer::toHtml($src, $parse)) ?? '';
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach (preg_split('~\R~u', $plain) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                return mb_strimwidth($line, 0, 80, '…', 'UTF-8');
            }
        }
    }

    return 'Сообщение';
};
?>
<div id="play-banner" class="play-banner" aria-live="polite"<?= $list === [] && $more === 0 ? ' hidden' : '' ?>>
<?php if ($list !== [] || $more > 0): ?>
    <div class="play-banner-head">
        <span class="play-banner-title">Новое сообщение</span>
        <form action="<?= esc(base_url('play/inbox/read'), 'attr') ?>" method="post">
            <?= csrf_field() ?>
            <button class="play-banner-close" type="submit">✕ Скрыть</button>
        </form>
    </div>
    <?php foreach ($list as $msg): ?>
        <?php
        if (! is_array($msg)) {
            continue;
        }
        $parse     = is_string($msg['parse_mode'] ?? null) ? $msg['parse_mode'] : null;
        $text      = is_string($msg['text'] ?? null) ? $msg['text'] : '';
        $caption   = is_string($msg['caption'] ?? null) ? $msg['caption'] : '';
        $photo     = is_string($msg['photo_url'] ?? null) && preg_match('~^(https?://|/(?![/\\\\]))~i', $msg['photo_url']) === 1 ? $msg['photo_url'] : null;
        if ($photo !== null && $photo[0] === '/') {
            $path  = (string) parse_url($photo, PHP_URL_PATH);
            $photo = $path !== '' && ! str_contains($path, '..') && is_file(FCPATH . ltrim(rawurldecode($path), '/')) ? $photo : null;
        }
        $messageId = is_int($msg['message_id'] ?? null) ? $msg['message_id'] : null;
        $rows      = is_array($msg['inline_keyboard'] ?? null) ? $msg['inline_keyboard'] : [];
        ?>
        <details class="play-banner-item"<?= $messageId !== null ? ' data-message-id="' . $messageId . '"' : '' ?>>
        <summary class="play-banner-summary"><span class="play-banner-line"><?= esc($summary($text, $caption, $parse)) ?></span></summary>
        <article class="play-msg play-banner-msg">
            <?php if ($photo !== null || $caption !== ''): ?>
                <figure class="play-msg-figure">
                    <?php if ($photo !== null): ?><img src="<?= esc($photo, 'attr') ?>" alt="" loading="lazy"><?php endif ?>
                    <?php if ($caption !== ''): ?><figcaption class="play-msg-caption"><?= TelegramMarkupRenderer::toHtml($caption, $parse) ?></figcaption><?php endif ?>
                </figure>
            <?php endif ?>
            <?php if ($text !== ''): ?><div class="play-msg-text"><?= TelegramMarkupRenderer::toHtml($text, $parse) ?></div><?php endif ?>
            <?php if ($rows !== []): ?>
                <div class="play-kb">
                    <?php foreach ($rows as $row): ?>
                        <?php if (! is_array($row) || $row === []) { continue; } ?>
                        <div class="play-kb-row">
                            <?php foreach ($row as $btn): ?>
                                <?php if (! is_array($btn)) { continue; } $label = is_string($btn['text'] ?? null) ? $btn['text'] : ''; ?>
                                <?php if (is_string($btn['callback_data'] ?? null)): ?>
                                    <form action="<?= esc($actUrl, 'attr') ?>" method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="intent_id" value="<?= bin2hex(random_bytes(16)) ?>">
                                        <input type="hidden" name="kind" value="callback">
                                        <input type="hidden" name="data" value="<?= esc($btn['callback_data'], 'attr') ?>">
                                        <?php if ($messageId !== null): ?><input type="hidden" name="message_id" value="<?= $messageId ?>"><?php endif ?>
                                        <button class="play-kb-btn" type="submit"><?= esc($label) ?></button>
                                    </form>
                                <?php elseif (is_string($btn['url'] ?? null) && preg_match('~^https?://~i', $btn['url']) === 1): ?>
                                    <a class="play-kb-btn is-url" href="<?= esc($btn['url'], 'attr') ?>" target="_blank" rel="noopener"><?= esc($label) ?></a>
                                <?php else: ?>
                                    <button class="play-kb-btn is-disabled" type="button" disabled><?= esc($label) ?></button>
                                <?php endif ?>
                            <?php endforeach ?>
                        </div>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
        </article>
        </details>
    <?php endforeach ?>
    <?php if ($more > 0): ?>
        <p class="play-banner-more">ещё <?= $more ?> — во входящих 🔔</p>
    <?php endif ?>
<?php endif ?>
</div>

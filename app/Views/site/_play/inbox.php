<?php
/**
 * web-bridge-p1-06 — входящие `/play`: фоновые сообщения бота (крафт готов, на базу напали…),
 * новые сверху, непрочитанные помечены. Кнопки сообщения — формы POST `/play/act`, как на экране.
 * Отдаётся в JSON `html` GET `/play/inbox`. Классы — `.play-inbox*`, `.play-kb*` (story 02).
 *
 * @var list<array{msg:array<string,mixed>, created_at:string, read:bool}> $items
 */

use App\Services\Web\TelegramMarkupRenderer;

$list = [];
foreach (is_array($items ?? null) ? $items : [] as $item) {
    if (is_array($item) && is_array($item['msg'] ?? null)) {
        $list[] = $item;
    }
}
// Новые сверху; usort стабилен (PHP 8), равные времена сохраняют порядок сервиса.
usort($list, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
$actUrl = base_url('play/act');
?>
<ul class="play-inbox">
    <?php if ($list === []): ?>
        <li class="play-inbox-empty">Входящих нет.</li>
    <?php endif ?>
    <?php foreach ($list as $item): ?>
        <?php
        $msg       = $item['msg'];
        $parse     = is_string($msg['parse_mode'] ?? null) ? $msg['parse_mode'] : null;
        $text      = is_string($msg['text'] ?? null) && $msg['text'] !== '' ? $msg['text'] : (is_string($msg['caption'] ?? null) ? $msg['caption'] : '');
        $messageId = is_int($msg['message_id'] ?? null) ? $msg['message_id'] : null;
        $unread    = ($item['read'] ?? false) !== true;
        $created   = is_string($item['created_at'] ?? null) ? $item['created_at'] : '';
        $rows      = is_array($msg['inline_keyboard'] ?? null) ? $msg['inline_keyboard'] : [];
        ?>
        <li class="play-inbox-item<?= $unread ? ' is-unread' : '' ?>">
            <span class="play-inbox-title"><?= $unread ? 'Новое' : 'Прочитано' ?></span>
            <time class="play-inbox-time" datetime="<?= esc($created, 'attr') ?>"><?= esc($created) ?></time>
            <div class="play-inbox-body">
                <div class="play-msg-text"><?= TelegramMarkupRenderer::toHtml($text, $parse) ?></div>
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
            </div>
        </li>
    <?php endforeach ?>
</ul>

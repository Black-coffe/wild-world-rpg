<?php

declare(strict_types=1);

namespace App\Services\Web;

use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Database\ResultInterface;
use Config\Database;
use Config\WebPlay;
use Longman\TelegramBot\Entities\Entity;
use Longman\TelegramBot\Entities\ServerResponse;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * web-bridge-p1-04 (ADR-189 §2, §4, §5) — слой (а): решение по каждому вызову Bot API.
 *
 * {@see \App\Services\Telegram\Request::send()} спрашивает {@see route()} после KeyboardNormalizer и
 * до отправки. Три случая:
 *
 * - **Чат актора во время захвата** (`/play`): вызов записывается в экран, в Telegram не уходит.
 *   `message_id` синтетический ({@see WebScreenStore::nextMessageId()}), ответ — синтетический ok.
 *   Правка сообщения, которого нет ни в захвате, ни на экране, ни во входящих, отвечает
 *   «message to edit not found» — чтобы сработал запасной путь «edit → send» вызывающего (R5).
 * - **Фоновая правка/удаление синтетического id** (`message_id` ≥ `WebPlay::firstMessageId`, plan
 *   A16) в чат, за которым стоит персонаж: такого сообщения в Telegram нет, поэтому в Telegram не
 *   уходит ничего — ни виртуальному, ни привязанному. При включённом флаге правка заменяет копию
 *   на экране или в истории на месте (без подъёма в экран) и обновляет одну строку входящих
 *   (снова непрочитано). Ответ — синтетический ok, так что запасной «edit → send» не срабатывает.
 * - **Виртуальный чат** ({@see VirtualChat::is()}): `send*` → строка входящих `virtual` (при
 *   включённом флаге), `edit*`/`delete*`/прочее отбрасываются. Ответ — синтетический ok: доставка во
 *   входящие тоже доставка. В Telegram не уходит ничего при любом флаге.
 * - **Иначе** — `null`: вызов идёт в Telegram как раньше. Если это `send*` игроку, который не актор
 *   и заходил на сайт (plan A5), и флаг включён — копия во входящие (`mirror`).
 *
 * Фото: адрес снимается с потока ДО того, как Longman его закроет. http(s)-URL — как есть, файл
 * под `public/` — URL сайта, прочее — `photo_url = null`, подпись остаётся целиком (plan A12).
 *
 * Ошибки записи во входящие логируются и никогда не ломают отправку в Telegram.
 *
 * @phpstan-import-type Msg from WebScreenStore
 * @phpstan-import-type Button from WebScreenStore
 * @phpstan-import-type Capture from WebScreenStore
 */
final class WebDelivery
{
    public const FLAG = 'web.play_enabled';

    private static bool $capturing = false;

    private static int $actorChat = 0;

    private static int $characterId = 0;

    /** @var Capture */
    private static array $buffer = [
        'sent' => [], 'edited' => [], 'deleted' => [], 'alert' => null,
        'dock' => null, 'dock_removed' => false, 'input' => null,
    ];

    private static ?WebScreenStore $store = null;

    private static ?WebInboxService $inbox = null;

    public static function beginCapture(int $actorChatId, int $characterId): void
    {
        self::$capturing   = true;
        self::$actorChat   = $actorChatId;
        self::$characterId = $characterId;
        self::$buffer      = self::emptyBuffer();
    }

    /**
     * Закончить захват и отдать накопленное. Идемпотентно (зовётся из `finally`): повторный вызов
     * отдаёт пустой захват.
     *
     * @return Capture
     */
    public static function endCapture(): array
    {
        $out               = self::$buffer;
        self::$capturing   = false;
        self::$actorChat   = 0;
        self::$characterId = 0;
        self::$buffer      = self::emptyBuffer();

        return $out;
    }

    public static function isCapturing(): bool
    {
        return self::$capturing;
    }

    public static function reset(): void
    {
        self::endCapture();
        self::$store = null;
        self::$inbox = null;
    }

    /** Подмена хранилищ (тесты). Null — вернуть настоящие. */
    public static function useServices(?WebScreenStore $store, ?WebInboxService $inbox = null): void
    {
        self::$store = $store;
        self::$inbox = $inbox;
    }

    /**
     * Точка решения слоя (а). Null — отправлять в Telegram; иначе — готовый ответ, в Telegram
     * ничего не уходит.
     *
     * @param array<string,mixed> $data
     */
    public static function route(string $action, array $data): ?ServerResponse
    {
        $kind   = self::kind($action);
        $chatId = self::chatIdOf($data);

        if (self::$capturing && ($chatId === self::$actorChat || ($chatId === null && $kind === 'alert'))) {
            return new ServerResponse(self::capture($action, $data, $kind), '');
        }

        if ($chatId !== null && ($kind === 'edit' || $kind === 'delete') && self::isSyntheticId($data['message_id'] ?? null)) {
            $absorbed = self::backgroundSynthetic($chatId, $action, $data, $kind);
            if ($absorbed !== null) {
                return new ServerResponse($absorbed, '');
            }
        }

        if ($chatId !== null && VirtualChat::is($chatId)) {
            $messageId = $kind === 'send' ? self::toInbox($chatId, $action, $data, WebInboxService::SOURCE_VIRTUAL, false) : 0;

            return new ServerResponse(
                $kind === 'send' ? self::messageResult($messageId, $chatId, self::buildMsg($action, $data, $messageId)) : self::okTrue(),
                ''
            );
        }

        if ($kind === 'send' && $chatId !== null && $chatId !== DeliveryContext::actor()) {
            self::toInbox($chatId, $action, $data, WebInboxService::SOURCE_MIRROR, true);
        }

        return null;
    }

    /**
     * Захват в обход слоя (а) — из {@see BridgeClient}. Поля уже плоские (текст/подпись/id), поэтому
     * получается экран «только подпись». Возвращает сырой ответ Bot API.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public static function captureBypass(string $action, array $fields): array
    {
        if (! self::$capturing) {
            return self::okTrue();
        }

        return self::capture($action, $fields, self::kind($action));
    }

    // ── захват ───────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function capture(string $action, array $data, string $kind): array
    {
        try {
            switch ($kind) {
                case 'send':
                    return self::captureSend($action, $data);
                case 'edit':
                    return self::captureEdit($action, $data);
                case 'delete':
                    $id = self::intOf($data['message_id'] ?? null);
                    if ($id !== null) {
                        self::captureDelete($id);
                    }

                    return self::okTrue();
                case 'alert':
                    $text = $data['text'] ?? null;
                    if (is_string($text) && $text !== '') {
                        self::$buffer['alert'] = $text;
                    }

                    return self::okTrue();
                default:
                    return self::okTrue();
            }
        } catch (Throwable $e) {
            log_message('error', '[WebDelivery] capture ' . $action . ' failed: ' . $e->getMessage());

            return ['ok' => false, 'error_code' => 500, 'description' => 'web capture failed'];
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function captureSend(string $action, array $data): array
    {
        $id  = self::store()->nextMessageId(self::$characterId);
        $msg = self::buildMsg($action, $data, $id);

        $markup = self::markup($data['reply_markup'] ?? null);
        if (isset($markup['keyboard']) && is_array($markup['keyboard'])) {
            self::$buffer['dock'] = self::dockRows($markup['keyboard']);
        }
        if (! empty($markup['remove_keyboard'])) {
            self::$buffer['dock']         = null;
            self::$buffer['dock_removed'] = true;
        }
        if (! empty($markup['force_reply'])) {
            $ph                    = $markup['input_field_placeholder'] ?? '';
            self::$buffer['input'] = ['placeholder' => is_string($ph) ? $ph : '', 'reply_to' => $id];
        }

        self::$buffer['sent'][] = $msg;

        return self::messageResult($id, self::$actorChat, $msg);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function captureEdit(string $action, array $data): array
    {
        $id = self::intOf($data['message_id'] ?? null);
        if ($id === null) {
            return self::okTrue();
        }

        foreach (self::$buffer['sent'] as $i => $msg) {
            if ($msg['message_id'] === $id) {
                $edited                     = self::applyEdit($msg, $action, $data);
                self::$buffer['sent'][$i] = $edited;

                return self::messageResult($id, self::$actorChat, $edited);
            }
        }

        $base = self::$buffer['edited'][$id] ?? self::store()->findMessage(self::$characterId, $id);
        if ($base !== null) {
            $edited                      = self::applyEdit($base, $action, $data);
            self::$buffer['edited'][$id] = $edited;

            return self::messageResult($id, self::$actorChat, $edited);
        }

        // Правка сообщения из входящих: показываем его экраном (id сохраняется).
        $fromInbox = self::inbox()->findMessage(self::$characterId, $id);
        if ($fromInbox !== null) {
            $edited                 = self::applyEdit($fromInbox, $action, $data);
            self::$buffer['sent'][] = $edited;

            return self::messageResult($id, self::$actorChat, $edited);
        }

        return ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message to edit not found'];
    }

    private static function captureDelete(int $id): void
    {
        $before                 = count(self::$buffer['sent']);
        self::$buffer['sent']   = array_values(array_filter(
            self::$buffer['sent'],
            static fn (array $m): bool => $m['message_id'] !== $id
        ));
        unset(self::$buffer['edited'][$id]);
        if (count(self::$buffer['sent']) === $before) {
            self::$buffer['deleted'][] = $id;
        }
    }

    // ── фоновая правка синтетического id (plan A16) ──────────────────────

    private static function isSyntheticId(mixed $raw): bool
    {
        $id = self::intOf($raw);

        return $id !== null && $id >= (new WebPlay())->firstMessageId;
    }

    /**
     * Правка/удаление синтетического id вне захвата. Null — чат не ведёт к персонажу, решает
     * общий путь. Иначе — ответ без Telegram. Никогда не бросает.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    private static function backgroundSynthetic(int $chatId, string $action, array $data, string $kind): ?array
    {
        try {
            $characterId = self::characterForChat($chatId, false);
            if ($characterId === null) {
                return null;
            }
            if ($kind !== 'edit' || ! self::flagEnabled()) {
                return self::okTrue();
            }

            $id     = (int) self::intOf($data['message_id'] ?? null);
            $base   = self::store()->findMessage($characterId, $id)
                ?? self::inbox()->findMessage($characterId, $id)
                ?? self::buildMsg('', [], $id);
            $edited = self::applyEdit($base, $action, $data);

            self::store()->patchMessage($characterId, $id, $edited);
            self::inbox()->upsertEdit(
                $characterId,
                $id,
                $edited,
                VirtualChat::is($chatId) ? WebInboxService::SOURCE_VIRTUAL : WebInboxService::SOURCE_MIRROR
            );

            return self::messageResult($id, $chatId, $edited);
        } catch (Throwable $e) {
            log_message('error', '[WebDelivery] background ' . $action . ' of web message failed: ' . $e->getMessage());

            return self::okTrue();
        }
    }

    // ── входящие ─────────────────────────────────────────────────────────

    /**
     * Записать сообщение во входящие персонажа этого чата (флаг включён). Возвращает `message_id`
     * строки, 0 — ничего не записано. Никогда не бросает.
     *
     * @param array<string,mixed> $data
     */
    private static function toInbox(int $chatId, string $action, array $data, string $source, bool $linkedOnly): int
    {
        try {
            if (! self::flagEnabled()) {
                return 0;
            }
            $characterId = self::characterForChat($chatId, $linkedOnly);
            if ($characterId === null) {
                return 0;
            }
            $id = self::store()->nextMessageId($characterId);
            self::inbox()->append($characterId, self::buildMsg($action, $data, $id), $source);

            return $id;
        } catch (Throwable $e) {
            log_message('error', '[WebDelivery] inbox ' . $source . ' failed: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * Персонаж чата. `$linkedOnly` — только если его аккаунт хоть раз входил на сайт (plan A5).
     */
    private static function characterForChat(int $chatId, bool $linkedOnly): ?int
    {
        $sql = 'SELECT c.id FROM telegram_users t JOIN characters c ON c.telegram_user_id = t.id';
        if ($linkedOnly) {
            $sql .= ' JOIN accounts a ON a.id = c.account_id AND a.last_login_at IS NOT NULL';
        }
        $sql .= ' WHERE t.telegram_id = ? ORDER BY c.id LIMIT 1';

        $res = Database::connect()->query($sql, [$chatId]);
        $row = $res instanceof ResultInterface ? $res->getRowArray() : null;

        return is_array($row) && is_numeric($row['id'] ?? null) ? (int) $row['id'] : null;
    }

    private static function flagEnabled(): bool
    {
        $raw = (new GameSettingsService())->get(self::FLAG, false);
        if (is_bool($raw)) {
            return $raw;
        }

        return is_numeric($raw) && (int) $raw === 1;
    }

    // ── разбор вызова ────────────────────────────────────────────────────

    /** send | edit | delete | alert | other */
    private static function kind(string $action): string
    {
        if ($action === 'sendChatAction') {
            return 'other';
        }
        if (str_starts_with($action, 'send')) {
            return 'send';
        }
        if (str_starts_with($action, 'editMessage')) {
            return 'edit';
        }
        if ($action === 'deleteMessage' || $action === 'deleteMessages') {
            return 'delete';
        }

        return $action === 'answerCallbackQuery' ? 'alert' : 'other';
    }

    /** @param array<string,mixed> $data */
    public static function chatIdOf(array $data): ?int
    {
        return self::intOf($data['chat_id'] ?? null);
    }

    private static function intOf(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^-?\d{1,19}$/', $v) === 1) {
            return (int) $v;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $data
     * @return Msg
     */
    private static function buildMsg(string $action, array $data, int $id): array
    {
        return [
            'message_id'      => $id,
            'text'            => self::strOrNull($data['text'] ?? null),
            'caption'         => self::strOrNull($data['caption'] ?? null),
            'parse_mode'      => self::strOrNull($data['parse_mode'] ?? null),
            'photo_url'       => $action === 'sendPhoto' ? self::photoUrl($data['photo'] ?? null) : null,
            'inline_keyboard' => self::inlineKeyboard($data['reply_markup'] ?? null),
        ];
    }

    /**
     * @param Msg                 $msg
     * @param array<string,mixed> $data
     * @return Msg
     */
    private static function applyEdit(array $msg, string $action, array $data): array
    {
        $keyboard = self::inlineKeyboard($data['reply_markup'] ?? null);
        switch ($action) {
            case 'editMessageText':
                $msg['text']       = self::strOrNull($data['text'] ?? null);
                $msg['parse_mode'] = self::strOrNull($data['parse_mode'] ?? null);
                $msg['caption']    = null;
                $msg['photo_url']  = null;
                break;
            case 'editMessageCaption':
                $msg['caption']    = self::strOrNull($data['caption'] ?? null);
                $msg['parse_mode'] = self::strOrNull($data['parse_mode'] ?? null);
                break;
            case 'editMessageMedia':
                $media             = self::entityArray($data['media'] ?? null);
                $msg['photo_url']  = self::photoUrl($media['media'] ?? null);
                $msg['caption']    = self::strOrNull($media['caption'] ?? null);
                $msg['parse_mode'] = self::strOrNull($media['parse_mode'] ?? null);
                $msg['text']       = null;
                break;
        }
        $msg['inline_keyboard'] = $keyboard;

        return $msg;
    }

    /** @return array<string,mixed>|null */
    private static function markup(mixed $raw): ?array
    {
        $arr = self::entityArray($raw);

        return $arr === [] ? null : $arr;
    }

    /**
     * Entity Longman / массив / JSON-строка → массив. Ресурсы внутри Entity не сериализуются,
     * поэтому берём `raw_data`, а не json_encode.
     *
     * @return array<string,mixed>
     */
    private static function entityArray(mixed $raw): array
    {
        if ($raw instanceof Entity) {
            $raw = $raw->raw_data;
        } elseif (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /** @return list<list<Button>> */
    private static function inlineKeyboard(mixed $rawMarkup): array
    {
        $markup = self::markup($rawMarkup);
        $rows   = $markup['inline_keyboard'] ?? null;
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $buttons = [];
            foreach ($row as $btn) {
                $b    = self::entityArray($btn instanceof Entity ? $btn : (is_array($btn) ? $btn : null));
                $text = $b['text'] ?? null;
                if (! is_string($text)) {
                    continue;
                }
                $button = ['text' => $text];
                if (is_string($b['callback_data'] ?? null)) {
                    $button['callback_data'] = $b['callback_data'];
                }
                if (is_string($b['url'] ?? null)) {
                    $button['url'] = $b['url'];
                }
                $buttons[] = $button;
            }
            if ($buttons !== []) {
                $out[] = $buttons;
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $rows
     * @return list<list<string>>
     */
    private static function dockRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $labels = [];
            foreach ($row as $btn) {
                $text = is_string($btn) ? $btn : (self::entityArray($btn instanceof Entity ? $btn : (is_array($btn) ? $btn : null))['text'] ?? null);
                if (is_string($text) && $text !== '') {
                    $labels[] = $text;
                }
            }
            if ($labels !== []) {
                $out[] = $labels;
            }
        }

        return $out;
    }

    /**
     * Адрес фото для экрана (plan A12): http(s) — как есть, файл под `public/` — URL сайта,
     * иначе null (подпись остаётся). Поток читается по метаданным, не закрывается.
     */
    public static function photoUrl(mixed $photo): ?string
    {
        $path = null;
        if (is_resource($photo)) {
            $uri  = stream_get_meta_data($photo)['uri'] ?? null;
            $path = is_string($uri) ? $uri : null;
        } elseif ($photo instanceof StreamInterface) {
            $uri  = $photo->getMetadata('uri');
            $path = is_string($uri) ? $uri : null;
        } elseif (is_string($photo)) {
            if (preg_match('#^https?://#i', $photo) === 1) {
                return $photo;
            }
            $path = $photo;
        }
        if ($path === null || $path === '') {
            return null;
        }

        $real   = realpath($path);
        $public = realpath(FCPATH);
        if ($real === false || $public === false || ! is_file($real)) {
            return null;
        }
        $prefix = rtrim($public, '/\\') . DIRECTORY_SEPARATOR;
        if (! str_starts_with($real, $prefix)) {
            return null;
        }

        helper('url');

        return base_url(str_replace('\\', '/', substr($real, strlen($prefix))));
    }

    private static function strOrNull(mixed $v): ?string
    {
        return is_string($v) ? $v : (is_int($v) || is_float($v) ? (string) $v : null);
    }

    // ── ответы ───────────────────────────────────────────────────────────

    /**
     * @param Msg $msg
     * @return array<string,mixed>
     */
    private static function messageResult(int $messageId, int $chatId, array $msg): array
    {
        $result = [
            'message_id' => $messageId,
            'chat'       => ['id' => $chatId, 'type' => 'private'],
            'date'       => time(),
        ];
        if ($msg['text'] !== null) {
            $result['text'] = $msg['text'];
        }
        if ($msg['caption'] !== null) {
            $result['caption'] = $msg['caption'];
        }

        return ['ok' => true, 'result' => $result];
    }

    /** @return array<string,mixed> */
    private static function okTrue(): array
    {
        return ['ok' => true, 'result' => true];
    }

    /** @return Capture */
    private static function emptyBuffer(): array
    {
        return [
            'sent' => [], 'edited' => [], 'deleted' => [], 'alert' => null,
            'dock' => null, 'dock_removed' => false, 'input' => null,
        ];
    }

    private static function store(): WebScreenStore
    {
        return self::$store ??= new WebScreenStore();
    }

    private static function inbox(): WebInboxService
    {
        return self::$inbox ??= new WebInboxService();
    }
}

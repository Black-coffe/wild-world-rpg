<?php

declare(strict_types=1);

namespace App\Services\Web;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use Config\Database;
use Config\WebPlay;

/**
 * web-bridge-p1-04 (ADR-189 §9) — экран `/play` персонажа в `web_play_state`.
 *
 * Экран — список сообщений одного ответа бота. Новая отправка делает экран текущим, прошлый
 * уходит в историю (новые первыми, не больше `historySize`). Правка заменяет своё сообщение
 * там, где оно лежит: в текущем экране или в истории. Reply-клавиатура — док, force-reply — поле
 * ввода. Синтетические `message_id` выдаются по персонажу монотонно, с `firstMessageId`.
 *
 * @phpstan-type Button array{text:string, callback_data?:string, url?:string}
 * @phpstan-type Msg array{message_id:int, text:?string, caption:?string, parse_mode:?string, photo_url:?string, inline_keyboard:list<list<Button>>}
 * @phpstan-type Input array{placeholder:string, reply_to:int}
 * @phpstan-type Capture array{sent:list<Msg>, edited:array<int,Msg>, deleted:list<int>, alert:?string, dock:?list<list<string>>, dock_removed:bool, input:?Input}
 * @phpstan-type State array{screen:list<Msg>, history:list<list<Msg>>, dock:list<list<string>>, input:?Input}
 */
class WebScreenStore
{
    /** @var BaseConnection<object, object> */
    private BaseConnection $db;

    private WebPlay $config;

    /**
     * @param BaseConnection<object, object>|null $db
     */
    public function __construct(?BaseConnection $db = null, ?WebPlay $config = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->config = $config ?? new WebPlay();
    }

    /**
     * Следующий синтетический `message_id` персонажа. Атомарно: `LAST_INSERT_ID(expr)` в одном
     * UPDATE отдаёт выданное значение этому соединению, параллельный запрос получит следующее.
     */
    public function nextMessageId(int $characterId): int
    {
        $this->ensureRow($characterId);
        $this->db->query(
            'UPDATE web_play_state SET next_message_id = LAST_INSERT_ID(next_message_id) + 1 WHERE character_id = ?',
            [$characterId]
        );
        $row = $this->row('SELECT LAST_INSERT_ID() AS id');
        $id  = is_array($row) && is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
        if ($id <= 0) {
            throw new \RuntimeException("WebScreenStore: message id allocation failed for character {$characterId}");
        }

        return $id;
    }

    /** @return State */
    public function state(int $characterId): array
    {
        $row = $this->row(
            'SELECT screen, history, dock, input FROM web_play_state WHERE character_id = ?',
            [$characterId]
        );
        if (! is_array($row)) {
            return ['screen' => [], 'history' => [], 'dock' => [], 'input' => null];
        }

        /** @var list<Msg> $screen */
        $screen = self::decodeList($row['screen'] ?? null);
        /** @var list<list<Msg>> $history */
        $history = self::decodeList($row['history'] ?? null);
        /** @var list<list<string>> $dock */
        $dock  = self::decodeList($row['dock'] ?? null);
        $input = self::decodeInput($row['input'] ?? null);

        return ['screen' => $screen, 'history' => $history, 'dock' => $dock, 'input' => $input];
    }

    /**
     * Применить захват одного действия к экрану.
     *
     * @param Capture $capture
     */
    public function applyCapture(int $characterId, array $capture): void
    {
        $state   = $this->state($characterId);
        $screen  = $state['screen'];
        $history = $state['history'];

        // Правка заменяет сообщение там, где оно лежит.
        foreach ($capture['edited'] as $id => $msg) {
            $screen = self::replaceIn($screen, $id, $msg);
            foreach ($history as $i => $old) {
                $history[$i] = self::replaceIn($old, $id, $msg);
            }
        }

        foreach ($capture['deleted'] as $id) {
            $screen  = self::removeFrom($screen, $id);
            $history = array_values(array_filter(
                array_map(static fn (array $s): array => self::removeFrom($s, $id), $history),
                static fn (array $s): bool => $s !== []
            ));
        }

        $input = $state['input'];
        if ($capture['sent'] !== []) {
            if ($screen !== []) {
                array_unshift($history, $screen);
            }
            $history = array_slice($history, 0, max(0, $this->config->historySize));
            $screen  = $capture['sent'];
            $input   = $capture['input'];
        } elseif ($capture['input'] !== null) {
            $input = $capture['input'];
        }

        $dock = $state['dock'];
        if ($capture['dock_removed']) {
            $dock = [];
        }
        if ($capture['dock'] !== null) {
            $dock = $capture['dock'];
        }

        $this->ensureRow($characterId);
        $this->db->table('web_play_state')->where('character_id', $characterId)->update([
            'screen'     => json_encode($screen, JSON_UNESCAPED_UNICODE),
            'history'    => json_encode($history, JSON_UNESCAPED_UNICODE),
            'dock'       => json_encode($dock, JSON_UNESCAPED_UNICODE),
            'input'      => $input === null ? null : json_encode($input, JSON_UNESCAPED_UNICODE),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return Msg|null */
    public function findMessage(int $characterId, int $messageId): ?array
    {
        $state = $this->state($characterId);
        foreach (array_merge([$state['screen']], $state['history']) as $screen) {
            foreach ($screen as $msg) {
                if ($msg['message_id'] === $messageId) {
                    return $msg;
                }
            }
        }

        return null;
    }

    /**
     * Callback из веба разрешён, только если его `callback_data` стоит на кнопке экрана,
     * истории или входящих этого персонажа (ADR-189 §6, инвариант 3).
     */
    public function callbackAllowed(int $characterId, string $data): bool
    {
        if ($data === '') {
            return false;
        }
        $state = $this->state($characterId);
        foreach (array_merge([$state['screen']], $state['history']) as $screen) {
            foreach ($screen as $msg) {
                if (self::hasCallback($msg, $data)) {
                    return true;
                }
            }
        }

        return (new WebInboxService($this->db, $this->config))->hasCallback($characterId, $data);
    }

    /** @param Msg $msg */
    public static function hasCallback(array $msg, string $data): bool
    {
        foreach ($msg['inline_keyboard'] as $row) {
            foreach ($row as $button) {
                if (($button['callback_data'] ?? null) === $data) {
                    return true;
                }
            }
        }

        return false;
    }

    private function ensureRow(int $characterId): void
    {
        $this->db->query(
            'INSERT IGNORE INTO web_play_state (character_id, next_message_id, updated_at) VALUES (?, ?, ?)',
            [$characterId, $this->config->firstMessageId, date('Y-m-d H:i:s')]
        );
    }

    /**
     * @param list<Msg> $screen
     * @param Msg       $msg
     * @return list<Msg>
     */
    private static function replaceIn(array $screen, int $id, array $msg): array
    {
        foreach ($screen as $i => $old) {
            if ($old['message_id'] === $id) {
                $screen[$i] = $msg;
            }
        }

        return $screen;
    }

    /**
     * @param list<Msg> $screen
     * @return list<Msg>
     */
    private static function removeFrom(array $screen, int $id): array
    {
        return array_values(array_filter($screen, static fn (array $m): bool => $m['message_id'] !== $id));
    }

    /** @return list<mixed> */
    private static function decodeList(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @return Input|null */
    private static function decodeInput(mixed $raw): ?array
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! is_numeric($decoded['reply_to'] ?? null)) {
            return null;
        }
        $placeholder = $decoded['placeholder'] ?? '';

        return ['placeholder' => is_string($placeholder) ? $placeholder : '', 'reply_to' => (int) $decoded['reply_to']];
    }

    /**
     * @param list<int|string|null> $binds
     * @return array<string,mixed>|null
     */
    private function row(string $sql, array $binds = []): ?array
    {
        $res = $this->db->query($sql, $binds);
        if (! $res instanceof ResultInterface) {
            return null;
        }
        $r = $res->getRowArray();
        if (! is_array($r)) {
            return null;
        }
        $out = [];
        foreach ($r as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }
}

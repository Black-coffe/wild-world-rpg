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
 * уходит в историю (новые первыми, не больше `historySize`). Правка сообщения текущего экрана
 * заменяет его на месте; правка сообщения из истории делает его текущим экраном (прошлый экран —
 * в историю, старая копия из истории убирается). Reply-клавиатура — док, force-reply — поле
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
        return $this->readState($characterId, false) ?? ['screen' => [], 'history' => [], 'dock' => [], 'input' => null];
    }

    /**
     * Состояние из строки `web_play_state`; null — строки нет. `$forUpdate` — блокирующее чтение
     * (только внутри {@see guarded()}).
     *
     * @return State|null
     */
    private function readState(int $characterId, bool $forUpdate): ?array
    {
        $row = $this->row(
            'SELECT screen, history, dock, input FROM web_play_state WHERE character_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$characterId]
        );
        if (! is_array($row)) {
            return null;
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
        $this->ensureRow($characterId);
        $this->guarded($characterId, function (array $state) use ($characterId, $capture): bool {
            $this->applyCaptureTo($characterId, $state, $capture);

            return true;
        });
    }

    /**
     * Фоновая правка (plan A16): заменить сообщение на месте — в текущем экране или в истории,
     * без подъёма в экран (игрок не действовал). False — сообщения нет ни там, ни там.
     *
     * @param Msg $msg
     */
    public function patchMessage(int $characterId, int $messageId, array $msg): bool
    {
        return $this->guarded($characterId, function (array $state) use ($characterId, $messageId, $msg): bool {
            $screen  = $state['screen'];
            $history = $state['history'];
            $found   = self::contains($screen, $messageId);
            if ($found) {
                $screen = self::replaceIn($screen, $messageId, $msg);
            }
            foreach ($history as $i => $entry) {
                if (self::contains($entry, $messageId)) {
                    $history[$i] = self::replaceIn($entry, $messageId, $msg);
                    $found       = true;
                }
            }
            if (! $found) {
                return false;
            }
            $this->beforeWrite($characterId);
            $this->db->table('web_play_state')->where('character_id', $characterId)->update([
                'screen'     => json_encode($screen, JSON_UNESCAPED_UNICODE),
                'history'    => json_encode($history, JSON_UNESCAPED_UNICODE),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        });
    }

    /**
     * Guard записи экрана (manual review #6): read-modify-write в транзакции с блокирующим чтением
     * строки персонажа (`SELECT … FOR UPDATE`). Параллельный писатель того же персонажа ждёт на
     * чтении, пока эта транзакция не закоммитится, и читает уже новое состояние. Нет строки —
     * `$apply` не зовётся, результат false. Внутри внешней транзакции CI4 вкладывает (блокировка
     * держится до внешнего коммита).
     *
     * @param callable(State): bool $apply
     */
    private function guarded(int $characterId, callable $apply): bool
    {
        $statusBefore = $this->db->transStatus();
        $this->db->transBegin();
        try {
            $state  = $this->readState($characterId, true);
            $result = $state !== null && $apply($state);
            // Внутри транзакции CI4 не бросает на ошибке запроса (напр. lock wait timeout), а
            // гасит transStatus: иначе «нет строки» и тихо закоммиченная половина.
            if (! $this->db->transStatus()) {
                throw new \RuntimeException("WebScreenStore: screen write failed for character {$characterId}: " . json_encode($this->db->error()));
            }
            $this->db->transCommit();

            return $result;
        } catch (\Throwable $e) {
            $this->db->transRollback();
            if ($statusBefore) {
                $this->db->resetTransStatus();
            }

            throw $e;
        }
    }

    /** Шов для теста: между чтением и записью (manual review #6). */
    protected function beforeWrite(int $characterId): void
    {
    }

    /**
     * @param State   $state
     * @param Capture $capture
     */
    private function applyCaptureTo(int $characterId, array $state, array $capture): void
    {
        $screen  = $state['screen'];
        $history = $state['history'];

        // Правка сообщения текущего экрана заменяет его на месте. Правка сообщения из истории
        // делает его текущим экраном (как правка входящего): старая копия уходит из истории.
        $promoted = [];
        foreach ($capture['edited'] as $id => $msg) {
            if (self::contains($screen, $id)) {
                $screen = self::replaceIn($screen, $id, $msg);

                continue;
            }
            $before  = $history;
            $history = self::removeFromHistory($history, $id);
            if ($history !== $before) {
                $promoted[] = $msg;
            }
        }

        foreach ($capture['deleted'] as $id) {
            $screen   = self::removeFrom($screen, $id);
            $promoted = self::removeFrom($promoted, $id);
            $history  = self::removeFromHistory($history, $id);
        }

        $input = $state['input'];
        $next  = array_merge($promoted, $capture['sent']);
        if ($next !== []) {
            if ($screen !== []) {
                array_unshift($history, $screen);
            }
            $history = array_slice($history, 0, max(0, $this->config->historySize));
            $screen  = $next;
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

        $this->beforeWrite($characterId);
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

    /** @param list<Msg> $screen */
    private static function contains(array $screen, int $id): bool
    {
        foreach ($screen as $m) {
            if ($m['message_id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Убрать сообщение из истории; опустевшие записи выпадают.
     *
     * @param list<list<Msg>> $history
     * @return list<list<Msg>>
     */
    private static function removeFromHistory(array $history, int $id): array
    {
        return array_values(array_filter(
            array_map(static fn (array $s): array => self::removeFrom($s, $id), $history),
            static fn (array $s): bool => $s !== []
        ));
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

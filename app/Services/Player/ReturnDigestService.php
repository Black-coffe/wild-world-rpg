<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Services\GameSettings\GameSettingsReaderTrait;
use Config\Database;
use App\Services\Telegram\Request;

/**
 * ROADMAP-100 E6 (ADR-108) Фаза 2 — оффлайн-digest «пока тебя не было».
 *
 * При возврате игрока (первое взаимодействие после паузы ≥ grace_hours) шлёт сводку
 * «мир жил без тебя»: что достроилось / скрафтилось / добылось, кто нападал (авто-PvE),
 * какие события пережил персонаж — собрано из лог-таблиц за окно `[last_seen, now]`.
 *
 * Триггер — {@see \App\Controllers\Telegram\BotController::webhook()} ДО `handle()`
 * (last_seen ещё ПРЕДЫДУЩИЙ; стамп в finally ПОСЛЕ обработки → следующее взаимодействие
 * свежее → естественный one-shot per возврат, без доп. флага).
 *
 * Гейты: killswitch `returnability.digest.enabled` (default false → dormant) + давность
 * `returnability.digest.grace_hours` (default 24). Пустой digest (ничего не произошло)
 * не отправляется. MEDIA-OFF (ADR-020): текст самодостаточен.
 *
 * Overridable seam'ы (unit без БД/Telegram): enabled / graceHours / resolveContext /
 * collect / send. Чистый {@see format()} тестируется напрямую.
 *
 * @see [[ADR-108-Player-returnability-digest-streaks]]
 */
class ReturnDigestService
{
    use GameSettingsReaderTrait;

    /**
     * Главная точка: если игрок вернулся после паузы — собрать и прислать digest.
     */
    public function maybeSendDigest(int $telegramId, int $chatId): void
    {
        if ($telegramId <= 0 || $chatId === 0 || ! $this->enabled()) {
            return;
        }

        $ctx = $this->resolveContext($telegramId);
        if ($ctx === null) {
            return;
        }
        $charId   = $ctx['char_id'];
        $lastSeen = $ctx['last_seen'];

        $hours = LastSeenService::hoursAgo($lastSeen);
        if ($hours === null || $hours < $this->graceHours()) {
            return; // первый /start новичка (last_seen null) или возврат раньше grace
        }

        // $lastSeen здесь гарантированно непуст (hours !== null).
        $data = $this->collect($charId, (string) $lastSeen, date('Y-m-d H:i:s'));
        $text = self::format($data, $hours);
        if ($text === null) {
            return; // пока тебя не было — ничего примечательного, молчим
        }

        $this->send([
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'Markdown',
            'disable_web_page_preview' => true,
        ]);
    }

    // ── Overridable seam'ы ───────────────────────────────────────────────────

    protected function enabled(): bool
    {
        return $this->gsBool('returnability.digest.enabled', false);
    }

    protected function graceHours(): int
    {
        return max(1, $this->gsInt('returnability.digest.grace_hours', 24));
    }

    /**
     * Резолв персонажа + last_seen по telegram_id (join characters↔telegram_users).
     *
     * @return array{char_id:int,last_seen:?string}|null
     */
    protected function resolveContext(int $telegramId): ?array
    {
        try {
            $res = Database::connect()
                ->table('characters c')
                ->select('c.id AS char_id, tu.last_seen AS last_seen')
                ->join('telegram_users tu', 'tu.id = c.telegram_user_id', 'inner')
                ->where('tu.telegram_id', $telegramId)
                ->get();
            $row = $res === false ? null : $res->getRowArray();
        } catch (\Throwable $e) {
            log_message('error', '[ReturnDigestService] resolveContext: ' . $e->getMessage());
            return null;
        }
        if (! is_array($row) || ! isset($row['char_id']) || ! is_numeric($row['char_id'])) {
            return null;
        }
        $lastSeen = $row['last_seen'] ?? null;
        return [
            'char_id'   => (int) $row['char_id'],
            'last_seen' => is_string($lastSeen) && $lastSeen !== '' ? $lastSeen : null,
        ];
    }

    /**
     * Собрать «что произошло пока тебя не было» из лог-таблиц за окно [since, until].
     *
     * @return array{buildings:array<int,string>,crafts:int,gathers:int,attacks:int,attack_wins:int,events:array<int,string>}
     */
    protected function collect(int $charId, string $since, string $until): array
    {
        $db = Database::connect();

        // 1) Завершённые задачи в окне (по updated_at = момент пометки 'completed').
        $buildings = [];
        $crafts    = 0;
        $gathers   = 0;
        try {
            $res = $db->table('character_tasks ct')
                ->select('t.name AS name, t.name_rus AS name_rus, COUNT(*) AS cnt')
                ->join('tasks t', 't.id = ct.task_id', 'inner')
                ->where('ct.character_id', $charId)
                ->where('ct.status', 'completed')
                ->where('ct.updated_at >=', $since)
                ->where('ct.updated_at <=', $until)
                ->groupBy('t.id')
                ->get();
            $rows = $res === false ? [] : $res->getResultArray();
            foreach ($rows as $r) {
                $name = is_string($r['name'] ?? null) ? $r['name'] : '';
                $cnt  = is_numeric($r['cnt'] ?? null) ? (int) $r['cnt'] : 0;
                if ($cnt <= 0) {
                    continue;
                }
                if (str_starts_with($name, 'build') || str_starts_with($name, 'startBuild')) {
                    $label = is_string($r['name_rus'] ?? null) && $r['name_rus'] !== '' ? $r['name_rus'] : $name;
                    $buildings[] = $cnt > 1 ? "{$label} ×{$cnt}" : $label;
                } elseif (str_starts_with($name, 'craft')) {
                    $crafts += $cnt;
                } elseif ($name === 'Gather') {
                    $gathers += $cnt;
                }
                // Marching / HealthRegeneration / прочее — намеренно пропускаем (шум).
            }
        } catch (\Throwable $e) {
            log_message('error', '[ReturnDigestService] collect tasks: ' . $e->getMessage());
        }

        // 2) Авто-PvE: бои с участием персонажа в окне (battle_logs PVE).
        $attacks     = 0;
        $attackWins  = 0;
        try {
            $res = $db->table('battle_logs')
                ->select('winner_id')
                ->where('battle_type', 'PVE')
                ->groupStart()
                    ->where('player1_id', $charId)
                    ->orWhere('player2_id', $charId)
                ->groupEnd()
                ->where('created_at >=', $since)
                ->where('created_at <=', $until)
                ->get();
            $rows    = $res === false ? [] : $res->getResultArray();
            $attacks = count($rows);
            foreach ($rows as $r) {
                if (isset($r['winner_id']) && (int) $r['winner_id'] === $charId) {
                    $attackWins++;
                }
            }
        } catch (\Throwable $e) {
            log_message('error', '[ReturnDigestService] collect battles: ' . $e->getMessage());
        }

        // 3) Мировые события, затронувшие персонажа (event_effects_log → events.name).
        $events = [];
        try {
            $res = $db->table('event_effects_log eel')
                ->select('e.name AS name, COUNT(*) AS cnt')
                ->join('events e', 'e.event_id = eel.event_id', 'inner')
                ->where('eel.character_id', $charId)
                ->where('eel.event_time >=', $since)
                ->where('eel.event_time <=', $until)
                ->groupBy('e.event_id')
                ->orderBy('cnt', 'DESC')
                ->get();
            $rows = $res === false ? [] : $res->getResultArray();
            foreach ($rows as $r) {
                $name = is_string($r['name'] ?? null) ? $r['name'] : '';
                if ($name !== '') {
                    $events[] = $name;
                }
            }
        } catch (\Throwable $e) {
            log_message('error', '[ReturnDigestService] collect events: ' . $e->getMessage());
        }

        return [
            'buildings'   => $buildings,
            'crafts'      => $crafts,
            'gathers'     => $gathers,
            'attacks'     => $attacks,
            'attack_wins' => $attackWins,
            'events'      => $events,
        ];
    }

    /**
     * Overridable seam отправки (тесты подменяют — без Telegram на CI).
     *
     * @param array<string, mixed> $payload
     */
    protected function send(array $payload): void
    {
        Request::sendMessage($payload);
    }

    // ── Текст (pure) ─────────────────────────────────────────────────────────

    /**
     * Собрать текст digest из данных. null, если ничего примечательного (не шлём).
     *
     * @param array{buildings:array<int,string>,crafts:int,gathers:int,attacks:int,attack_wins:int,events:array<int,string>} $data
     */
    public static function format(array $data, int $hours): ?string
    {
        $lines = [];

        if ($data['buildings'] !== []) {
            $lines[] = '🏗 Достроено: ' . implode(', ', $data['buildings']);
        }
        if ($data['crafts'] > 0) {
            $lines[] = '⚒️ Завершён крафт: ' . $data['crafts'] . ' ' . self::plural($data['crafts'], 'предмет', 'предмета', 'предметов');
        }
        if ($data['gathers'] > 0) {
            $lines[] = '⛏ Добыча завершена: ' . $data['gathers'] . ' ' . self::plural($data['gathers'], 'раз', 'раза', 'раз');
        }
        if ($data['attacks'] > 0) {
            $a = $data['attacks'];
            $w = $data['attack_wins'];
            $lines[] = '⚔️ На тебя нападали: ' . $a . ' ' . self::plural($a, 'раз', 'раза', 'раз')
                . ($w > 0 ? " (отбился {$w})" : '');
        }
        if ($data['events'] !== []) {
            $lines[] = '🌪 Пережитые события: ' . implode(', ', $data['events']);
        }

        if ($lines === []) {
            return null;
        }

        $gap = self::humanGap($hours);

        return "🧭 *С возвращением, выживший.*\n\n"
            . "Пока тебя не было ({$gap}), остров не дремал:\n\n"
            . '• ' . implode("\n• ", $lines) . "\n\n"
            . '_— Роби. Загляни на базу и карту — проверь, всё ли на месте._';
    }

    /** Человекочитаемая давность: «N часов» / «N дней». */
    public static function humanGap(int $hours): string
    {
        if ($hours < 48) {
            return $hours . ' ' . self::plural($hours, 'час', 'часа', 'часов');
        }
        $days = intdiv($hours, 24);
        return $days . ' ' . self::plural($days, 'день', 'дня', 'дней');
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $mod100 = $n % 100;
        $mod10  = $n % 10;
        if ($mod100 > 10 && $mod100 < 20) {
            return $many;
        }
        if ($mod10 === 1) {
            return $one;
        }
        if ($mod10 > 1 && $mod10 < 5) {
            return $few;
        }
        return $many;
    }
}

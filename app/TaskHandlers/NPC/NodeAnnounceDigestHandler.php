<?php

declare(strict_types=1);

namespace App\TaskHandlers\NPC;

use App\Attributes\HandlerKey;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\TaskHandlers\BaseTaskHandler;
use CodeIgniter\Database\BaseResult;
use Config\Database;

/**
 * WB11 (ADR-137 «Узлы») — ежедневная «Сводка с пустоши» (дайджест анонсов о повергнутых узлах).
 *
 * Анонс на КАЖДЫЙ килл синхронным broadcast'ом отрезан (§0.3: self-DoS при ~147 достижимых). Вместо
 * него kill-путь копит квалифицирующие килы в `boss_kill_announce_queue`, а этот крон раз в сутки шлёт
 * ОДНУ обезличенную сводку (радист Корвин: «пал босс L{N} в районе [биом]» — без ника/точных X,Y →
 * анти-PvP-наводка) всем opted-in игрокам (`characters.node_announce_enabled=1`), затем потребляет очередь.
 *
 * Guard'ы (паттерн DailyTipBroadcastHandler): killswitch `world.nodes.announce_enabled` → hour-guard
 * `world.nodes.announce_digest_hour` → атомарный once/day DB-claim (durable, переживает cache:clear/деплой).
 * media-off самодостаточно (вся сводка — текст). Telegram через seam `sendDigest` (CI без бот-ключа не падает).
 */
#[HandlerKey(
    key: 'node_announce_digest',
    displayName: 'Узлы: «Сводка с пустоши» (WB11 дайджест анонсов)',
    description: 'everyMinute + hour/once-day guard + killswitch world.nodes.announce_enabled: раз в сутки шлёт opted-in игрокам обезличенную сводку о повергнутых узлах (boss_kill_announce_queue) и потребляет очередь. ADR-137.',
)]
class NodeAnnounceDigestHandler extends BaseTaskHandler
{
    use GameSettingsReaderTrait;

    private const MARKER_KEY     = 'world.nodes.announce_last_digest'; // DB once/day-claim (durable)
    private const THROTTLE_USEC  = 35000; // ~28 msg/sec < лимит Telegram 30/sec
    private const MAX_LINES      = 12;     // сколько узлов перечислить в сводке (остальное — «…и ещё N»)

    public function handle(array $task = []): void
    {
        $this->run();
    }

    public function run(): void
    {
        // 1. killswitch
        if (! $this->gsBool('world.nodes.announce_enabled', false)) {
            return;
        }
        // 2. hour-guard
        $hour = $this->gsInt('world.nodes.announce_digest_hour', 11);
        if ((int) date('G') !== $hour) {
            return;
        }
        // 3. once/day atomic claim (durable)
        if (! $this->claimToday()) {
            return;
        }

        $pending = $this->fetchPending();
        if ($pending === []) {
            return; // за сутки квалифицирующих килов не было
        }

        $hours = max(1, $this->gsInt('world.nodes.respawn_hours', 12));
        $text  = $this->buildDigestText($pending, $hours);

        $sent = 0;
        foreach ($this->fetchAudience() as $row) {
            $tgId = is_numeric($row['telegram_id'] ?? null) ? (int) $row['telegram_id'] : 0;
            if ($tgId === 0) {
                continue;
            }
            $this->sendDigest($tgId, $text);
            $sent++;
            usleep(self::THROTTLE_USEC);
        }

        $this->consume();
        log_message('info', '[NodeAnnounceDigest] сводка по ' . count($pending) . " узлам разослана {$sent} игрокам");
    }

    /**
     * Собрать текст сводки (media-off самодостаточно). Pure → тестируемо без БД.
     *
     * @param list<array{level:int,biome:string}> $entries отсортированы по level DESC
     */
    public function buildDigestText(array $entries, int $respawnHours): string
    {
        $text  = "📻 *Сводка с пустоши* — радист Корвин\n\n";
        $text .= "За минувшие сутки эфир принёс вести о павших узлах:\n";

        $shown = array_slice($entries, 0, self::MAX_LINES);
        foreach ($shown as $e) {
            $biome = $e['biome'] !== '' ? $e['biome'] : 'пустошь';
            $text .= "• L{$e['level']} — {$biome}\n";
        }
        $rest = count($entries) - count($shown);
        if ($rest > 0) {
            $text .= "…и ещё *{$rest}* " . $this->plural($rest, 'узел', 'узла', 'узлов') . " пали в эфире.\n";
        }

        $text .= "\n_Свято место пусто не бывает: примерно через {$respawnHours}ч точки займут новые носители, злее прежних._";

        return $text;
    }

    /**
     * Pending-килы за сутки: level + имя биома (LEFT JOIN biomes), по убыванию уровня. Overridable seam.
     *
     * @return list<array{level:int,biome:string}>
     */
    protected function fetchPending(): array
    {
        $q = Database::connect()->query(
            'SELECT q.boss_level, COALESCE(b.name, \'\') AS biome '
            . 'FROM boss_kill_announce_queue q '
            . 'LEFT JOIN biomes b ON b.id = q.biome_id '
            . "WHERE q.status = 'pending' "
            . 'ORDER BY q.boss_level DESC, q.id ASC'
        );
        if (! $q instanceof BaseResult) {
            return [];
        }
        $out = [];
        foreach ($q->getResultArray() as $r) {
            $out[] = [
                'level' => is_numeric($r['boss_level'] ?? null) ? (int) $r['boss_level'] : 0,
                'biome' => is_string($r['biome'] ?? null) ? $r['biome'] : '',
            ];
        }

        return $out;
    }

    /**
     * Opted-in аудитория (node_announce_enabled=1, достижима). Overridable seam.
     * @return list<array<string,mixed>>
     */
    protected function fetchAudience(): array
    {
        $q = Database::connect()->query(
            'SELECT tu.telegram_id '
            . 'FROM characters c '
            . 'JOIN telegram_users tu ON tu.id = c.telegram_user_id '
            . 'WHERE c.node_announce_enabled = 1 AND tu.blocked_at IS NULL'
        );
        if (! $q instanceof BaseResult) {
            return [];
        }

        return array_values($q->getResultArray());
    }

    /** Потребить очередь (DELETE разосланных pending-строк; TRANSIENT). Overridable seam. */
    protected function consume(): void
    {
        Database::connect()->table('boss_kill_announce_queue')->where('status', 'pending')->delete();
    }

    /**
     * Атомарно «забирает» дайджест за сегодня (паттерн DailyTipBroadcastHandler::claimToday).
     */
    protected function claimToday(): bool
    {
        $today = date('Y-m-d');
        $db    = Database::connect();

        $exists = $db->table('game_settings')->where('setting_key', self::MARKER_KEY)->countAllResults();
        if ($exists === 0) {
            try {
                $db->table('game_settings')->insert([
                    'setting_key'  => self::MARKER_KEY,
                    'value_type'   => 'string',
                    'value_string' => '',
                    'category'     => 'system',
                ]);
            } catch (\Throwable) {
                // гонка на вставке — строку создал параллельный процесс, ок.
            }
        }

        $db->table('game_settings')
            ->where('setting_key', self::MARKER_KEY)
            ->where('value_string !=', $today)
            ->update(['value_string' => $today]);

        return $db->affectedRows() === 1;
    }

    /** Seam отправки (тесты подменяют — CI без бот-ключа не падает). */
    protected function sendDigest(int $tgId, string $text): void
    {
        $this->safeSendMessage($tgId, $text, ['parse_mode' => 'Markdown']);
    }

    /** Русская плюрализация (1 узел / 2 узла / 5 узлов). */
    private function plural(int $n, string $one, string $few, string $many): string
    {
        $mod10  = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
            return $few;
        }

        return $many;
    }
}

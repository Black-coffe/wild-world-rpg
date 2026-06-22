<?php

declare(strict_types=1);

namespace App\TaskHandlers\NPC;

use App\Attributes\HandlerKey;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\TaskHandlers\BaseTaskHandler;
use CodeIgniter\Database\BaseResult;
use Config\Database;

/**
 * WB10 (ADR-137 «Узлы») — анти-кемп LOOT-LOCK: накопление dwell + warn-пинг + лок «крысятников».
 *
 * Кемпер засел вплотную к узлу и монополизирует его трофей. Крон everyMinute:
 *   1) СБРОС: удаляет boss_camp_state-строки персов, которые УШЛИ из радиуса узла (позиция, не online)
 *      → «сброс при первом тике вне радиуса». Оффлайн-кемпер, стоящий в радиусе, не удаляется (заморожен).
 *   2) НАКОПЛЕНИЕ: для ONLINE-персов (last_seen в окне) в радиусе `anticamp.radius` живого узла копит
 *      `dwell_minutes` (+1/тик, апсёрт). 🔴 Оффлайн НЕ копит (крон его пропускает) → «беззащитный
 *      оффлайн» соблюдён, alt-абьюз закрыт. Это аккумулятор, НЕ wall-clock (тот учитывал бы оффлайн).
 *   3) WARN: за `anticamp.warn_minutes` до порога — one-shot пинг от Роби (last_warned_at).
 *   4) LOCK: при dwell ≥ `anticamp.dwell_hours`×60 → `loot_locked_until` (kill даёт 0 трофеев + не
 *      killer для анонса; читает AntiCampService на kill-пути).
 *
 * Гейт killswitch `world.nodes.point_mode_enabled` (OFF → no-op; при OFF alive-точек нет). Telegram —
 * через overridable seam `sendWarn` (CI без бот-ключа не падает; урок taskhandler-init). Класс НЕ final.
 */
#[HandlerKey(
    key: 'anticamp_dwell',
    displayName: 'Узлы: анти-кемп LOOT-LOCK (WB10)',
    description: 'everyMinute + killswitch world.nodes.point_mode_enabled: копит online-dwell персов вплотную к узлу (anticamp.radius), warn-пинг за anticamp.warn_minutes до порога, при dwell ≥ anticamp.dwell_hours лочит лут (kill даёт 0 трофеев). Сброс при уходе из радиуса; оффлайн не копит. ADR-137.',
)]
class AntiCampDwellHandler extends BaseTaskHandler
{
    use GameSettingsReaderTrait;

    private const THROTTLE_USEC = 35000; // ~28 msg/sec < лимит Telegram 30/sec

    public function handle(array $task = []): void
    {
        $this->run();
    }

    public function run(): void
    {
        if (! $this->gsBool('world.nodes.point_mode_enabled', false)) {
            return; // dormant — узлов нет
        }
        $radius = max(0, $this->gsInt('world.nodes.anticamp.radius', 2));
        if ($radius <= 0) {
            return; // 0 = анти-кемп выключен
        }
        $dwellHours = max(1, $this->gsInt('world.nodes.anticamp.dwell_hours', 12));
        $warnMin    = max(0, $this->gsInt('world.nodes.anticamp.warn_minutes', 30));
        $activeMin  = max(1, $this->gsInt('world.nodes.anticamp.active_window_minutes', 30));
        $lockMin    = $dwellHours * 60;
        $now        = date('Y-m-d H:i:s');

        // (1) Сброс: ушедшие из радиуса узла строки удаляются (позиция, не online).
        $this->resetOutOfZone($radius);

        // (2-4) Накопление dwell + warn + lock для online-персов в радиусе живого узла.
        $rows     = $this->fetchInZone($radius, $activeMin);
        $warned   = 0;
        $lockUntil = date('Y-m-d H:i:s', time() + $dwellHours * 3600);

        foreach ($rows as $row) {
            $charId  = is_numeric($row['character_id'] ?? null) ? (int) $row['character_id'] : 0;
            $pointId = is_numeric($row['boss_point_id'] ?? null) ? (int) $row['boss_point_id'] : 0;
            $tgId    = is_numeric($row['telegram_id'] ?? null) ? (int) $row['telegram_id'] : 0;
            if ($charId <= 0 || $pointId <= 0) {
                continue;
            }

            $prevDwell = is_numeric($row['dwell_minutes'] ?? null) ? (int) $row['dwell_minutes'] : 0;
            $hadRow    = isset($row['dwell_minutes']); // isset()=false при NULL (LEFT JOIN без совпадения)
            $newDwell  = $prevDwell + 1;

            $this->upsertTick($charId, $pointId, $now);

            if ($newDwell >= $lockMin) {
                $this->setLock($charId, $pointId, $lockUntil);
                continue;
            }

            $alreadyWarned = $hadRow && ($row['last_warned_at'] ?? null) !== null;
            if (! $alreadyWarned && $warnMin > 0 && $newDwell >= ($lockMin - $warnMin)) {
                if ($tgId !== 0) {
                    $this->sendWarn($tgId);
                    usleep(self::THROTTLE_USEC);
                }
                $this->markWarned($charId, $pointId, $now);
                $warned++;
            }
        }

        if ($warned > 0) {
            log_message('info', "[AntiCampDwell] warn-пингов кемперам: {$warned}");
        }
    }

    /**
     * Удалить строки персов, ушедших из радиуса своего узла (Chebyshev, по позиции; online не важен —
     * оффлайн-кемпер в радиусе остаётся заморожен). Это «сброс при первом тике вне радиуса».
     */
    protected function resetOutOfZone(int $radius): void
    {
        Database::connect()->query(
            'DELETE cs FROM boss_camp_state cs '
            . 'JOIN boss_points bp ON bp.id = cs.boss_point_id '
            . 'JOIN characters c   ON c.id = cs.character_id '
            . 'JOIN map m          ON m.cell_number = c.cell_number '
            . 'WHERE ABS(m.coordinate_x - bp.coordinate_x) > ? OR ABS(m.coordinate_y - bp.coordinate_y) > ?',
            [$radius, $radius]
        );
    }

    /**
     * Online-персы в радиусе ЖИВОГО узла + их текущее dwell-состояние (LEFT JOIN → null если новый).
     * Только last_seen в окне (ADR-108) и достижимые (blocked_at IS NULL). Overridable seam (тесты).
     *
     * @return list<array<string,mixed>>
     */
    protected function fetchInZone(int $radius, int $activeMin): array
    {
        $q = Database::connect()->query(
            'SELECT c.id AS character_id, tu.telegram_id, bp.id AS boss_point_id, '
            . 'cs.dwell_minutes, cs.last_warned_at, cs.loot_locked_until '
            . 'FROM characters c '
            . 'JOIN telegram_users tu ON tu.id = c.telegram_user_id '
            . 'JOIN map m            ON m.cell_number = c.cell_number '
            . "JOIN boss_points bp   ON bp.status = 'alive' "
            . 'AND ABS(m.coordinate_x - bp.coordinate_x) <= ? AND ABS(m.coordinate_y - bp.coordinate_y) <= ? '
            . 'LEFT JOIN boss_camp_state cs ON cs.character_id = c.id AND cs.boss_point_id = bp.id '
            . 'WHERE tu.last_seen >= NOW() - INTERVAL ' . $activeMin . ' MINUTE '
            . 'AND tu.blocked_at IS NULL',
            [$radius, $radius]
        );

        if (! $q instanceof BaseResult) {
            return [];
        }

        return array_values($q->getResultArray());
    }

    /** Апсёрт тика: новый → dwell=1 + first_seen=now; существующий → dwell+1 (first_seen/warn/lock не трогаем). */
    protected function upsertTick(int $charId, int $pointId, string $now): void
    {
        Database::connect()->query(
            'INSERT INTO boss_camp_state '
            . '(character_id, boss_point_id, first_seen_in_zone_at, dwell_minutes, loot_locked_until, last_warned_at) '
            . 'VALUES (?, ?, ?, 1, NULL, NULL) '
            . 'ON DUPLICATE KEY UPDATE dwell_minutes = dwell_minutes + 1',
            [$charId, $pointId, $now]
        );
    }

    /** Выставить/обновить loot-lock (рефрешится каждый тик пока кемпит; сброс — уход из радиуса). */
    protected function setLock(int $charId, int $pointId, string $lockUntil): void
    {
        Database::connect()->table('boss_camp_state')
            ->where('character_id', $charId)->where('boss_point_id', $pointId)
            ->update(['loot_locked_until' => $lockUntil]);
    }

    /** Отметить отправленный warn-пинг (one-shot per текущий dwell). */
    protected function markWarned(int $charId, int $pointId, string $now): void
    {
        Database::connect()->table('boss_camp_state')
            ->where('character_id', $charId)->where('boss_point_id', $pointId)
            ->update(['last_warned_at' => $now]);
    }

    /**
     * Overridable seam отправки warn-пинга (тесты подменяют — CI без бот-ключа не падает; урок
     * feedback_taskhandler_telegram_init_in_tests). Тон Роби, media-off (весь смысл в тексте).
     */
    protected function sendWarn(int $tgId): void
    {
        $text = "📡 *Сводка сети — Роби.*\n\n"
            . "Ты слишком долго держишь этот узел. По законам пустоши добыча достаётся тем, кто приходит и уходит, — "
            . "засидишься, и следующая *Метка пустоши* пройдёт мимо тебя (золото за вклад останется).\n\n"
            . '_Отойди от точки на время, чтобы сбросить отсчёт._';

        $this->safeSendMessage($tgId, $text, ['parse_mode' => 'Markdown']);
    }
}

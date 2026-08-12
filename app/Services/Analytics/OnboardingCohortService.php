<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use CodeIgniter\Database\BaseResult;
use Config\Database;

/**
 * Суточные когорты новичков — снимок воронки, переживающий чистку логов.
 *
 * 🔴 **Зачем.** `player_action_log` живёт `logging.player_actions.retention_days` = 30 дней
 * ({@see \App\Commands\CleanupPlayerActionLog}), и первая добыча новичка НИГДЕ больше не
 * оставляет следа: `action_log` её не пишет, `character_tasks` чистится, `character_resources`
 * мутирует (стартовый паёк неотличим от добытого). Пока снимка не было, любое сравнение
 * когорт старше месяца молча считало ноль вместо «данных нет» — на этом 12.08.2026 сгорели
 * два замера подряд: слайс «второй шаг» получил ответ «сигнала нет» на стёртом базлайне, а
 * сравнение периодов показало несуществующий рост 16% → 85%.
 *
 * **Инвариант, ради которого всё написано:** строка, посчитанная на ПОЛНЫХ логах
 * (`logs_complete = 1`), никогда не перезаписывается пересчётом на урезанных. Иначе
 * история гниёт ровно тем же способом, от которого таблица и защищает.
 *
 * Источники намеренно разные — берём самый долговечный из доступных:
 *
 * | Метрика | Источник | Долговечность |
 * |---|---|---|
 * | `regs` | `characters` + `telegram_users` | вечно |
 * | `moved` / `back_d1` / `back_d7` | `explored_cells` | вечно (та же, что у `/admin/funnel`) |
 * | `crafted` | `crafted_items_log` | вечно |
 * | `beyond_start` / `gathered` | `player_action_log` | **30 дней** — ради них и снимок |
 *
 * Горизонт наблюдения — 24 часа с регистрации КАЖДОГО персонажа, иначе у старых когорт
 * было бы больше времени, чем у вчерашних, и доли поехали бы сами собой.
 */
final class OnboardingCohortService
{
    public const TABLE = 'onboarding_cohort_daily';

    /** Сколько суток пересчитывать по умолчанию: TTL логов (30) + запас на «сегодня незакрыт». */
    public const DEFAULT_DAYS = 35;

    /**
     * Пересчитать когорты за последние N суток и записать снимок.
     *
     * @return array{scanned:int, written:int, skipped_stale:int, rows:list<array<string,mixed>>}
     */
    public function recompute(int $days = self::DEFAULT_DAYS, bool $dryRun = false): array
    {
        $days = max(1, min(400, $days));
        $db   = Database::connect();

        // С какого момента логи вообще существуют: всё, что раньше, посчитать честно нельзя.
        $oldestQuery = $db->query('SELECT MIN(created_at) AS oldest FROM player_action_log');
        $oldestRow   = $oldestQuery instanceof BaseResult ? $oldestQuery->getRowArray() : null;
        $oldestLog   = is_array($oldestRow) && is_string($oldestRow['oldest'] ?? null)
            ? $oldestRow['oldest']
            : null;

        $existing    = [];
        $existingRes = $db->table(self::TABLE)->select('cohort_date, logs_complete')->get();
        foreach (is_object($existingRes) ? $existingRes->getResultArray() : [] as $r) {
            $existing[(string) ($r['cohort_date'] ?? '')] = (int) ($r['logs_complete'] ?? 0) === 1;
        }

        $rows          = [];
        $written       = 0;
        $skippedStale  = 0;

        for ($i = $days; $i >= 1; $i--) {
            $ts   = strtotime("-{$i} days");
            $date = date('Y-m-d', $ts === false ? time() : $ts);

            $metrics = $this->metricsFor($date);

            // Когорта посчитана по полным логам, если хранение накрывает ВЕСЬ её горизонт —
            // то есть логи начинаются не позже ПЕРВОЙ регистрации этих суток. Сравнивать с
            // полуночью нельзя: логи стартуют в середине дня (чистка режет по времени, а не
            // по дате), и честная когорта помечалась бы неполной. Поймано тестом.
            $firstReg     = is_string($metrics['first_reg'] ?? null) ? $metrics['first_reg'] : '';
            $windowStart  = $firstReg !== '' ? strtotime($firstReg) : strtotime($date . ' 00:00:00');
            $oldestTs     = $oldestLog === null ? false : strtotime($oldestLog);
            $logsComplete = $oldestTs !== false && $windowStart !== false && $oldestTs <= $windowStart;
            unset($metrics['first_reg']);

            // 🔴 Уже есть полная строка, а сейчас логи урезаны → НЕ трогаем: снимок дороже пересчёта.
            if (($existing[$date] ?? false) && ! $logsComplete) {
                $skippedStale++;
                continue;
            }
            if ($metrics['regs'] === 0 && ! isset($existing[$date])) {
                continue; // пустой день без регистраций — строку не заводим
            }

            $metrics['cohort_date']   = $date;
            $metrics['logs_complete'] = $logsComplete ? 1 : 0;
            $rows[]                   = $metrics;

            if (! $dryRun) {
                $this->upsert($metrics);
                $written++;
            }
        }

        return ['scanned' => $days, 'written' => $written, 'skipped_stale' => $skippedStale, 'rows' => $rows];
    }

    /**
     * Метрики одной суточной когорты. Горизонт — 24 часа с регистрации персонажа.
     *
     * @return array<string, int|string> целые метрики + `first_reg` (время первой регистрации суток)
     */
    private function metricsFor(string $date): array
    {
        $db  = Database::connect();
        $sql = "
            SELECT
              COUNT(*) AS regs,
              COALESCE(MIN(u.created_at), '') AS first_reg,
              SUM((SELECT COUNT(*) FROM player_action_log p
                    WHERE p.character_id = c.id
                      AND p.created_at <= u.created_at + INTERVAL 24 HOUR) >= 2) AS beyond_start,
              SUM(EXISTS(SELECT 1 FROM explored_cells e
                          WHERE e.character_id = c.id
                            AND e.created_at <= u.created_at + INTERVAL 24 HOUR)) AS moved,
              SUM(EXISTS(SELECT 1 FROM player_action_log p
                          WHERE p.character_id = c.id
                            AND p.action_name LIKE 'gather%'
                            AND p.created_at <= u.created_at + INTERVAL 24 HOUR)) AS gathered,
              SUM(EXISTS(SELECT 1 FROM crafted_items_log l
                          WHERE l.character_id = c.id
                            AND l.created_at <= u.created_at + INTERVAL 24 HOUR)) AS crafted,
              SUM(EXISTS(SELECT 1 FROM explored_cells e
                          WHERE e.character_id = c.id
                            AND e.created_at >= u.created_at + INTERVAL 1 DAY)) AS back_d1,
              SUM(EXISTS(SELECT 1 FROM explored_cells e
                          WHERE e.character_id = c.id
                            AND e.created_at >= u.created_at + INTERVAL 7 DAY)) AS back_d7
            FROM characters c
            JOIN telegram_users u ON u.id = c.telegram_user_id
            WHERE DATE(u.created_at) = ?
        ";

        $query = $db->query($sql, [$date]);
        $row   = $query instanceof BaseResult ? ($query->getRowArray() ?? []) : [];

        $int = static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0;

        $firstReg = $row['first_reg'] ?? '';

        return [
            'first_reg'    => is_string($firstReg) ? $firstReg : '',
            'regs'         => $int($row['regs'] ?? 0),
            'beyond_start' => $int($row['beyond_start'] ?? 0),
            'moved'        => $int($row['moved'] ?? 0),
            'gathered'     => $int($row['gathered'] ?? 0),
            'crafted'      => $int($row['crafted'] ?? 0),
            'back_d1'      => $int($row['back_d1'] ?? 0),
            'back_d7'      => $int($row['back_d7'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $m */
    private function upsert(array $m): void
    {
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');

        $db->query(
            'INSERT INTO ' . self::TABLE . '
                (cohort_date, regs, beyond_start, moved, gathered, crafted, back_d1, back_d7,
                 logs_complete, computed_at, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                regs=VALUES(regs), beyond_start=VALUES(beyond_start), moved=VALUES(moved),
                gathered=VALUES(gathered), crafted=VALUES(crafted), back_d1=VALUES(back_d1),
                back_d7=VALUES(back_d7), logs_complete=VALUES(logs_complete),
                computed_at=VALUES(computed_at), updated_at=VALUES(updated_at)',
            [
                $m['cohort_date'], $m['regs'], $m['beyond_start'], $m['moved'], $m['gathered'],
                $m['crafted'], $m['back_d1'], $m['back_d7'], $m['logs_complete'], $now, $now, $now,
            ]
        );
    }

    /**
     * Последние N когорт для дашборда (свежие сверху).
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $days = 30): array
    {
        $res = Database::connect()
            ->table(self::TABLE)
            ->orderBy('cohort_date', 'DESC')
            ->limit(max(1, min(400, $days)))
            ->get();

        return is_object($res) ? array_values($res->getResultArray()) : [];
    }
}

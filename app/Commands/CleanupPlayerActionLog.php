<?php

namespace App\Commands;

use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * ADR-148 — авто-очистка + кольцевая ротация firehose `player_action_log` по created_at.
 *
 * Запуск: php spark player-actions:cleanup
 * Расписание: Config\Tasks → daily('03:40') (после battles:cleanup 03:35).
 *
 * Две независимые оси (обе по created_at; id ≈ хронологичен → ORDER BY id):
 *   1. TTL — удалить строки старше N дней (logging.player_actions.retention_days).
 *   2. Кап (кольцевой буфер «старое вытесняется новым») — оставить не более M НОВЕЙШИХ
 *      строк, лишние СТАРЕЙШИЕ удалить (logging.player_actions.max_rows).
 *
 * Любая ось со значением 0 → пропускается (можно отключить через GameSettings/флаг).
 * Удаление пакетами (--batch, default 5000) — не лочит firehose большим единым DELETE.
 *
 * Pattern: расширение CleanupBattleLogs (battles:cleanup) добавлением кап-оси.
 * Независим от killswitch логирования: чистит даже если запись firehose выключена.
 */
class CleanupPlayerActionLog extends BaseCommand
{
    protected $group       = 'Tasks';
    protected $name        = 'player-actions:cleanup';
    protected $description = 'Авто-очистка + кольцевая ротация player_action_log (ADR-148) по created_at: TTL по дням + кап по числу строк (старое вытесняется новым).';
    protected $usage       = 'player-actions:cleanup [--days=] [--max-rows=] [--batch=5000] [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--days'     => 'TTL: удалить строки старше N дней (default — GameSettings logging.player_actions.retention_days; 0 = пропустить TTL)',
        '--max-rows' => 'Кап: оставить не более N новейших строк, лишние старые удалить (default — GameSettings logging.player_actions.max_rows; 0 = пропустить кап)',
        '--batch'    => 'Размер пакета удаления (default 5000)',
        '--dry-run'  => 'Только показать, сколько будет удалено, без выполнения',
    ];

    public function run(array $params)
    {
        $gs = new GameSettingsService();

        $daysOpt = CLI::getOption('days');
        $days    = is_numeric($daysOpt) ? (int) $daysOpt : self::gsInt($gs, 'logging.player_actions.retention_days', 30);

        $maxOpt  = CLI::getOption('max-rows');
        $maxRows = is_numeric($maxOpt) ? (int) $maxOpt : self::gsInt($gs, 'logging.player_actions.max_rows', 2_000_000);

        $batchOpt = CLI::getOption('batch');
        $batch    = is_numeric($batchOpt) ? (int) $batchOpt : 5000;
        if ($batch < 1) {
            $batch = 5000;
        }

        $dryRun = (bool) CLI::getOption('dry-run');

        $db    = Database::connect();
        $total = $this->scalar('SELECT COUNT(*) AS c FROM player_action_log');
        CLI::write(
            "player_action_log: всего {$total} строк. TTL={$days}д, кап={$maxRows}, batch={$batch}"
            . ($dryRun ? ' [dry-run]' : ''),
            'yellow'
        );

        // ── 1) TTL по created_at ────────────────────────────────────────────
        $ttlDeleted = 0;
        if ($days > 0) {
            $ttlCount = $this->scalar(
                'SELECT COUNT(*) AS c FROM player_action_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
                [$days]
            );
            CLI::write("TTL: к удалению {$ttlCount} строк старше {$days} дней.", 'cyan');

            if (! $dryRun && $ttlCount > 0) {
                do {
                    $db->query(
                        "DELETE FROM player_action_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY id ASC LIMIT {$batch}",
                        [$days]
                    );
                    $aff = $db->affectedRows();
                    $ttlDeleted += $aff;
                } while ($aff >= $batch);
                CLI::write("TTL: удалено {$ttlDeleted}.", 'green');
            }
        } else {
            CLI::write('TTL пропущен (days <= 0).', 'cyan');
        }

        // ── 2) Кап (кольцевой буфер): вытеснить старейшие сверх лимита ───────
        $capDeleted = 0;
        if ($maxRows > 0) {
            $remaining = $this->scalar('SELECT COUNT(*) AS c FROM player_action_log');
            $excess    = self::excessRows($remaining, $maxRows);
            CLI::write("Кап: сейчас {$remaining}, лимит {$maxRows} → вытеснить {$excess} старейших.", 'cyan');

            if (! $dryRun && $excess > 0) {
                $left = $excess;
                do {
                    $lim = (int) min($batch, $left);
                    $db->query("DELETE FROM player_action_log ORDER BY id ASC LIMIT {$lim}");
                    $aff = $db->affectedRows();
                    $capDeleted += $aff;
                    $left -= $aff;
                } while ($left > 0 && $aff > 0);
                CLI::write("Кап: вытеснено {$capDeleted}.", 'green');
            }
        } else {
            CLI::write('Кап пропущен (max-rows <= 0).', 'cyan');
        }

        if ($dryRun) {
            CLI::write('--dry-run: реально ничего не удалено.', 'cyan');
            return;
        }

        $totalDeleted = $ttlDeleted + $capDeleted;
        $finalCount   = $this->scalar('SELECT COUNT(*) AS c FROM player_action_log');
        CLI::write("✓ Готово. Удалено {$totalDeleted} (TTL {$ttlDeleted} + кап {$capDeleted}). Осталось {$finalCount}.", 'green');
        log_message(
            'info',
            "[player-actions:cleanup] removed {$totalDeleted} rows (ttl={$ttlDeleted} days={$days}, cap={$capDeleted} max={$maxRows}); remaining {$finalCount}"
        );
    }

    /**
     * Чистый расчёт «сколько старейших вытеснить, чтобы осталось ≤ maxRows».
     * Тестируется без БД.
     */
    public static function excessRows(int $total, int $maxRows): int
    {
        if ($maxRows <= 0) {
            return 0;
        }
        return $total > $maxRows ? $total - $maxRows : 0;
    }

    /**
     * Скалярный COUNT с type-guard результата (query() возвращает bool|BaseResult|Query).
     *
     * @param list<int|string> $binds
     */
    private function scalar(string $sql, array $binds = []): int
    {
        $res = Database::connect()->query($sql, $binds);
        if (! $res instanceof \CodeIgniter\Database\BaseResult) {
            return 0;
        }
        $val = $res->getRow('c');
        return is_numeric($val) ? (int) $val : 0;
    }

    private static function gsInt(GameSettingsService $gs, string $key, int $default): int
    {
        $raw = $gs->get($key, $default);
        return is_numeric($raw) ? (int) $raw : $default;
    }
}

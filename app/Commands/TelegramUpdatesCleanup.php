<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * ADR-181 — очистка `telegram_updates_seen` (дедуп повторной доставки webhook'а по
 * `update_id`). Ретенция — крон, а не чистка на горячем пути тапа: повторный прогон
 * находит ноль строк и ничего не делает.
 *
 * Запуск: php spark telegram-updates:cleanup
 * Расписание: Config\Tasks → daily, ночное окно рядом с player-actions:cleanup/
 * community:cleanup (ADR-148/ADR-176).
 *
 * Срок хранения — инфраструктурная величина (Telegram прекращает ретраить задолго до
 * суток), не игровой баланс, поэтому живёт константой команды с `.env`-override
 * (`telegram.UPDATES_SEEN_RETENTION_DAYS`), как `telegram.RATE_LIMIT_PER_MINUTE`
 * (TelegramRateLimitFilter, ADR-163) — не в GameSettings (CLAUDE.md §admin-tunable
 * balance, раздел «Когда НЕ нужно»).
 *
 * Pattern: батч-удаление по образцу CleanupPlayerActionLog (player-actions:cleanup).
 */
class TelegramUpdatesCleanup extends BaseCommand
{
    protected $group       = 'Tasks';
    protected $name        = 'telegram-updates:cleanup';
    protected $description = 'ADR-181 — TTL-очистка telegram_updates_seen (дедуп update_id) по created_at.';
    protected $usage       = 'telegram-updates:cleanup [--days=] [--batch=5000] [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--days'    => 'TTL: удалить строки старше N дней (default — .env telegram.UPDATES_SEEN_RETENTION_DAYS или ' . self::DEFAULT_RETENTION_DAYS . ')',
        '--batch'   => 'Размер пакета удаления (default 5000)',
        '--dry-run' => 'Только показать, сколько будет удалено, без выполнения',
    ];

    /**
     * Telegram прекращает ретраить webhook задолго до суток — запас трёхкратный.
     * Переопределяется `telegram.UPDATES_SEEN_RETENTION_DAYS` в `.env`.
     */
    private const DEFAULT_RETENTION_DAYS = 3;

    public function run(array $params)
    {
        $daysOpt = CLI::getOption('days');
        $days    = is_numeric($daysOpt) ? (int) $daysOpt : self::retentionDays();

        $batchOpt = CLI::getOption('batch');
        $batch    = is_numeric($batchOpt) ? (int) $batchOpt : 5000;
        if ($batch < 1) {
            $batch = 5000;
        }

        $dryRun = (bool) CLI::getOption('dry-run');

        if ($days <= 0) {
            CLI::write('TTL пропущен (days <= 0).', 'cyan');
            return;
        }

        $db    = Database::connect();
        $total = $this->scalar('SELECT COUNT(*) AS c FROM telegram_updates_seen WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
        CLI::write("telegram_updates_seen: к удалению {$total} строк старше {$days} дней, batch={$batch}" . ($dryRun ? ' [dry-run]' : ''), 'yellow');

        if ($dryRun || $total === 0) {
            return;
        }

        $deleted = 0;
        do {
            $db->query(
                "DELETE FROM telegram_updates_seen WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY update_id ASC LIMIT {$batch}",
                [$days]
            );
            $aff = $db->affectedRows();
            $deleted += $aff;
        } while ($aff >= $batch);

        CLI::write("✓ Готово. Удалено {$deleted}.", 'green');
        log_message('info', "[telegram-updates:cleanup] removed {$deleted} rows (days={$days})");
    }

    /**
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

    private static function retentionDays(): int
    {
        $raw = getenv('telegram.UPDATES_SEEN_RETENTION_DAYS');
        if (is_string($raw) && ctype_digit(trim($raw)) && (int) trim($raw) > 0) {
            return (int) trim($raw);
        }

        return self::DEFAULT_RETENTION_DAYS;
    }
}

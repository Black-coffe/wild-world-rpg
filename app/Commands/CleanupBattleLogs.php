<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * v0.51.43 — TTL cleanup для battle_logs.
 *
 * Запуск: php spark battles:cleanup
 * Cron-рекомендація: щодня 03:35 EEST (після tasks:cleanup 03:30).
 *   35 3 * * * cd /path/to/mmorpg && php spark battles:cleanup
 *
 * Видаляє battle_logs старше 90 днів. Замало для звичайного forensics
 * (admin /battles/view), збереження recent activity (~58k rows у вікні)
 * для адміна/гравців.
 *
 * battle_logs — log_data longtext blob, growing ~646 rows/day на проді
 * (2026-05 baseline). Без TTL — 250k+ rows/year, повільні admin queries.
 *
 * Pattern: 1:1 копія `tasks:cleanup` (CleanupCharacterTasks.php F0.11).
 */
class CleanupBattleLogs extends BaseCommand
{
    protected $group       = 'Tasks';
    protected $name        = 'battles:cleanup';
    protected $description = 'Видаляє battle_logs старше N днів (default 90).';
    protected $usage       = 'battles:cleanup [--days=90] [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--days'    => 'Скільки днів зберігати (default: 90)',
        '--dry-run' => 'Тільки показати скільки буде видалено, без виконання',
    ];

    public function run(array $params)
    {
        $days   = (int) (CLI::getOption('days') ?? 90);
        $dryRun = (bool) CLI::getOption('dry-run');

        if ($days < 1) {
            CLI::error('--days повинно бути >= 1');
            return;
        }

        $db = Database::connect();

        $countQuery = $db->query(
            'SELECT COUNT(*) AS cnt FROM battle_logs
             WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );
        if (!$countQuery instanceof \CodeIgniter\Database\BaseResult) {
            CLI::error('Не вдалося отримати лічильник із БД.');
            return;
        }
        $count = (int) $countQuery->getRow('cnt');

        CLI::write("Знайдено для видалення: {$count} рядків (старше {$days} днів)", 'yellow');

        if ($dryRun) {
            CLI::write('--dry-run: реально не видаляємо.', 'cyan');
            return;
        }

        if ($count === 0) {
            CLI::write('Нічого чистити.', 'green');
            return;
        }

        $db->query(
            'DELETE FROM battle_logs
             WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );

        $affected = $db->affectedRows();
        CLI::write("✓ Видалено: {$affected} рядків.", 'green');

        log_message('info', "[battles:cleanup] removed {$affected} battle_logs rows older than {$days} days");
    }
}

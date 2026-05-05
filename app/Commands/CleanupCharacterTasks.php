<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * F0.11 — nightly cleanup завершённых/прерванных character_tasks.
 *
 * Запуск: php spark tasks:cleanup
 * Cron-рекомендация: ежедневно в 03:30 (после TaxCollectionHandler в 03:00).
 *   30 3 * * * cd /path/to/mmorpg && php spark tasks:cleanup
 *
 * Удаляет строки со статусом completed/interrupted старше 7 дней.
 * Без этого character_tasks растёт неограниченно (см. lore/refactor/Tick-system.md P2).
 */
class CleanupCharacterTasks extends BaseCommand
{
    protected $group       = 'Tasks';
    protected $name        = 'tasks:cleanup';
    protected $description = 'Удаляет character_tasks со статусом completed/interrupted старше 7 дней.';
    protected $usage       = 'tasks:cleanup [--days=7] [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--days'    => 'Сколько дней хранить (default: 7)',
        '--dry-run' => 'Только показать сколько будет удалено, без выполнения',
    ];

    public function run(array $params)
    {
        $days   = (int) (CLI::getOption('days') ?? 7);
        $dryRun = (bool) CLI::getOption('dry-run');

        if ($days < 1) {
            CLI::error('--days должно быть >= 1');
            return;
        }

        $db = Database::connect();

        $countQuery = $db->query(
            'SELECT COUNT(*) AS cnt FROM character_tasks
             WHERE status IN (?, ?)
               AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            ['completed', 'interrupted', $days]
        );
        if (!$countQuery instanceof \CodeIgniter\Database\BaseResult) {
            CLI::error('Не вдалося отримати лічильник із БД.');
            return;
        }
        $count = (int) $countQuery->getRow('cnt');

        CLI::write("Найдено для удаления: {$count} строк (status IN completed/interrupted, старше {$days} дней)", 'yellow');

        if ($dryRun) {
            CLI::write('--dry-run: реально не удаляем.', 'cyan');
            return;
        }

        if ($count === 0) {
            CLI::write('Нечего чистить.', 'green');
            return;
        }

        $db->query(
            'DELETE FROM character_tasks
             WHERE status IN (?, ?)
               AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            ['completed', 'interrupted', $days]
        );

        $affected = $db->affectedRows();
        CLI::write("✓ Удалено: {$affected} строк.", 'green');

        log_message('info', "[tasks:cleanup] removed {$affected} character_tasks rows older than {$days} days");
    }
}

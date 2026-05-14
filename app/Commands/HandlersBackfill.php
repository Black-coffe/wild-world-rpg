<?php

declare(strict_types=1);

namespace App\Commands;

use App\Controllers\Worker;
use App\Services\World\ObjectDiscoveryService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Config\WorldEvents;
use ReflectionClass;

/**
 * ADR-023 Phase B6 backfill: заповнює `handler_key` колонки у 3 таблицях
 * (events / tasks / world_objects) на основі існуючих dispatch-keys та
 * legacy-map'ів.
 *
 * Run:
 *   php spark handlers:backfill              — backfill 3 таблиці, безпечно
 *   php spark handlers:backfill --dry-run    — тільки звіт, нічого не оновлюємо
 *   php spark handlers:backfill --reset      — перезаповнити навіть якщо вже set
 *                                              (default — пропускати рядки де
 *                                              handler_key вже NOT NULL)
 *
 * Idempotent у default режимі. Безпечно гонити повторно.
 *
 * Sources:
 *   - events.handler_key        ← `Config\WorldEvents::$events[name_english]['effect_kind']`
 *   - tasks.handler_key         ← `Worker::$taskHandlerKeyMap[tasks.name]`
 *   - world_objects.handler_key ← `ObjectDiscoveryService::$objectHandlerKeyMap[name_en]`
 *
 * Скриnu output показує per-table breakdown:
 *   updated / skipped (already set) / unmapped (no entry в map'і → залишаємо NULL).
 *
 * `unmapped` НЕ є помилкою — це означає що для DB-row немає registered handler'а
 * (наприклад, admin додав 'CustomEvent' без супровідного WorldEvents.php запису,
 * або старий task.name був прибраний з $taskHandlerKeyMap у cleanup). Dispatcher
 * в legacy-режимі продовжує працювати через старий шлях.
 */
final class HandlersBackfill extends BaseCommand
{
    protected $group       = 'Handlers';
    protected $name        = 'handlers:backfill';
    protected $description = 'Phase B6 (ADR-023): backfill events.handler_key / tasks.handler_key / world_objects.handler_key з legacy dispatch-keys.';
    protected $usage       = 'handlers:backfill [--dry-run] [--reset]';

    /** @var array<string, string> */
    protected $arguments = [];

    /** @var array<string, string> */
    protected $options = [
        '--dry-run' => 'Тільки звіт, нічого не оновлюємо.',
        '--reset'   => 'Перезаповнити навіть якщо handler_key уже set (default: пропустити).',
    ];

    public function run(array $params): void
    {
        $dryRun = (bool) CLI::getOption('dry-run');
        $reset  = (bool) CLI::getOption('reset');

        CLI::write(sprintf(
            'Phase B6 handler_key backfill (dry-run=%s, reset=%s)',
            $dryRun ? 'yes' : 'no',
            $reset ? 'yes' : 'no',
        ), 'yellow');
        CLI::newLine();

        $this->backfillEvents($dryRun, $reset);
        $this->backfillTasks($dryRun, $reset);
        $this->backfillWorldObjects($dryRun, $reset);

        CLI::newLine();
        CLI::write('Done.', 'green');
    }

    private function backfillEvents(bool $dryRun, bool $reset): void
    {
        CLI::write('=== events.handler_key ===', 'cyan');

        $worldEvents = config(WorldEvents::class);

        $db     = Database::connect();
        $query  = $db->table('events')
            ->select('event_id, name_english, handler_key')
            ->where($reset ? [] : ['handler_key' => null])
            ->get();
        $rows   = $query !== false ? $query->getResultArray() : [];

        $updated   = 0;
        $skipped   = 0;
        $unmapped  = [];
        foreach ($rows as $row) {
            $nameEn = (string) ($row['name_english'] ?? '');
            $cfg    = $worldEvents->get($nameEn);
            $kind   = is_array($cfg) ? ($cfg['effect_kind'] ?? null) : null;

            if (!is_string($kind) || $kind === '') {
                $unmapped[] = $nameEn;
                continue;
            }

            if ($dryRun) {
                $updated++;
                continue;
            }

            $db->table('events')
                ->where('event_id', $row['event_id'])
                ->update(['handler_key' => $kind, 'updated_at' => date('Y-m-d H:i:s')]);
            $updated++;
        }

        // Окремо рахуємо «вже set» у не-reset режимі (через окремий SELECT).
        if (!$reset) {
            $skipped = (int) $db->table('events')
                ->where('handler_key IS NOT NULL', null, false)
                ->countAllResults();
        }

        $this->summary('events', count($rows), $updated, $skipped, $unmapped, $dryRun);
    }

    private function backfillTasks(bool $dryRun, bool $reset): void
    {
        CLI::write('=== tasks.handler_key ===', 'cyan');

        $worker  = new Worker();
        $keyMap  = $this->extractArrayProperty($worker, 'taskHandlerKeyMap');
        $db      = Database::connect();
        $query   = $db->table('tasks')
            ->select('id, name, handler_key')
            ->where($reset ? [] : ['handler_key' => null])
            ->get();
        $rows    = $query !== false ? $query->getResultArray() : [];

        $updated  = 0;
        $skipped  = 0;
        $unmapped = [];
        foreach ($rows as $row) {
            $taskName = (string) ($row['name'] ?? '');
            $key      = $keyMap[$taskName] ?? null;

            if (!is_string($key) || $key === '') {
                $unmapped[] = $taskName;
                continue;
            }

            if ($dryRun) {
                $updated++;
                continue;
            }

            $db->table('tasks')
                ->where('id', $row['id'])
                ->update(['handler_key' => $key, 'updated_at' => date('Y-m-d H:i:s')]);
            $updated++;
        }

        if (!$reset) {
            $skipped = (int) $db->table('tasks')
                ->where('handler_key IS NOT NULL', null, false)
                ->countAllResults();
        }

        $this->summary('tasks', count($rows), $updated, $skipped, $unmapped, $dryRun);
    }

    private function backfillWorldObjects(bool $dryRun, bool $reset): void
    {
        CLI::write('=== world_objects.handler_key ===', 'cyan');

        $service = new ObjectDiscoveryService(null, null);
        $keyMap  = $this->extractArrayProperty($service, 'objectHandlerKeyMap');
        $db      = Database::connect();
        $query   = $db->table('world_objects')
            ->select('id, name_en, handler_key')
            ->where($reset ? [] : ['handler_key' => null])
            ->get();
        $rows    = $query !== false ? $query->getResultArray() : [];

        $updated  = 0;
        $skipped  = 0;
        $unmapped = [];
        foreach ($rows as $row) {
            $nameEn = (string) ($row['name_en'] ?? '');
            $key    = $keyMap[$nameEn] ?? null;

            if (!is_string($key) || $key === '') {
                $unmapped[] = $nameEn;
                continue;
            }

            if ($dryRun) {
                $updated++;
                continue;
            }

            $db->table('world_objects')
                ->where('id', $row['id'])
                ->update(['handler_key' => $key, 'updated_at' => date('Y-m-d H:i:s')]);
            $updated++;
        }

        if (!$reset) {
            $skipped = (int) $db->table('world_objects')
                ->where('handler_key IS NOT NULL', null, false)
                ->countAllResults();
        }

        $this->summary('world_objects', count($rows), $updated, $skipped, $unmapped, $dryRun);
    }

    /**
     * @param list<string> $unmapped
     */
    private function summary(string $table, int $processed, int $updated, int $skipped, array $unmapped, bool $dryRun): void
    {
        CLI::write("  processed: {$processed} rows");
        $verb = $dryRun ? 'would update' : 'updated';
        CLI::write("  {$verb}: {$updated}", $updated > 0 ? 'green' : 'dark_gray');
        if ($skipped > 0) {
            CLI::write("  already set (skipped): {$skipped}", 'dark_gray');
        }
        if ($unmapped !== []) {
            $uniq = array_values(array_unique($unmapped));
            CLI::write('  unmapped (no registry entry, left NULL): ' . count($unmapped) . ' row(s), keys: ' . implode(', ', $uniq), 'yellow');
        }
        CLI::newLine();
    }

    /**
     * @return array<string, string>
     */
    private function extractArrayProperty(object $instance, string $name): array
    {
        $refl  = new ReflectionClass($instance);
        $prop  = $refl->getProperty($name);
        $prop->setAccessible(true);
        $value = $prop->getValue($instance);
        if (!is_array($value)) {
            return [];
        }
        /** @var array<string, string> $value */
        return $value;
    }
}

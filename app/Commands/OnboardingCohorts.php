<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Analytics\OnboardingCohortService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Снимок суточных когорт новичков (аудит 2026-08-12).
 *
 * Запуск: php spark onboarding:cohorts
 * Расписание: Config\Tasks → daily('03:50') — ПОСЛЕ `player-actions:cleanup` (03:40) намеренно:
 * если чистка урежет логи, сегодняшний пересчёт это увидит и пометит когорту `logs_complete=0`,
 * вместо того чтобы записать заниженные числа как факт.
 *
 * Зачем вообще: `player_action_log` живёт 30 дней, а первой добычи новичка больше нигде нет.
 * Без снимка сравнение когорт старше месяца молча считает ноль вместо «данных нет» — на этом
 * 12.08.2026 сгорели два замера подряд ({@see OnboardingCohortService}).
 */
class OnboardingCohorts extends BaseCommand
{
    protected $group       = 'Tasks';
    protected $name        = 'onboarding:cohorts';
    protected $description = 'Снимок суточных когорт новичков (воронка старт → шаг → добыча → крафт → возврат), переживающий чистку firehose.';
    protected $usage       = 'onboarding:cohorts [--days=35] [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--days'    => 'Сколько последних суток пересчитать (default 35 — TTL логов 30 + запас)',
        '--dry-run' => 'Показать, что получится, ничего не записывая',
    ];

    public function run(array $params): int
    {
        $daysOpt = CLI::getOption('days');
        $days    = is_numeric($daysOpt) ? (int) $daysOpt : OnboardingCohortService::DEFAULT_DAYS;
        $dryRun  = (bool) CLI::getOption('dry-run');

        $res = (new OnboardingCohortService())->recompute($days, $dryRun);

        CLI::write(
            sprintf(
                'onboarding:cohorts — окно %d сут., записано %d, пропущено полных строк %d%s',
                $res['scanned'],
                $res['written'],
                $res['skipped_stale'],
                $dryRun ? ' (dry-run)' : ''
            ),
            'green'
        );

        // mixed → string нарочно через хелпер, а не приведением: phpstan L9 запрещает
        // слепой (string) на mixed, и это правильно — в массиве метрик всё приходит из БД.
        $s = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';

        $tail = array_slice($res['rows'], -7);
        if ($tail !== []) {
            CLI::table(
                array_map(static fn (array $r): array => [
                    $s($r['cohort_date'] ?? ''),
                    $s($r['regs'] ?? 0),
                    $s($r['beyond_start'] ?? 0),
                    $s($r['moved'] ?? 0),
                    $s($r['gathered'] ?? 0),
                    $s($r['crafted'] ?? 0),
                    $s($r['back_d1'] ?? 0),
                    ($r['logs_complete'] ?? 0) === 1 ? 'да' : 'НЕТ',
                ], $tail),
                ['сутки', 'рег', 'дальше', 'шаг', 'добыча', 'крафт', 'd1', 'логи полные']
            );
        }

        return EXIT_SUCCESS;
    }
}

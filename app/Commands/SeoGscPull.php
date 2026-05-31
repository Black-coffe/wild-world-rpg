<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Seo\GoogleSearchConsoleService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Config\Seo as SeoConfig;
use Throwable;

/**
 * Пулл данных Google Search Console в БД (Шаг 6a SEO-пайплайна).
 *
 * Тянет Search Analytics по измерениям query и page за период и пишет снапшот
 * в seo_gsc_queries / seo_gsc_pages (каждый прогон — новый батч с captured_at).
 * Печатает топ-запросы и «показы без кликов» (impressions>0, clicks=0) — точки роста.
 *
 * GSC отдаёт данные с лагом ~2-3 дня, поэтому окно сдвинуто на 3 дня назад.
 *
 *   php spark seo:gsc-pull               # период по умолчанию (Config\Seo::$gscDefaultDays)
 *   php spark seo:gsc-pull --days 90     # последние 90 дней
 *   php spark seo:gsc-pull --dry-run     # не писать в БД, только показать
 */
class SeoGscPull extends BaseCommand
{
    protected $group       = 'SEO';
    protected $name        = 'seo:gsc-pull';
    protected $description = 'Тянет запросы/страницы из Google Search Console в БД + показывает точки роста.';
    protected $usage       = 'seo:gsc-pull [--days N] [--dry-run]';
    protected $options     = [
        '--days'    => 'Период в днях (по умолчанию из Config\\Seo::$gscDefaultDays).',
        '--dry-run' => 'Не писать в БД — только вывести сводку.',
    ];

    /** @param array<int|string,string|null> $params */
    public function run(array $params): int
    {
        $config = config(SeoConfig::class);

        $daysOpt = CLI::getOption('days');
        $days    = is_numeric($daysOpt) ? max(1, (int) $daysOpt) : $config->gscDefaultDays;
        $dryRun  = (bool) CLI::getOption('dry-run');

        // Лаг GSC ~2-3 дня → окно [today-(days+2); today-3].
        $startTs = strtotime('-' . ($days + 2) . ' days');
        $end     = date('Y-m-d', strtotime('-3 days'));
        $start   = date('Y-m-d', $startTs !== false ? $startTs : time());

        CLI::write('Период: ' . $start . ' … ' . $end . ' (siteUrl ' . $config->gscSiteUrl . ')', 'yellow');

        try {
            $svc       = new GoogleSearchConsoleService($config);
            $queryRows = $svc->searchAnalytics($start, $end, ['query'], 1000);
            $pageRows  = $svc->searchAnalytics($start, $end, ['page'], 1000);
        } catch (Throwable $e) {
            CLI::error('Ошибка GSC: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write('Получено: ' . count($queryRows) . ' запросов, ' . count($pageRows) . ' страниц.', 'green');

        if (! $dryRun) {
            $this->persist('seo_gsc_queries', 'query', 255, $queryRows, $start, $end);
            $this->persist('seo_gsc_pages', 'page', 512, $pageRows, $start, $end);
            CLI::write('Записано в БД (captured_at = сейчас).', 'green');
        } else {
            CLI::write('--dry-run: в БД не писал.', 'yellow');
        }

        $this->report($queryRows);

        return EXIT_SUCCESS;
    }

    /**
     * @param list<array{keys:list<string>,clicks:float,impressions:float,ctr:float,position:float}> $rows
     */
    private function persist(string $table, string $keyCol, int $maxLen, array $rows, string $start, string $end): void
    {
        if ($rows === []) {
            return;
        }

        $now  = date('Y-m-d H:i:s');
        $data = [];
        foreach ($rows as $r) {
            $key = $r['keys'][0] ?? '';
            if ($key === '') {
                continue;
            }
            $data[] = [
                $keyCol        => mb_substr($key, 0, $maxLen),
                'clicks'       => (int) round($r['clicks']),
                'impressions'  => (int) round($r['impressions']),
                'ctr'          => round($r['ctr'], 4),
                'position'     => round($r['position'], 2),
                'period_start' => $start,
                'period_end'   => $end,
                'captured_at'  => $now,
            ];
        }

        if ($data !== []) {
            Database::connect()->table($table)->insertBatch($data);
        }
    }

    /**
     * @param list<array{keys:list<string>,clicks:float,impressions:float,ctr:float,position:float}> $rows
     */
    private function report(array $rows): void
    {
        if ($rows === []) {
            CLI::write('Нет данных для отчёта (период пуст или сайт почти не показывается).', 'yellow');

            return;
        }

        $byClicks = $rows;
        usort($byClicks, static fn (array $a, array $b): int => $b['clicks'] <=> $a['clicks']);
        CLI::write("\nТОП-10 запросов по кликам:", 'cyan');
        foreach (array_slice($byClicks, 0, 10) as $r) {
            CLI::write(sprintf('  %4d кл · %5d пок · поз. %.1f · %s', (int) $r['clicks'], (int) $r['impressions'], $r['position'], $r['keys'][0] ?? ''));
        }

        $opp = array_values(array_filter($rows, static fn (array $r): bool => $r['impressions'] >= 10.0 && $r['clicks'] === 0.0));
        usort($opp, static fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
        CLI::write("\n«Показы без кликов» (≥10 показов, 0 кликов) — точки роста:", 'cyan');
        if ($opp === []) {
            CLI::write('  (нет — либо мало данных, либо всё кликается)');
        }
        foreach (array_slice($opp, 0, 15) as $r) {
            CLI::write(sprintf('  %5d пок · поз. %.1f · %s', (int) $r['impressions'], $r['position'], $r['keys'][0] ?? ''));
        }
    }
}

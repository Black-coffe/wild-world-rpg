<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Admin;

use App\Services\Admin\DashboardCsvExporter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * CSV-экспорт дашборда — чистая трансформация payload'а (без БД).
 *
 * @internal
 */
final class DashboardCsvExporterTest extends CIUnitTestCase
{
    /** @return array<string,mixed> */
    private function sample(): array
    {
        return [
            'kpi'          => ['chars_total' => 527, 'gold_total' => 161815211.0, 'avg_level' => 3.5, 'battles_total' => 40382],
            'growth'       => ['labels' => ['14.06', '15.06'], 'regs' => [3, 5], 'active' => [10, 12]],
            'levels'       => [['bucket' => 'L1', 'chars' => 470], ['bucket' => 'L2-4', 'chars' => 20]],
            'biomes'       => [['name' => 'Леса', 'chars' => 100]],
            'factions'     => [['name' => 'Партизаны', 'chars' => 30]],
            'gold_buckets' => [['bucket' => '1M+', 'chars' => 2]],
            'battles'      => ['total' => 40382, 'by_type' => [['type' => 'PVP', 'count' => 5]], 'labels' => ['15.06'], 'daily' => [7]],
            'pvp_ladder'   => [['name' => 'Боец', 'points' => 9, 'wins' => 3, 'losses' => 1]],
            'economy'      => ['labels' => ['15.06'], 'buy' => [1000], 'sell' => [2000]],
            'top_crafted'  => [['name' => 'Топор', 'qty' => 999]],
            'top_resources' => [['name' => 'Вода', 'qty' => 5000]],
            'buildings'    => [['type' => '🌾 Фермерские', 'count' => 2]],
            'tasks'        => [['type' => 'gathering', 'count' => 4]],
            'world'        => ['explored_cells' => 2568, 'map_cells' => 1000000],
            'top_players'  => [['name' => 'Vet', 'level' => 10, 'gold' => 5000000.0, 'exp' => 999.5]],
            'top_rich'     => [['name' => 'Vet', 'gold' => 5000000.0, 'level' => 10]],
            'trend_days'   => 30,
            'generated_at' => '2026-06-15 15:00:00',
        ];
    }

    public function testStartsWithBom(): void
    {
        $csv = (new DashboardCsvExporter())->export($this->sample());
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    public function testContainsAllSectionTitles(): void
    {
        $csv = (new DashboardCsvExporter())->export($this->sample());

        foreach ([
            '# Ключевые показатели', '# Мир (снимок)', '# Рост и активность (по дням)',
            '# Распределение по уровням', '# Игроки по биомам', '# Фракции',
            '# Богатство (золото)', '# Бои — сводка', '# Бои по типам', '# Бои по дням',
            '# PvP-ладдер', '# Рынок: оборот золота (по дням)', '# Топ скрафченного',
            '# Склад рынка (топ запасов)', '# Постройки по типам', '# Чем заняты сейчас',
            '# Топ по уровню', '# Богатейшие',
        ] as $title) {
            $this->assertStringContainsString($title, $csv);
        }
    }

    public function testNumbersAreRawNoThousandSeparators(): void
    {
        $csv = (new DashboardCsvExporter())->export($this->sample());

        // Целый float выводится без `.0`, без пробелов-разделителей.
        $this->assertStringContainsString('161815211', $csv);
        $this->assertStringNotContainsString('161 815 211', $csv);
        $this->assertStringNotContainsString('161815211.0', $csv);
        // Дробное сохраняется.
        $this->assertStringContainsString('3.5', $csv);
    }

    public function testUsesSemicolonDelimiterAndSeriesRows(): void
    {
        $csv = (new DashboardCsvExporter())->export($this->sample());

        $this->assertStringContainsString('Дата;Новые персонажи;Активны (движение)', $csv);
        $this->assertStringContainsString('14.06;3;10', $csv);
        $this->assertStringContainsString('15.06;5;12', $csv);
        // KPI key-value.
        $this->assertStringContainsString('Персонажей;527', $csv);
    }

    public function testEmptyPayloadStillProducesHeaderAndSections(): void
    {
        $csv = (new DashboardCsvExporter())->export([]);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Дашборд «Пульс игры»', $csv);
        $this->assertStringContainsString('# Ключевые показатели', $csv);
        // KPI-лейблы есть даже при пустых данных (значение 0).
        $this->assertStringContainsString('Персонажей;0', $csv);
    }
}

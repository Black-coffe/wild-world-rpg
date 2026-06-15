<?php

declare(strict_types=1);

namespace App\Services\Admin;

/**
 * Сериализует payload [[DashboardAnalyticsService]] в секционированный CSV для экспорта
 * с дашборда `/dashboard/export`. Чистая трансформация (без БД) → юнит-тестируема.
 *
 * Формат: блоки секций, каждый со строкой-заголовком `# Название` + шапкой колонок,
 * разделённые пустой строкой. Разделитель `;` + BOM — дружелюбно к RU-Excel и кириллице.
 * Числа — сырые (без пробелов-разделителей разрядов), чтобы CSV годился для анализа.
 */
final class DashboardCsvExporter
{
    private const DELIM = ';';

    /** key → RU-лейбл для KPI-блока (порядок = порядок вывода). */
    private const KPI_LABELS = [
        'chars_total'     => 'Персонажей',
        'tg_total'        => 'Telegram-аккаунтов',
        'reachable'       => 'Достижимы (не заблокировали бота)',
        'active_24h'      => 'Активны за 24 часа',
        'active_7d'       => 'Активны за 7 дней',
        'active_30d'      => 'Активны за 30 дней',
        'new_7d'          => 'Новых за 7 дней',
        'new_30d'         => 'Новых за 30 дней',
        'gold_total'      => 'Золото в экономике',
        'avg_level'       => 'Средний уровень',
        'max_level'       => 'Макс. уровень',
        'battles_total'   => 'Боёв всего',
        'bases_active'    => 'Активных баз',
        'buildings_total' => 'Построек всего',
    ];

    /** key → RU-лейбл для блока «Мир». */
    private const WORLD_LABELS = [
        'explored_cells'   => 'Исследовано клеток',
        'claimed_active'   => 'Активных захваченных клеток',
        'world_objects'    => 'Объектов в мире (активны)',
        'events_active'    => 'Активных событий',
        'crafts_total'     => 'Скрафчено предметов (всего)',
        'txns_total'       => 'Сделок на рынке (всего)',
        'quests_completed' => 'Квестов завершено',
        'map_cells'        => 'Клеток на карте',
    ];

    /**
     * @param array<string,mixed> $d payload DashboardAnalyticsService::dashboard()
     */
    public function export(array $d): string
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return '';
        }

        $arr = static fn (mixed $v): array => is_array($v) ? $v : [];

        // Шапка файла.
        $this->row($fh, ['Дашборд «Пульс игры» — экспорт данных']);
        $this->row($fh, ['Сформировано', $this->s($d['generated_at'] ?? '')]);
        $this->row($fh, ['Период тренда (дней)', $this->num($d['trend_days'] ?? 0)]);

        // KPI.
        $kpi = $arr($d['kpi'] ?? null);
        $this->section($fh, '# Ключевые показатели', ['Метрика', 'Значение']);
        foreach (self::KPI_LABELS as $key => $label) {
            $this->row($fh, [$label, $this->num($kpi[$key] ?? 0)]);
        }

        // Мир.
        $world = $arr($d['world'] ?? null);
        $this->section($fh, '# Мир (снимок)', ['Метрика', 'Значение']);
        foreach (self::WORLD_LABELS as $key => $label) {
            $this->row($fh, [$label, $this->num($world[$key] ?? 0)]);
        }

        // Рост и активность (time-series).
        $this->seriesBlock(
            $fh,
            '# Рост и активность (по дням)',
            ['Дата', 'Новые персонажи', 'Активны (движение)'],
            $arr($d['growth'] ?? null),
            ['labels', 'regs', 'active']
        );

        // Распределения.
        $this->listBlock($fh, '# Распределение по уровням', ['Корзина', 'Игроков'], $arr($d['levels'] ?? null), ['bucket', 'chars']);
        $this->listBlock($fh, '# Игроки по биомам', ['Биом', 'Игроков'], $arr($d['biomes'] ?? null), ['name', 'chars']);
        $this->listBlock($fh, '# Фракции', ['Фракция', 'Игроков'], $arr($d['factions'] ?? null), ['name', 'chars']);
        $this->listBlock($fh, '# Богатство (золото)', ['Корзина', 'Игроков'], $arr($d['gold_buckets'] ?? null), ['bucket', 'chars']);

        // Бои.
        $battles = $arr($d['battles'] ?? null);
        $this->section($fh, '# Бои — сводка', ['Метрика', 'Значение']);
        $this->row($fh, ['Всего боёв', $this->num($battles['total'] ?? 0)]);
        $this->listBlock($fh, '# Бои по типам', ['Тип', 'Количество'], $arr($battles['by_type'] ?? null), ['type', 'count']);
        $this->seriesBlock($fh, '# Бои по дням', ['Дата', 'Боёв'], $battles, ['labels', 'daily']);

        // PvP-ладдер.
        $this->listBlock($fh, '# PvP-ладдер', ['Боец', 'Очки', 'Победы', 'Поражения'], $arr($d['pvp_ladder'] ?? null), ['name', 'points', 'wins', 'losses']);

        // Экономика.
        $this->seriesBlock($fh, '# Рынок: оборот золота (по дням)', ['Дата', 'Покупки', 'Продажи'], $arr($d['economy'] ?? null), ['labels', 'buy', 'sell']);

        // Крафт / ресурсы / постройки / задания.
        $this->listBlock($fh, '# Топ скрафченного', ['Предмет', 'Количество'], $arr($d['top_crafted'] ?? null), ['name', 'qty']);
        $this->listBlock($fh, '# Склад рынка (топ запасов)', ['Ресурс', 'Количество'], $arr($d['top_resources'] ?? null), ['name', 'qty']);
        $this->listBlock($fh, '# Постройки по типам', ['Тип', 'Количество'], $arr($d['buildings'] ?? null), ['type', 'count']);
        $this->listBlock($fh, '# Чем заняты сейчас', ['Тип задания', 'Количество'], $arr($d['tasks'] ?? null), ['type', 'count']);

        // Лидерборды.
        $this->listBlock($fh, '# Топ по уровню', ['Игрок', 'Уровень', 'Золото', 'Опыт'], $arr($d['top_players'] ?? null), ['name', 'level', 'gold', 'exp']);
        $this->listBlock($fh, '# Богатейшие', ['Игрок', 'Золото', 'Уровень'], $arr($d['top_rich'] ?? null), ['name', 'gold', 'level']);

        rewind($fh);
        $body = stream_get_contents($fh);
        fclose($fh);

        // BOM для корректной кириллицы в Excel.
        return "\xEF\xBB\xBF" . (is_string($body) ? $body : '');
    }

    /**
     * Блок-список: секция + строки из list<assoc> по указанным ключам.
     *
     * @param resource          $fh
     * @param list<string>      $header
     * @param array<int,mixed>  $rows
     * @param list<string>      $keys
     */
    private function listBlock($fh, string $title, array $header, array $rows, array $keys): void
    {
        $this->section($fh, $title, $header);
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $line = [];
            foreach ($keys as $idx => $k) {
                $v      = $r[$k] ?? '';
                $line[] = $idx === 0 ? $this->s($v) : $this->num($v); // 1-я колонка = подпись (без числ-коэрции)
            }
            $this->row($fh, $line);
        }
    }

    /**
     * Блок time-series: параллельные массивы под ключами выстраиваются построчно.
     *
     * @param resource              $fh
     * @param list<string>          $header
     * @param array<string,mixed>   $series
     * @param list<string>          $keys  первый = labels (ось), остальные — серии значений
     */
    private function seriesBlock($fh, string $title, array $header, array $series, array $keys): void
    {
        $this->section($fh, $title, $header);
        $cols = [];
        foreach ($keys as $k) {
            $cols[$k] = is_array($series[$k] ?? null) ? array_values($series[$k]) : [];
        }
        $labelKey = $keys[0];
        $n        = count($cols[$labelKey] ?? []);
        for ($i = 0; $i < $n; $i++) {
            $line = [];
            foreach ($keys as $idx => $k) {
                $v      = $cols[$k][$i] ?? '';
                $line[] = $idx === 0 ? $this->s($v) : $this->num($v); // 1-я колонка = дата/подпись
            }
            $this->row($fh, $line);
        }
    }

    /**
     * Заголовок секции (пустая строка-разделитель + `# Название` + шапка колонок).
     *
     * @param resource     $fh
     * @param list<string> $header
     */
    private function section($fh, string $title, array $header): void
    {
        fwrite($fh, "\r\n");
        $this->row($fh, [$title]);
        $this->row($fh, $header);
    }

    /**
     * Пишет CSV-строку. Ручное экранирование (не fputcsv): кавычим ТОЛЬКО при наличии
     * `;`/`"`/перевода строки — без лишних кавычек вокруг полей с пробелами.
     *
     * @param resource     $fh
     * @param list<string> $fields
     */
    private function row($fh, array $fields): void
    {
        $escaped = array_map(
            static fn (string $f): string => preg_match('/[";\r\n]/', $f) === 1
                ? '"' . str_replace('"', '""', $f) . '"'
                : $f,
            $fields
        );
        fwrite($fh, implode(self::DELIM, $escaped) . "\r\n");
    }

    /** Число без разделителей разрядов; целые float'ы без `.0`. */
    private function num(mixed $v): string
    {
        if (! is_numeric($v)) {
            return '0';
        }
        $f = (float) $v;
        return $f === floor($f) && abs($f) < 1.0e15 ? (string) (int) $f : (string) $f;
    }

    private function s(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }
}

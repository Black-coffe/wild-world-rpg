<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseResult;
use Config\Database;
use Throwable;

/**
 * story: craft-shortfall-buy-12 — read-only замер масштаба уже существующего цикла
 * «купил сырьё → собрал → продал» и сколько золота он печатает. Отдельно от фичи докупки
 * недостающего сырья (craft-shortfall-buy): цикл существует независимо от неё.
 *
 * Источник — журналы действий, НЕ затухающие счётчики `resources_bank` (ADR-175 — «0» там
 * больше не значит «не покупали»): `transactions` (append-only лог купли/продажи, type=buy|sell,
 * не путать с раздачами) и `crafted_items_log` (append-only лог завершённых крафтов). Обе таблицы
 * не участвуют ни в какой чистке/decay — в отличие от `player_action_log` (TTL 30 суток).
 *
 * Read-only: только SELECT. Ни один запрос не пишет в игровые таблицы.
 *
 * Запуск: php spark audit:craft-gold-loop [--out=PATH]
 */
final class AuditCraftGoldLoop extends BaseCommand
{
    protected $group       = 'Analytics';
    protected $name        = 'audit:craft-gold-loop';
    protected $description = 'Read-only замер цикла «купил сырьё → собрал → продал» по журналам действий: сколько персонажей, сколько золота, какие рецепты.';
    protected $usage       = 'audit:craft-gold-loop [--out=PATH]';
    protected $options     = [
        '--out' => 'Куда сохранить markdown-отчёт (по умолчанию только печать в консоль).',
    ];

    public function run(array $params): int
    {
        $window = $this->equalWindow();
        if ($window === null) {
            CLI::error('Недостаточно данных: хотя бы одна из журнальных таблиц (transactions/crafted_items_log) пуста — равное окно построить не из чего.');
            return EXIT_ERROR;
        }
        [$start, $end] = $window;

        $buyers  = $this->charSet("SELECT character_id, MIN(created_at) AS first_at FROM transactions WHERE type = 'buy' AND created_at BETWEEN ? AND ? GROUP BY character_id", [$start, $end]);
        $crafters = $this->charSet("SELECT character_id, MIN(created_at) AS first_at FROM crafted_items_log WHERE created_at BETWEEN ? AND ? GROUP BY character_id", [$start, $end]);
        $sellers  = $this->charSet("SELECT character_id, MIN(created_at) AS first_at, MAX(created_at) AS last_at FROM transactions WHERE type = 'sell' AND created_at BETWEEN ? AND ? GROUP BY character_id", [$start, $end]);

        // Полный цикл = купил, собрал и продал внутри одного окна, и продажа случилась не раньше
        // первой покупки того же персонажа в этом окне (слабая проверка хронологии — точная
        // цепочка «эта покупка → этот крафт → эта продажа» по журналам недоступна без FK между
        // ними; это упрощение, зафиксировано в отчёте как метод, а не выдаётся за точный трейс).
        $fullCycleIds = [];
        foreach ($sellers as $charId => $sellRow) {
            if (! isset($buyers[$charId], $crafters[$charId])) {
                continue;
            }
            $lastSell = $sellRow['last_at'];
            $firstBuy = $buyers[$charId]['first_at'];
            if ($lastSell !== '' && $firstBuy !== '' && $lastSell >= $firstBuy) {
                $fullCycleIds[] = $charId;
            }
        }

        $totalCharsSeen = count(array_unique(array_merge(array_keys($buyers), array_keys($crafters), array_keys($sellers))));

        CLI::write('=== Аудит цикла «купил сырьё → собрал → продал» ===', 'yellow');
        CLI::write("Окно (пересечение покрытия всех трёх журналов): {$start} .. {$end}");
        CLI::write('Персонажей с покупкой в окне: ' . count($buyers));
        CLI::write('Персонажей с завершённым крафтом в окне: ' . count($crafters));
        CLI::write('Персонажей с продажей в окне: ' . count($sellers));
        CLI::write('Персонажей хоть с одним действием из тройки: ' . $totalCharsSeen);
        CLI::write('');

        $fullCycleCount = count($fullCycleIds);
        CLI::write("Персонажей с ПОЛНЫМ циклом (купил → собрал → продал, в одном окне): {$fullCycleCount}", 'green');
        if ($fullCycleCount < 20) {
            CLI::write('Выборка меньше 20 — по методологическому правилу это "сигнала нет", а не "цикла нет".', 'red');
        }

        $recipeRows = $this->recipeMargins($fullCycleIds, $start, $end);

        $grossFullCycle = 0.0;
        $printedGold    = 0.0;
        $unpriced       = 0;
        foreach ($recipeRows as $r) {
            $grossFullCycle += $r['revenue'];
            if ($r['margin_per_unit'] === null) {
                $unpriced++;
                continue;
            }
            $printedGold += $r['margin_per_unit'] * $r['qty'];
        }

        $totalSellAll = $this->scalar("SELECT COALESCE(SUM(price),0) v FROM transactions WHERE type = 'sell' AND created_at BETWEEN ? AND ?", [$start, $end], 'v');
        $share = $totalSellAll > 0 ? round($grossFullCycle / $totalSellAll * 100, 2) : 0.0;

        CLI::write('');
        CLI::write('Оборот от продаж полного цикла (валовая выручка): ' . round($grossFullCycle, 2) . ' золота');
        CLI::write('Оборот от всех продаж в окне (все персонажи): ' . round($totalSellAll, 2) . ' золота');
        CLI::write("Доля продаж полного цикла в общем обороте: {$share}%");
        CLI::write('Оценка напечатанного золота (выручка минус стоимость сырья по каталогу): ' . round($printedGold, 2) . ' золота'
            . ($unpriced > 0 ? " (рецептов без известной стоимости сырья: {$unpriced}, исключены из этой суммы)" : ''));
        CLI::write('');

        usort($recipeRows, static fn (array $a, array $b): int => ($b['margin_per_unit'] ?? -1) <=> ($a['margin_per_unit'] ?? -1));
        $top = array_slice($recipeRows, 0, 15);

        CLI::table(
            array_map(static fn (array $r): array => [
                (string) $r['name'],
                (string) $r['qty'],
                (string) round($r['revenue'], 2),
                $r['margin_per_unit'] === null ? 'н/д' : (string) round($r['margin_per_unit'], 2),
                $r['margin_per_unit'] === null ? 'н/д' : (string) round($r['margin_per_unit'] * $r['qty'], 2),
            ], $top),
            ['рецепт', 'продано (полный цикл)', 'выручка', 'маржа/шт', 'маржа×кол-во']
        );

        $report = $this->renderMarkdown(
            $start,
            $end,
            count($buyers),
            count($crafters),
            count($sellers),
            $totalCharsSeen,
            $fullCycleCount,
            $grossFullCycle,
            $totalSellAll,
            $share,
            $printedGold,
            $unpriced,
            $top
        );

        $outOpt = CLI::getOption('out');
        if (is_string($outOpt) && $outOpt !== '') {
            if (@file_put_contents($outOpt, $report) === false) {
                CLI::error("Не удалось записать отчёт в {$outOpt}");
                return EXIT_ERROR;
            }
            CLI::write("Отчёт сохранён: {$outOpt}", 'green');
        }

        return EXIT_SUCCESS;
    }

    /**
     * Равное окно = пересечение диапазонов, которые реально покрывают все три журнала.
     * Сравнение когорт в неравном окне систематически врёт в пользу более старой (см. правило
     * «когорты в равном окне»). Возвращает [start, end] или null, если какой-то журнал пуст.
     *
     * @return array{0:string,1:string}|null
     */
    private function equalWindow(): ?array
    {
        $bounds = $this->row(
            "SELECT
                (SELECT MIN(created_at) FROM transactions WHERE type = 'buy') AS buy_min,
                (SELECT MAX(created_at) FROM transactions WHERE type = 'buy') AS buy_max,
                (SELECT MIN(created_at) FROM crafted_items_log) AS craft_min,
                (SELECT MAX(created_at) FROM crafted_items_log) AS craft_max,
                (SELECT MIN(created_at) FROM transactions WHERE type = 'sell') AS sell_min,
                (SELECT MAX(created_at) FROM transactions WHERE type = 'sell') AS sell_max"
        );

        $mins = [$bounds['buy_min'] ?? null, $bounds['craft_min'] ?? null, $bounds['sell_min'] ?? null];
        $maxs = [$bounds['buy_max'] ?? null, $bounds['craft_max'] ?? null, $bounds['sell_max'] ?? null];
        if (in_array(null, $mins, true) || in_array(null, $maxs, true)) {
            return null;
        }

        $start = max($mins);
        $end   = min($maxs);
        if (! is_string($start) || ! is_string($end) || $start > $end) {
            return null;
        }

        return [$start, $end];
    }

    /**
     * @param list<string> $params
     * @return array<int,array<string,string>> character_id => агрегат (даты created_at как строки)
     */
    private function charSet(string $sql, array $params): array
    {
        $out = [];
        foreach ($this->rows($sql, $params) as $r) {
            $id = isset($r['character_id']) && is_numeric($r['character_id']) ? (int) $r['character_id'] : null;
            if ($id === null) {
                continue;
            }
            $out[$id] = [
                'first_at' => is_string($r['first_at'] ?? null) ? $r['first_at'] : '',
                'last_at'  => is_string($r['last_at'] ?? null) ? $r['last_at'] : '',
            ];
        }
        return $out;
    }

    /**
     * Продажи персонажей с полным циклом, сгруппированные по рецепту, с оценкой маржи на штуку.
     * Маржа = каталожная цена рецепта минус стоимость сырья (по `resources.buy_price`, с
     * fallback на `crafted_items.price`, если ингредиент сам является крафтовым полуфабрикатом).
     * Это оценка по каталогу, не по факту закупки конкретного персонажа — честно помечено «н/д»,
     * когда ингредиент не находится ни в одном справочнике.
     *
     * @param list<int> $fullCycleIds
     * @return list<array{name:string,qty:float,revenue:float,margin_per_unit:float|null}>
     */
    private function recipeMargins(array $fullCycleIds, string $start, string $end): array
    {
        if ($fullCycleIds === []) {
            return [];
        }

        $ids = implode(',', array_map('intval', $fullCycleIds));

        $sales = $this->rows(
            "SELECT t.crafted_item_id, ci.name_rus, ci.price AS catalog_price, ci.required_resources,
                    SUM(t.quantity) AS qty, SUM(t.price) AS revenue
             FROM transactions t
             JOIN crafted_items ci ON ci.id = t.crafted_item_id
             WHERE t.type = 'sell' AND t.character_id IN ({$ids}) AND t.created_at BETWEEN ? AND ?
             GROUP BY t.crafted_item_id, ci.name_rus, ci.price, ci.required_resources",
            [$start, $end]
        );

        // Справочники стоимости сырья: сперва базовые ресурсы (buy_price), затем крафтовые
        // полуфабрикаты (собственная каталожная цена) — по имени, как хранит required_resources.
        $resourcePrices = [];
        foreach ($this->rows('SELECT name, buy_price, price FROM resources', []) as $r) {
            $name = $r['name'] ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }
            $p = $r['buy_price'] ?? $r['price'] ?? null;
            $resourcePrices[$name] = is_numeric($p) ? (float) $p : null;
        }
        $craftedPrices = [];
        foreach ($this->rows('SELECT name_rus, price FROM crafted_items', []) as $r) {
            $name = $r['name_rus'] ?? null;
            if (! is_string($name) || $name === '' || isset($resourcePrices[$name])) {
                continue;
            }
            $craftedPrices[$name] = is_numeric($r['price'] ?? null) ? (float) $r['price'] : null;
        }

        $out = [];
        foreach ($sales as $row) {
            $reqJson      = is_string($row['required_resources'] ?? null) ? $row['required_resources'] : '';
            $materialCost = $this->materialCost($reqJson, $resourcePrices, $craftedPrices);
            $catalogPrice = is_numeric($row['catalog_price'] ?? null) ? (float) $row['catalog_price'] : null;

            $marginPerUnit = ($materialCost !== null && $catalogPrice !== null) ? $catalogPrice - $materialCost : null;

            $rawName = $row['name_rus'] ?? null;
            $name    = is_string($rawName) && $rawName !== '' ? $rawName : '#' . (is_numeric($row['crafted_item_id'] ?? null) ? (string) $row['crafted_item_id'] : '?');

            $out[] = [
                'name'            => $name,
                'qty'             => is_numeric($row['qty'] ?? null) ? (float) $row['qty'] : 0.0,
                'revenue'         => is_numeric($row['revenue'] ?? null) ? (float) $row['revenue'] : 0.0,
                'margin_per_unit' => $marginPerUnit,
            ];
        }

        return $out;
    }

    /**
     * Парсит `crafted_items.required_resources` (JSON {"Имя":qty,...}) и суммирует стоимость
     * по справочникам. Возвращает null, если хотя бы один ингредиент не найден нигде —
     * честное «не знаю», а не тихий ноль.
     *
     * @param array<string,float|null> $resourcePrices
     * @param array<string,float|null> $craftedPrices
     */
    private function materialCost(string $json, array $resourcePrices, array $craftedPrices): ?float
    {
        if ($json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        $sum = 0.0;
        foreach ($decoded as $name => $qty) {
            if (! is_numeric($qty)) {
                return null;
            }
            $unitPrice = $resourcePrices[$name] ?? $craftedPrices[$name] ?? null;
            if ($unitPrice === null) {
                return null;
            }
            $sum += $unitPrice * (float) $qty;
        }

        return $sum;
    }

    /**
     * @param list<array{name:string,qty:float,revenue:float,margin_per_unit:float|null}> $top
     */
    private function renderMarkdown(
        string $start,
        string $end,
        int $buyers,
        int $crafters,
        int $sellers,
        int $totalCharsSeen,
        int $fullCycleCount,
        float $grossFullCycle,
        float $totalSellAll,
        float $share,
        float $printedGold,
        int $unpriced,
        array $top
    ): string {
        $lines = [];
        $lines[] = '# Аудит: масштаб цикла «купил сырьё → собрал → продал»';
        $lines[] = '';
        $lines[] = 'Прогон: `php spark audit:craft-gold-loop` — ' . date('Y-m-d H:i:s') . ' (локальная БД, слепок testbot).';
        $lines[] = 'Источник — журналы действий (`transactions` type=buy|sell, `crafted_items_log`), не счётчики `resources_bank`';
        $lines[] = '(ADR-175: их «0» больше не значит «не покупали»). Read-only, ни один запрос не изменяет данные.';
        $lines[] = '';
        $lines[] = '## Окно';
        $lines[] = "Равное окно — пересечение диапазонов покрытия всех трёх журналов: **{$start} .. {$end}**.";
        $lines[] = 'Все три когорты (покупатели/крафтеры/продавцы) считаны из ОДНОГО этого окна, а не из полной истории каждого журнала.';
        $lines[] = '';
        $lines[] = '## Размер выборки';
        $lines[] = "- Персонажей с покупкой в окне: **{$buyers}**";
        $lines[] = "- Персонажей с завершённым крафтом в окне: **{$crafters}**";
        $lines[] = "- Персонажей с продажей в окне: **{$sellers}**";
        $lines[] = "- Персонажей хоть с одним действием из тройки: **{$totalCharsSeen}**";
        $lines[] = "- Персонажей с ПОЛНЫМ циклом (купил → собрал → продал, в этом окне): **{$fullCycleCount}**";
        if ($fullCycleCount < 20) {
            $lines[] = '';
            $lines[] = '> Выборка меньше 20 — по правилу проекта это читается как "сигнала нет", а не "цикла нет".'
                . ' Локальная БД — старый/малый слепок testbot; для решения по 41 рецепту нужен тот же прогон на актуальном дампе/на testbot.';
        }
        $lines[] = '';
        $lines[] = '## Методология «полного цикла»';
        $lines[] = 'Персонаж засчитан, если в окне есть хотя бы одна покупка (`transactions.type=buy`), хотя бы один';
        $lines[] = 'завершённый крафт (`crafted_items_log`) и хотя бы одна продажа (`transactions.type=sell`), и момент';
        $lines[] = 'последней продажи не раньше момента первой покупки того же персонажа. Это слабая проверка хронологии,';
        $lines[] = 'не точная цепочка «эта покупка → этот крафт → эта продажа» — прямого внешнего ключа между тремя';
        $lines[] = 'журналами нет. Зафиксировано как метод, а не выдано за точный трейс.';
        $lines[] = '';
        $lines[] = '## Золото';
        $lines[] = '- Валовая выручка от продаж персонажей полного цикла: **' . round($grossFullCycle, 2) . '** золота.';
        $lines[] = '- Валовая выручка от всех продаж в окне (все персонажи): **' . round($totalSellAll, 2) . '** золота.';
        $lines[] = "- Доля продаж полного цикла в общем обороте: **{$share}%**.";
        $lines[] = '- Оценка напечатанного золота (выручка минус стоимость сырья по каталогу `resources.buy_price`';
        $lines[] = '  / `crafted_items.price` как fallback для полуфабрикатов): **' . round($printedGold, 2) . '** золота'
            . ($unpriced > 0 ? " (рецептов без известной стоимости сырья: {$unpriced} — исключены из этой суммы, а не приняты за ноль)." : '.');
        $lines[] = '';
        $lines[] = '## Топ рецептов по марже (среди продаж полного цикла)';
        if ($top === []) {
            $lines[] = 'Пусто — в окне нет продаж, попадающих под определение полного цикла.';
        } else {
            $lines[] = '| Рецепт | Продано | Выручка | Маржа/шт | Маржа×кол-во |';
            $lines[] = '|---|---|---|---|---|';
            foreach ($top as $r) {
                $marginPerUnit = $r['margin_per_unit'] === null ? 'н/д' : (string) round($r['margin_per_unit'], 2);
                $marginTotal   = $r['margin_per_unit'] === null ? 'н/д' : (string) round($r['margin_per_unit'] * $r['qty'], 2);
                $lines[] = '| ' . $r['name'] . ' | ' . $r['qty'] . ' | ' . round($r['revenue'], 2) . ' | ' . $marginPerUnit . ' | ' . $marginTotal . ' |';
            }
        }
        $lines[] = '';
        $lines[] = '## Non-goals (напоминание)';
        $lines[] = 'Ничего не исправлено, прайсы рецептов и `sales` не тронуты. Выводов о судьбе фичи докупки недостающего';
        $lines[] = 'сырья (craft-shortfall-buy) здесь нет — цикл существует независимо от неё, решение по 41 рецепту принимает владелец.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>
     */
    private function row(string $sql, array $params = []): array
    {
        try {
            $q = Database::connect()->query($sql, $params);
            if (! $q instanceof BaseResult) {
                return [];
            }
            $row = $q->getRowArray();
            return is_array($row) ? $row : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        try {
            $q = Database::connect()->query($sql, $params);
            if (! $q instanceof BaseResult) {
                return [];
            }
            return array_values($q->getResultArray());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param list<mixed> $params
     */
    private function scalar(string $sql, array $params, string $key): float
    {
        $r = $this->row($sql, $params);
        return is_numeric($r[$key] ?? null) ? (float) $r[$key] : 0.0;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Player\Progression;

use App\Services\Buildings\BuildingGateService;
use App\Services\GameSettings\GameSettingsReaderTrait;
use CodeIgniter\Database\ResultInterface;
use Config\Database;
use Throwable;

/**
 * Слайс «Видимая лестница L1→L10» — что КОНКРЕТНО откроется на заданном уровне.
 *
 * Мотив (прод-аудит 2026-07-26): ранние уровни вовсе не пустые — на L2 становятся доступны
 * 18 новых ресурсов и 2 оружия, на L3 — 8 рецептов (аптечка, дрон-разведчик, мотыга), на L4 —
 * постройка «Мастерская», на L5 — 16 рецептов (верстак, роботы, удочка) и «Доменная печь».
 * Игрок об этом не знал НИЧЕГО: нигде в интерфейсе не сказано, что уровень вообще что-то
 * открывает. Награда за прогресс существовала и была невидимой — тот же класс дефекта, что
 * «невидимый множитель читается как сломанный».
 *
 * Числа считаются ПО БАЗЕ, а не зашиты в код: добавили рецепт/оружие/постройку — строка
 * сама станет правдивой (анти-дрейф; правило «числа баланса в текст из кода, а не руками»).
 * Системные вехи (специализация / Оракул / верстак T3 / коллекции / вторая база) читаются из
 * GameSettings — админ подвинул порог, текст поехал за ним. Постройки — из `Config\Buildings`
 * через {@see BuildingGateService}: колонка БД оказалась мёртвой (ADR-155), и до этой правки
 * строка обещала постройки не на тех уровнях, а Спортзал не обещала вовсе.
 *
 * Read-only. Одна SQL-выборка (UNION ALL) на уровень + процесс-кеш: карточка Персонажа —
 * горячий экран, N COUNT'ов на рендер там неуместны.
 *
 * Деградация: тестовая БД (`wildworld_tests`) контент-таблиц не содержит, поэтому любая
 * ошибка выборки = «нечего сказать» (null), а не исключение в карточке игрока.
 *
 * @see \App\Services\Player\Progression\LevelProgressService
 */
class LevelUnlockService
{
    use GameSettingsReaderTrait;

    /**
     * Контент-таблицы и колонка-гейт уровня.
     *
     * Постройки здесь ОТСУТСТВУЮТ намеренно (ADR-155): колонка `buildings.min_character_level`
     * декоративная и разошлась с реально проверяемым гейтом у 9 построек из 16 — считаем их
     * через {@see BuildingGateService} по тому же конфигу, который гейтит стройку.
     *
     * @var array<string, array{0: string, 1: string}> ключ → [таблица, колонка]
     */
    private const SOURCES = [
        'resource' => ['resources', 'level_required'],
        'craft'    => ['crafted_items', 'required_level'],
        'weapon'   => ['weapons', 'required_level'],
        'outfit'   => ['outfits', 'required_level'],
        'quest'    => ['quests', 'min_level'],
    ];

    /**
     * Порядок категорий в строке: сначала то, что новичок почувствует раньше (ресурсы под
     * ногами), потом рецепты, снаряжение, стройка, задания. Постройки в SOURCES отсутствуют —
     * они считаются по конфигу, а не по БД (ADR-155).
     *
     * @var list<string>
     */
    private const CATEGORY_ORDER = ['resource', 'craft', 'weapon', 'outfit', 'building', 'quest'];

    /**
     * Формы склонения для каждой категории: [одна, две, пять].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const FORMS = [
        'resource' => ['новый ресурс', 'новых ресурса', 'новых ресурсов'],
        'craft'    => ['рецепт', 'рецепта', 'рецептов'],
        'weapon'   => ['оружие', 'вида оружия', 'видов оружия'],
        'outfit'   => ['броня', 'вида брони', 'видов брони'],
        'building' => ['постройка', 'постройки', 'построек'],
        'quest'    => ['задание', 'задания', 'заданий'],
    ];

    /**
     * Системные вехи: ключ GameSettings с уровнем-порогом → что на нём открывается.
     * Читаются из настроек, поэтому переезд порога админом переносит и текст.
     *
     * @var array<string, array{0: string, 1: int}> ключ → [подпись, дефолт уровня]
     */
    private const SYSTEM_GATES = [
        'specialization.min_level'                => ['выбор специализации крафта', 5],
        'oracle.min_level'                        => ['ставки у Оракула острова', 10],
        'tier3.workbench.character_level_required' => ['профессиональный верстак', 20],
        'collections.min_level'                   => ['коллекции базы', 50],
    ];

    /** @var array<int, string|null> процесс-кеш готовых строк по уровню. */
    private static array $cache = [];

    /**
     * Строка «что откроется на уровне N», или null — если на этом уровне не открывается
     * ничего (честно молчим, а не выдумываем награду).
     *
     * Пример: «🎁 На 3-м уровне: 10 новых ресурсов, 8 рецептов, 1 вид оружия, 2 задания».
     */
    public function summaryFor(int $level): ?string
    {
        if ($level < 2) {
            return null; // ниже второго уровня подниматься некуда
        }
        if (array_key_exists($level, self::$cache)) {
            return self::$cache[$level];
        }

        $parts = [];
        foreach ($this->countsFor($level) as $key => $count) {
            if ($count < 1 || ! isset(self::FORMS[$key])) {
                continue;
            }
            [$one, $few, $many] = self::FORMS[$key];
            $parts[] = $count . ' ' . self::plural($count, $one, $few, $many);
        }

        foreach ($this->systemGatesFor($level) as $label) {
            $parts[] = $label;
        }

        $line = $parts === []
            ? null
            : sprintf('🎁 *На %d-м уровне:* %s', $level, implode(', ', $parts));

        return self::$cache[$level] = $line;
    }

    /**
     * Счётчики контента по категориям для уровня. Одна выборка UNION ALL вместо шести COUNT'ов.
     *
     * @return array<string, int>
     */
    protected function countsFor(int $level): array
    {
        try {
            $db     = Database::connect();
            $selects = [];
            $binds   = [];
            foreach (self::SOURCES as $key => [$table, $column]) {
                $selects[] = sprintf(
                    'SELECT %s AS k, COUNT(*) AS n FROM %s WHERE %s = ?',
                    $db->escape($key),
                    $db->prefixTable($table),
                    $db->protectIdentifiers($column)
                );
                $binds[] = $level;
            }
            $query = $db->query(implode(' UNION ALL ', $selects), $binds);
            if (! $query instanceof ResultInterface) {
                return [];
            }

            $counts = [];
            foreach ($query->getResultArray() as $row) {
                $key = $row['k'] ?? null;
                $n   = $row['n'] ?? null;
                if (is_string($key) && is_numeric($n)) {
                    $counts[$key] = (int) $n;
                }
            }

            // Постройки — НЕ из БД: колонка `buildings.min_character_level` декоративная и
            // разошлась с реально проверяемым гейтом у 9 построек из 16 (ADR-155). Считаем по
            // тому же источнику, который гейтит саму стройку.
            $counts['building'] = (new BuildingGateService())->countUnlockedAt($level);

            // Порядок категорий задаём мы, а не БД.
            $ordered = [];
            foreach (self::CATEGORY_ORDER as $key) {
                if (($counts[$key] ?? 0) > 0) {
                    $ordered[$key] = $counts[$key];
                }
            }

            return $ordered;
        } catch (Throwable $e) {
            log_message('warning', '[LevelUnlockService] counts failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Системные вехи, порог которых совпал с этим уровнем (читаются из GameSettings).
     *
     * @return list<string>
     */
    protected function systemGatesFor(int $level): array
    {
        $labels = [];
        foreach (self::SYSTEM_GATES as $key => [$label, $default]) {
            if ($this->gsInt($key, $default) === $level) {
                $labels[] = $label;
            }
        }

        // Вторая база: каждые N уровней (buildings.bases.levels_per_base).
        $perBase = $this->gsInt('buildings.bases.levels_per_base', 50);
        if ($perBase > 0 && $level % $perBase === 0) {
            $labels[] = 'место ещё под одну базу';
        }

        return $labels;
    }

    /** Сбрасывает процесс-кеш (нужен тестам и админ-правкам порогов в одном процессе). */
    public static function resetCache(): void
    {
        self::$cache = [];
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $mod100 = $n % 100;
        $mod10  = $n % 10;
        if ($mod100 > 10 && $mod100 < 20) {
            return $many;
        }
        if ($mod10 === 1) {
            return $one;
        }
        if ($mod10 > 1 && $mod10 < 5) {
            return $few;
        }

        return $many;
    }
}

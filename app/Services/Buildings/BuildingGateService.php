<?php

declare(strict_types=1);

namespace App\Services\Buildings;

use Config\Buildings;
use Config\Database;

/**
 * ADR-155, слайс 3 контент-паса L1→L10 — ЕДИНЫЙ источник ответа «на каком уровне открывается
 * постройка».
 *
 * Проблема (аудит 2026-07-26). Требуемый уровень постройки жил в ДВУХ местах и они разошлись
 * у 9 построек из 16:
 *   - `Config\Buildings[...]['level_required']` — то, что РЕАЛЬНО проверяется при стройке
 *     ({@see \App\Controllers\Telegram\Commands\Actions\Camp\GenericBuildingAction} шаг 5)
 *     и в превью ({@see \App\Controllers\Telegram\Commands\Actions\Camp\GenericBuildingInfoAction});
 *   - `buildings.min_character_level` (колонка БД) — то, что ПОКАЗЫВАЛОСЬ игроку: публичная
 *     вики построек, админский craft-tree и строка «что откроется на уровне N».
 * Примеры расхождения: Мастерская 4 против 1, Склад 6 против 1, Теплица 8 против 1,
 * Робототехника 6 против 10, Спортзал **0 против 5**.
 *
 * Последствия были player-facing: вики обещала «треб. ур. 8» там, где строить можно с первого,
 * а строка «🎁 На N-м уровне» (ADR-153) считала постройки по мёртвой колонке — Спортзал с её
 * точки зрения не открывался НИКОГДА (0 < 2), хотя реально открывается ровно на L5.
 *
 * Решение: рантайм больше не читает колонку. Все player-facing поверхности спрашивают этот
 * сервис, а он отвечает из `Config\Buildings` — того же массива, который гейтит саму стройку.
 * Дрейф становится невозможен по построению, а не «до следующего раза».
 *
 * Read-only, без БД: чистая выборка по конфигу + процесс-кеш.
 *
 * @see \App\Database\Migrations\Adr155SyncBuildingLevelGates синхронизация колонки для админки
 */
class BuildingGateService
{
    /** @var array<int, list<string>>|null уровень → русские названия построек (процесс-кеш). */
    private static ?array $byLevel = null;

    /** @var array<string, list<array{name: string, level: int, kind: string}>>|null материалы построек. */
    private static ?array $materials = null;

    private Buildings $config;

    public function __construct(?Buildings $config = null)
    {
        // config() отдаёт object|null — нарроим явно, вместо ослабления типа свойства.
        if ($config === null) {
            $resolved = config('Buildings');
            $config   = $resolved instanceof Buildings ? $resolved : new Buildings();
        }
        $this->config = $config;
    }

    /**
     * Требуемый уровень персонажа для постройки по ключу конфига (Gym / Warehouse / …).
     * Неизвестный ключ → 1 (как в BuildLockService: не выдумываем гейт).
     */
    public function requiredLevel(string $key): int
    {
        $recipes = $this->recipes();
        $recipe  = $recipes[$key] ?? null;
        if (! is_array($recipe) || ! isset($recipe['level_required']) || ! is_numeric($recipe['level_required'])) {
            return 1;
        }

        return max(1, (int) $recipe['level_required']);
    }

    /**
     * ФАКТИЧЕСКИЙ порог: уровень, раньше которого постройку не собрать своими руками —
     * max(объявленный гейт, уровень самого труднодоступного материала) (ADR-156).
     *
     * Мотив: объявленный гейт часто ничего не значит. Аудит 2026-07-26 — **13 построек из 16**
     * требуют материалы выше собственного гейта: Вышка связи объявлена с L1, а требует Нефть
     * (добыча с L20); Спортзал объявлен с L5, а требует Стекло пакеты (крафт с L25) и Янтарь
     * (добыча с L20). Эмпирика сходится: Спортзал на проде стоит у игроков L11, L29, L50, L51,
     * L64, L223 — на объявленном пятом его нет НИ У КОГО.
     *
     * ⚠️ Это не жёсткий запрет: материалы можно купить или выменять. Поэтому значение
     * используется там, где мы что-то ОБЕЩАЕМ игроку («что откроется на уровне N»), и не
     * подменяет проверяемый гейт при самой стройке.
     *
     * Деградация: нет контент-таблиц (тестовая БД) → возвращаем объявленный гейт.
     */
    public function effectiveLevel(string $key): int
    {
        $declared = $this->requiredLevel($key);
        $material = $this->materialLevels()[$key] ?? 0;

        return max($declared, $material);
    }

    /**
     * Русские названия построек, ФАКТИЧЕСКИЙ порог которых равен этому уровню.
     *
     * @return list<string>
     */
    public function unlockedAt(int $level): array
    {
        return $this->map()[$level] ?? [];
    }

    /** Сколько построек открывается ровно на этом уровне. */
    public function countUnlockedAt(int $level): int
    {
        return count($this->unlockedAt($level));
    }

    /**
     * Полная карта «уровень → постройки» (для сверки и админских поверхностей).
     *
     * @return array<int, list<string>>
     */
    public function map(): array
    {
        if (self::$byLevel !== null) {
            return self::$byLevel;
        }

        $map = [];
        foreach ($this->recipes() as $key => $recipe) {
            if (! is_array($recipe)) {
                continue;
            }
            // Карта строится по ФАКТИЧЕСКОМУ порогу (ADR-156): обещать постройку на уровне,
            // где её материалы ещё недостижимы, — то же враньё, что и прежняя мёртвая колонка.
            $level   = $this->effectiveLevel((string) $key);
            $nameRaw = $recipe['name_rus'] ?? null;
            $name    = is_string($nameRaw) && $nameRaw !== '' ? $nameRaw : (string) $key;

            $map[$level][] = $name;
        }

        foreach ($map as $level => $names) {
            sort($names);
            $map[$level] = $names;
        }
        ksort($map);

        return self::$byLevel = $map;
    }

    /**
     * Материалы, которые постройка требует, но которые игрок её уровня ещё не может добыть
     * или скрафтить сам. Для честной оговорки в превью стройки.
     *
     * @return list<array{name: string, level: int, kind: string}>
     */
    public function outOfReachMaterials(string $key, int $characterLevel): array
    {
        $out = [];
        foreach ($this->materialDetails()[$key] ?? [] as $row) {
            if ($row['level'] > $characterLevel) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** Сбрасывает процесс-кеш (тесты). */
    public static function resetCache(): void
    {
        self::$byLevel     = null;
        self::$materials   = null;
    }

    /**
     * Уровень самого труднодоступного материала каждой постройки.
     *
     * @return array<string, int>
     */
    private function materialLevels(): array
    {
        $levels = [];
        foreach ($this->materialDetails() as $key => $rows) {
            $max = 0;
            foreach ($rows as $row) {
                $max = max($max, $row['level']);
            }
            $levels[$key] = $max;
        }

        return $levels;
    }

    /**
     * Материалы всех построек с уровнем доступа. Две выборки на процесс, потом кеш.
     * Ошибка выборки (нет контент-таблиц в тестовой БД) → пустая карта, поведение
     * деградирует к объявленному гейту.
     *
     * @return array<string, list<array{name: string, level: int, kind: string}>>
     */
    protected function materialDetails(): array
    {
        if (self::$materials !== null) {
            return self::$materials;
        }

        $resources = [];
        $items     = [];
        try {
            $db = Database::connect();
            foreach ($this->fetchRows($db, 'resources', 'name_en, name, level_required') as $row) {
                $nameEn = $row['name_en'] ?? null;
                if (is_string($nameEn)) {
                    $resources[$nameEn] = [
                        'name'  => is_string($row['name'] ?? null) ? $row['name'] : $nameEn,
                        'level' => is_numeric($row['level_required'] ?? null) ? (int) $row['level_required'] : 0,
                    ];
                }
            }
            foreach ($this->fetchRows($db, 'crafted_items', 'name_eng, name_rus, required_level') as $row) {
                $nameEng = $row['name_eng'] ?? null;
                if (is_string($nameEng)) {
                    $items[$nameEng] = [
                        'name'  => is_string($row['name_rus'] ?? null) ? $row['name_rus'] : $nameEng,
                        'level' => is_numeric($row['required_level'] ?? null) ? (int) $row['required_level'] : 0,
                    ];
                }
            }
        } catch (\Throwable $e) {
            log_message('warning', '[BuildingGateService] material levels failed: ' . $e->getMessage());

            return self::$materials = [];
        }

        $map = [];
        foreach ($this->recipes() as $key => $recipe) {
            if (! is_array($recipe)) {
                continue;
            }
            $rows = [];
            $recipeRes   = is_array($recipe['resources'] ?? null) ? $recipe['resources'] : [];
            $recipeItems = is_array($recipe['crafted_items'] ?? null) ? $recipe['crafted_items'] : [];

            foreach (array_keys($recipeRes) as $nameEn) {
                $meta = $resources[$nameEn] ?? null;
                if ($meta !== null && $meta['level'] > 0) {
                    $rows[] = ['name' => $meta['name'], 'level' => $meta['level'], 'kind' => 'добыча'];
                }
            }
            foreach (array_keys($recipeItems) as $nameEng) {
                $meta = $items[$nameEng] ?? null;
                if ($meta !== null && $meta['level'] > 0) {
                    $rows[] = ['name' => $meta['name'], 'level' => $meta['level'], 'kind' => 'крафт'];
                }
            }
            usort($rows, static fn (array $a, array $b): int => $b['level'] <=> $a['level']);
            $map[(string) $key] = $rows;
        }

        return self::$materials = $map;
    }

    /**
     * Строки таблицы или пустой массив, если выборка не удалась (нарроим `get()`, который
     * отдаёт ResultInterface|false).
     *
     * @param \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(\CodeIgniter\Database\BaseConnection $db, string $table, string $select): array
    {
        $result = $db->table($table)->select($select)->get();

        return $result instanceof \CodeIgniter\Database\ResultInterface ? $result->getResultArray() : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function recipes(): array
    {
        /** @var array<string, mixed> $recipes */
        $recipes = $this->config->recipes;

        return $recipes;
    }
}

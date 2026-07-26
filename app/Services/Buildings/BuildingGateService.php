<?php

declare(strict_types=1);

namespace App\Services\Buildings;

use Config\Buildings;

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
final class BuildingGateService
{
    /** @var array<int, list<string>>|null уровень → русские названия построек (процесс-кеш). */
    private static ?array $byLevel = null;

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
     * Русские названия построек, которые открываются ровно на этом уровне.
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
            $lvlRaw = $recipe['level_required'] ?? null;
            $level  = is_numeric($lvlRaw) ? max(1, (int) $lvlRaw) : 1;
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

    /** Сбрасывает процесс-кеш (тесты). */
    public static function resetCache(): void
    {
        self::$byLevel = null;
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

<?php

declare(strict_types=1);

namespace App\Services\Buildings;

use App\Entities\CharacterEntity;
use App\Services\Duration\StatDurationInterpolator;
use App\Services\GameSettings\GameSettingsService;
use Config\Buildings;
use Config\Database;

/**
 * ADR-160 — единственная точка расчёта длительности СТРОЙКИ.
 *
 * До этого формула жила двумя копиями: `GenericBuildingAction::calculateDuration`
 * (реальное время задачи) и `GenericBuildingInfoAction::estimateDuration` (обещание
 * «Строительство займёт ~N минут» на экране постройки). Копии **уже разъехались** —
 * экран урезал статы до целых, стройка считала по дробным. Расхождение было в доли
 * минуты (граница округления), то есть игроку почти не врало, но это ровно тот класс,
 * что ADR-158 лечил внутри крафта: две копии расходятся всегда.
 *
 * Потолок счёта статов (исторически 2000 против 1000 у крафта) вынесен в
 * `GameSettings` — это балансовый параметр прогрессии, а не архитектурная константа.
 *
 * ⚠️ Стройка НЕ применяет множители времени (постройки / сытость / специализация /
 * проект фракции) — в отличие от крафта. Это исходное поведение, оно сохранено
 * байт-в-байт; вопрос «должна ли стройка ускоряться» — отдельное решение владельца
 * (см. ADR-160 §Открытый вопрос).
 *
 * ADR-161 добавил сюда же СПРАВОЧНОЕ время ({@see publishedMinutes}) — для поверхностей
 * без персонажа (публичная вики, админский craft-tree). До этого они публиковали
 * колонку `buildings.construction_time`, не связанную с задачей ничем: из 16 построек
 * она расходилась у 14 — девять завышали время (Склад 180 против 74), пять занижали
 * (Колючая ограда 45 против 70). Колонка больше не читается нигде, кроме собственной
 * модели и миграций.
 */
final class BuildDurationService
{
    public const SETTING_SCORE_CAP = 'buildings.duration.stat_score_cap';

    /** @var array<string,array{min:int,max:int}>|null ключ постройки → границы задачи (процесс-кеш). */
    private static ?array $ranges = null;

    private GameSettingsService $gameSettings;
    private Buildings $config;

    public function __construct(?GameSettingsService $gameSettings = null, ?Buildings $config = null)
    {
        $this->gameSettings = $gameSettings ?? new GameSettingsService();

        // config() отдаёт object|null — нарроим явно (как в BuildingGateService).
        if ($config === null) {
            $resolved = config('Buildings');
            $config   = $resolved instanceof Buildings ? $resolved : new Buildings();
        }
        $this->config = $config;
    }

    /**
     * Минуты стройки для персонажа по строке задачи `tasks`.
     *
     * `$taskRow === null` → `null`: экран информации в этом случае просто не показывает
     * строку времени, а сама стройка до расчёта не доходит (бейлится раньше с ошибкой
     * «Задача не найдена в таблице tasks»). Выдумывать число здесь нельзя — оно стало
     * бы четвёртым источником правды о времени стройки.
     *
     * @param array<array-key,mixed>|CharacterEntity $character
     * @param array<array-key,mixed>|null            $taskRow
     */
    public function minutes(array|CharacterEntity $character, ?array $taskRow): ?int
    {
        if ($taskRow === null) {
            return null;
        }

        $minD = $this->intOr($taskRow['min_duration'] ?? null, 0);
        $maxD = $this->intOr($taskRow['max_duration'] ?? null, 0);

        // Задача без верхней границы (напр. фиксированное время): интерполировать
        // нечего — отдаём нижнюю, как делал экран информации.
        if ($maxD <= 0) {
            return $minD > 0 ? $minD : null;
        }

        return StatDurationInterpolator::minutes($character, $minD, $maxD, $this->scoreCap());
    }

    /**
     * ADR-161 — время стройки для СПРАВОЧНЫХ поверхностей (публичная вики, админский
     * craft-tree), где персонажа нет.
     *
     * 🔴 Отдаём `max_duration` задачи, а НЕ диапазон «min–max» и не середину.
     *
     * Исходная причина (ADR-161) — быстрый край был недостижим никому при потолке 2000.
     * **ADR-162 эту причину снял:** потолок калиброван до 100, и нижний край теперь
     * реально берётся верхушкой прогрессии. Решение осталось прежним, но по другому
     * основанию: вики — АНОНИМНАЯ поверхность, персонажа у неё нет, а её читатель почти
     * всегда новичок, который получит ровно `max_duration`. Диапазон «56–74» на карточке
     * без объяснения, от чего он зависит, сообщал бы меньше правды, чем одно число.
     *
     * Личное время и то, что оно личное, игрок видит там, где персонаж известен — на
     * экране постройки: «~64 минут (новичку — 74 мин; ускоряют опыт, ловкость и
     * интеллект)» (`GenericBuildingInfoAction`). Публиковать диапазон и на вики — это
     * отдельное решение о поверхности, а не следствие калибровки.
     *
     * `null` = у постройки нет строки в `tasks`: выдумывать число нельзя, поверхность
     * просто не показывает время.
     */
    public function publishedMinutes(string $buildingKey): ?int
    {
        $range = $this->rangeFor($buildingKey);

        return $range === null ? null : $range['max'];
    }

    /**
     * Границы задачи постройки: `min` — время ветерана, `max` — новичка.
     *
     * @return array{min:int,max:int}|null
     */
    public function rangeFor(string $buildingKey): ?array
    {
        return $this->ranges()[$buildingKey] ?? null;
    }

    /**
     * Границы всех построек одной выборкой (справочники перебирают все 16 сразу).
     *
     * Деградация: нет таблицы `tasks` (тестовая БД) → пустая карта, поверхности молчат
     * о времени вместо выдуманного числа.
     *
     * @return array<string,array{min:int,max:int}>
     */
    public function ranges(): array
    {
        if (self::$ranges !== null) {
            return self::$ranges;
        }

        $taskNames = [];
        foreach ($this->recipes() as $key => $recipe) {
            if (! is_array($recipe)) {
                continue;
            }
            $taskName = $recipe['task_name'] ?? null;
            if (is_string($taskName) && $taskName !== '') {
                $taskNames[(string) $key] = $taskName;
            }
        }

        if ($taskNames === []) {
            return self::$ranges = [];
        }

        $rows = [];
        try {
            $result = Database::connect()
                ->table('tasks')
                ->select('name, min_duration, max_duration')
                ->whereIn('name', array_values($taskNames))
                ->get();

            if ($result instanceof \CodeIgniter\Database\ResultInterface) {
                foreach ($result->getResultArray() as $row) {
                    $name = $row['name'] ?? null;
                    if (is_string($name)) {
                        $rows[$name] = $row;
                    }
                }
            }
        } catch (\Throwable $e) {
            log_message('warning', '[BuildDurationService] task durations failed: ' . $e->getMessage());

            return self::$ranges = [];
        }

        $map = [];
        foreach ($taskNames as $key => $taskName) {
            $row = $rows[$taskName] ?? null;
            if ($row === null) {
                continue;
            }
            $min = $this->intOr($row['min_duration'] ?? null, 0);
            $max = $this->intOr($row['max_duration'] ?? null, 0);

            // Та же деградация, что в minutes(): задача без верхней границы живёт по нижней.
            if ($max <= 0) {
                $max = $min;
            }
            if ($min <= 0 && $max <= 0) {
                continue;
            }

            $map[$key] = ['min' => min($min, $max), 'max' => max($min, $max)];
        }

        return self::$ranges = $map;
    }

    /** Сбрасывает процесс-кеш границ (тесты). */
    public static function resetRangeCache(): void
    {
        self::$ranges = null;
    }

    /** Потолок счёта статов (admin-tunable, дефолт 2000). */
    public function scoreCap(): float
    {
        $raw = $this->gameSettings->get(
            self::SETTING_SCORE_CAP,
            StatDurationInterpolator::BUILD_SCORE_CAP
        );

        if (! is_numeric($raw)) {
            return StatDurationInterpolator::BUILD_SCORE_CAP;
        }

        $cap = (float) $raw;

        return $cap > 0.0 ? $cap : StatDurationInterpolator::BUILD_SCORE_CAP;
    }

    private function intOr(mixed $value, int $fallback): int
    {
        return is_numeric($value) ? (int) $value : $fallback;
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

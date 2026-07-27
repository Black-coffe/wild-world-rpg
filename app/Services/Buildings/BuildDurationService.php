<?php

declare(strict_types=1);

namespace App\Services\Buildings;

use App\Entities\CharacterEntity;
use App\Services\Duration\StatDurationInterpolator;
use App\Services\GameSettings\GameSettingsService;

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
 */
final class BuildDurationService
{
    public const SETTING_SCORE_CAP = 'buildings.duration.stat_score_cap';

    private GameSettingsService $gameSettings;

    public function __construct(?GameSettingsService $gameSettings = null)
    {
        $this->gameSettings = $gameSettings ?? new GameSettingsService();
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
}

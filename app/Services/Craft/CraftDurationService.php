<?php

declare(strict_types=1);

namespace App\Services\Craft;

use App\Entities\CharacterEntity;
use App\Services\BuildingEffects\BuildingEffectsService;
use App\Services\Duration\StatDurationInterpolator;
use App\Services\Food\FoodBuffService;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Player\FactionProjectService;
use App\Services\Player\SpecializationService;

/**
 * ADR-158 — единственная точка расчёта длительности крафта.
 *
 * Зачем сервис. Формула жила в двух местах и уже разъехалась: старт крафта
 * (`GenericCraftActionStart`) применял override часов и все четыре множителя, а
 * активация следующей задачи ИЗ ОЧЕРЕДИ (`GenericCraftCompletionHandler`) считала
 * голую интерполяцию по статам — без единого множителя, при комментарии «та сама
 * формула». Практический эффект: у игрока с Мастерской L10 первая вещь крафтилась
 * за 55% времени, а вторая из той же очереди — за 100%.
 *
 * Второе назначение — разбивка. Свободный стек множителей достигает ×0.22 (−78%),
 * но игрок его не видел: экран показывал итоговое время, и долгий крафт читался как
 * поломка, а вложения в постройки и специализацию — как бесполезные. Сервис отдаёт
 * не только минуты, но и список того, ЧТО именно ускорило.
 *
 * Порядок применения (мультипликативно, как было):
 *   база(статы, override) × постройки × сытость × специализация × фракц-проект
 */
class CraftDurationService
{
    // ADR-160: веса статов (0.3 / 0.3 / 0.4) и сама интерполяция живут в
    // StatDurationInterpolator — они общие со стройкой.

    /** ADR-159: пол длительности, если ключ `craft.min_duration_min` недоступен. */
    private const DEFAULT_FLOOR_MINUTES = 10;

    private GameSettingsService $gameSettings;
    private BuildingEffectsService $buildingEffects;
    private FoodBuffService $food;
    private SpecializationService $specialization;
    private FactionProjectService $faction;

    public function __construct(
        ?GameSettingsService $gameSettings = null,
        ?BuildingEffectsService $buildingEffects = null,
        ?FoodBuffService $food = null,
        ?SpecializationService $specialization = null,
        ?FactionProjectService $faction = null
    ) {
        $this->gameSettings    = $gameSettings ?? new GameSettingsService();
        $this->buildingEffects = $buildingEffects ?? new BuildingEffectsService();
        $this->food            = $food ?? new FoodBuffService();
        $this->specialization  = $specialization ?? new SpecializationService();
        $this->faction         = $faction ?? new FactionProjectService();
    }

    /**
     * Длительность крафта ОДНОЙ штуки со всей разбивкой.
     *
     * @param array<array-key,mixed>|CharacterEntity $character
     * @param array<array-key,mixed> $taskRow строка `tasks` (min_duration/max_duration в минутах)
     * @param array<array-key,mixed> $recipe запись из Config\CraftRecipes
     */
    public function forOne(array|CharacterEntity $character, array $taskRow, array $recipe = []): CraftDurationBreakdown
    {
        $base    = $this->baseMinutes($character, $taskRow, $recipe);
        $factors = $this->factors($character, $recipe);

        $multiplier = 1.0;
        foreach ($factors as $factor) {
            $multiplier *= $factor['mult'];
        }

        $compressed = max(1, (int) round($base * $multiplier));

        // ADR-159: множители не могут пробить коридор тайм-гейтов. Свободный стек
        // достигает ×0.22, и 15-минутная задача превращалась в 3 минуты — крафт
        // перестаёт быть выбором «занять время», а тайм-гейт, на котором держится
        // темп игры, обходится вложениями в постройки.
        //
        // Пол применяется как ограничитель СЖАТИЯ, а не как минимальное время:
        // min(база, пол). Иначе пол УДЛИНИЛ бы короткие по замыслу рецепты
        // («Тряпичная рубаха» — база 3 мин, готовка — 10 мин) и превратил бы
        // страховку баланса в скрытый нерф раннего крафта.
        $floor      = $this->floorMinutes();
        $floorLimit = $floor > 0 ? min($base, $floor) : 0;
        $minutes    = max($compressed, $floorLimit);

        return new CraftDurationBreakdown(
            $minutes,
            $base,
            $factors,
            $minutes > $compressed ? $floorLimit : null
        );
    }

    /**
     * Итоговые минуты на одну штуку — короткий путь для вызовов, которым разбивка не нужна.
     *
     * @param array<array-key,mixed>|CharacterEntity $character
     * @param array<array-key,mixed> $taskRow
     * @param array<array-key,mixed> $recipe
     */
    public function minutesForOne(array|CharacterEntity $character, array $taskRow, array $recipe = []): int
    {
        return $this->forOne($character, $taskRow, $recipe)->minutes;
    }

    /**
     * База: интерполяция по статам между min/max задачи, либо фиксированные часы из
     * GameSettings (`recipe.duration_override_setting_key`, ADR-026 — хранит ЧАСЫ).
     *
     * @param array<array-key,mixed>|CharacterEntity $character
     * @param array<array-key,mixed> $taskRow
     * @param array<array-key,mixed> $recipe
     */
    private function baseMinutes(array|CharacterEntity $character, array $taskRow, array $recipe): int
    {
        $minD = (int) $this->num($taskRow['min_duration'] ?? 0);
        $maxD = (int) $this->num($taskRow['max_duration'] ?? 0);

        $overrideKey = isset($recipe['duration_override_setting_key']) && is_string($recipe['duration_override_setting_key'])
            ? $recipe['duration_override_setting_key']
            : null;
        if ($overrideKey !== null) {
            $hours = $this->gameSettings->get($overrideKey, null);
            if (is_numeric($hours) && (int) $hours > 0) {
                $minD = (int) $hours * 60;
                $maxD = $minD;
            }
        }

        // ADR-160: интерполяция по статам — общая с стройкой (веса жили в двух файлах;
        // у стройки к тому же две копии уже разъехались). Различается только потолок
        // счёта: у крафта 1000, у стройки 2000.
        return StatDurationInterpolator::minutes(
            $character,
            $minD,
            $maxD,
            StatDurationInterpolator::CRAFT_SCORE_CAP
        );
    }

    /**
     * @param array<array-key,mixed>|CharacterEntity $character
     * @param array<array-key,mixed> $recipe
     * @return list<array{label:string, mult:float}>
     */
    private function factors(array|CharacterEntity $character, array $recipe): array
    {
        $charId = (int) $this->num($character['id'] ?? 0);

        $extraBuilding = isset($recipe['boost_building_time']) && is_string($recipe['boost_building_time'])
            ? $recipe['boost_building_time']
            : null;
        $factors = $this->buildingFactors($charId, $extraBuilding);

        $foodMult = $this->foodMultiplier($character['well_fed_until'] ?? null);
        if ($foodMult < 1.0) {
            $factors[] = ['label' => 'Сытость', 'mult' => $foodMult];
        }

        $specRaw  = $character['specialization'] ?? null;
        $specMult = $this->specMultiplier(
            is_string($specRaw) ? $specRaw : null,
            $recipe,
            (int) $this->num($character['level'] ?? 0)
        );
        if ($specMult < 1.0) {
            $factors[] = [
                'label' => is_string($specRaw) && $specRaw !== '' ? 'Специализация' : 'Мастерство',
                'mult'  => $specMult,
            ];
        }

        $factionMult = $this->factionMultiplier($charId);
        if ($factionMult < 1.0) {
            $factors[] = ['label' => 'Проект фракции', 'mult' => $factionMult];
        }

        return $factors;
    }

    /**
     * Четыре чтения у внешних сервисов вынесены в отдельные методы-seam'ы: сами
     * сервисы объявлены final, а подменять в тестах нужно именно их ответы. Сборка
     * разбивки (пороги, подписи, порядок) при этом остаётся настоящей и под тестом.
     *
     * @return list<array{label:string, mult:float}>
     */
    protected function buildingFactors(int $charId, ?string $extraBuilding): array
    {
        return $this->buildingEffects->craftTimeFactors($charId, $extraBuilding);
    }

    protected function foodMultiplier(mixed $wellFedUntil): float
    {
        return $this->food->craftTimeMultiplierFor($wellFedUntil);
    }

    /** @param array<array-key,mixed> $recipe */
    protected function specMultiplier(?string $branch, array $recipe, int $level): float
    {
        return $this->specialization->getCraftTimeMultiplierFor($branch, $recipe, $level);
    }

    protected function factionMultiplier(int $charId): float
    {
        return $this->faction->craftTimeMultiplierFor($charId);
    }

    /**
     * ADR-159 — пол длительности крафта (минуты, admin-tunable).
     *
     * 0 = выключено (прежнее поведение: единственный ограничитель — 1 минута).
     */
    protected function floorMinutes(): int
    {
        $raw = $this->gameSettings->get('craft.min_duration_min', self::DEFAULT_FLOOR_MINUTES);

        return is_numeric($raw) ? max(0, (int) $raw) : self::DEFAULT_FLOOR_MINUTES;
    }

    private function num(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}

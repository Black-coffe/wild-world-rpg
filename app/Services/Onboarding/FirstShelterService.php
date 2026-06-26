<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Models\CharacterBuildingModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use Config\Database;

/**
 * S5 (ROADMAP-RETENTION-10, ADR-142) — «первое укрытие» (Навес/LeanTo): дофамин первой постройки.
 *
 * Проблема (пере-срез A+B 2026-06-20): OnbStepBuild — #1 утечка онбординг-воронки
 * (Build-конверсия 9%). Реальная стена первой постройки = ресурс-стоимость + crafted_items +
 * 60-мин таймер обычных зданий, недоступные L1-новичку (не уровень — у дешёвых зданий он и так 1).
 *
 * Решение: дешёвое one-shot ПЕРВОЕ укрытие «Навес» (Config\Buildings 'LeanTo': 25 дерева +
 * 10 воды, БЕЗ крафта/зависимостей, ~3 мин, налог 0). Этот сервис гейтит ВИДИМОСТЬ его кнопки
 * в {@see \App\Controllers\Telegram\Commands\Actions\Camp\BuildListAction}: показываем ТОЛЬКО
 * новичку (level ≤ max_level) без единой постройки (и без постройки в работе) → после первой
 * стройки кнопка исчезает = one-shot (§5.4-исключение: быстрый тайм-гейт оправдан разовостью).
 *
 * Засчитывается в OnbStepBuild автоматически через objective_type 'any_building' (как любое
 * здание) — отдельного кода не требует.
 *
 * Гейт — killswitch `onboarding.first_build.enabled` (default OFF → dormant: кнопка не
 * добавляется, BuildListAction byte-identical). Паттерн зеркалит {@see BuildLockService}
 * (read-only сервис + GameSettingsReaderTrait + overridable seam для CI без БД).
 */
class FirstShelterService
{
    use GameSettingsReaderTrait;

    /** Ключ рецепта/здания (= Config\Buildings ключ = buildings.name_en). */
    public const BUILDING_KEY = 'LeanTo';

    /** Killswitch S5: предлагать ли «Навес» новичкам. Default OFF → dormant. */
    public function enabled(): bool
    {
        return $this->gsBool('onboarding.first_build.enabled', false);
    }

    /** Потолок уровня «новичка», которому показываем Навес (единый порог с contextual_hints). */
    public function maxLevel(): int
    {
        return $this->gsInt('onboarding.first_build.max_level', 6);
    }

    /**
     * Предлагать ли «Навес» этому персонажу: killswitch ON + новичок (1 ≤ level ≤ max) +
     * НИ ОДНОЙ постройки (готовой или в работе) → one-shot первое укрытие. Уровневый гейт
     * первым (без БД) — ветераны отсекаются дёшево, DB-запрос только для новичков.
     */
    public function shouldOffer(int $charId, int $level): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        if ($level < 1 || $level > $this->maxLevel()) {
            return false;
        }
        if ($charId <= 0) {
            return false;
        }

        return ! $this->hasAnyBuildingOrInflight($charId);
    }

    /**
     * Запись кнопки «Навес» для списка построек (формат массива $buildingsInfo в BuildListAction).
     * Налог 0 — новичка не нагружаем. callback_data ловит generic prefix-dispatch
     * (genericBuildInfo_*), доп. роут не нужен.
     *
     * @return array{name: string, tax: int, callback_data: string}
     */
    public function buttonEntry(): array
    {
        return [
            'name'          => '⛺ Навес — первое укрытие',
            'tax'           => 0,
            'callback_data' => 'genericBuildInfo_' . self::BUILDING_KEY,
        ];
    }

    // ── Overridable seam (тесты подменяют без БД) ────────────────────────────

    /**
     * Есть ли у персонажа ХОТЬ ОДНА постройка (готовая в character_buildings ИЛИ стройка
     * в работе/очереди — generic_building task). Готовая → Навес уже не первый; in-flight →
     * не даём запустить второй Навес, пока первый строится (честный one-shot в окне ~3 мин).
     */
    protected function hasAnyBuildingOrInflight(int $charId): bool
    {
        $built = (new CharacterBuildingModel())
            ->where('character_id', $charId)
            ->countAllResults();
        if (is_numeric($built) && (int) $built > 0) {
            return true;
        }

        $inflight = Database::connect()->table('character_tasks ct')
            ->join('tasks t', 't.id = ct.task_id')
            ->where('ct.character_id', $charId)
            ->whereIn('ct.status', ['in_work', 'queued'])
            ->where('t.handler_key', 'generic_building')
            ->countAllResults();

        return is_numeric($inflight) && (int) $inflight > 0;
    }
}

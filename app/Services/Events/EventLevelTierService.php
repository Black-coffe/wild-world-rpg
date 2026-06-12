<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Services\GameSettings\GameSettingsReaderTrait;

/**
 * E17 (ADR-117) — уровневый tier-модификатор воздействия ВРЕДНЫХ событий.
 *
 * Цель: события перестают быть «одинаковыми для L1 и L60» — мягко: новичков события скорее обучают
 * (сниженный вред), ветеранов — испытывают (полный/повышенный вред). Применяется множителем к
 * магнитуде вредных эффектов (урон HP, потеря ресурсов, gather-дебафф).
 *
 * Killswitch `world.events.level_tier_enabled` (default false → harmFactor=1.0 = byte-identical,
 * fixture-fence-safe). Класс читает GameSettings (кэш 60с). Чистая логика — harmFactor детерминирован.
 */
final class EventLevelTierService
{
    use GameSettingsReaderTrait;

    /** Активен ли уровневый tier (killswitch). */
    public function enabled(): bool
    {
        return $this->gsBool('world.events.level_tier_enabled', false);
    }

    /**
     * Множитель вреда события по уровню персонажа:
     *  - dormant → 1.0 (без изменений);
     *  - level ≤ newbie_max → newbie_factor (<1.0, щадит/обучает);
     *  - level ≥ veteran_min → veteran_factor (≥1.0, испытывает);
     *  - середина → 1.0.
     */
    public function harmFactor(int $level): float
    {
        if (! $this->enabled()) {
            return 1.0;
        }
        $level     = max(1, $level);
        $newbieMax = $this->gsInt('world.events.tier_newbie_max_level', 9);
        $vetMin    = $this->gsInt('world.events.tier_veteran_min_level', 25);

        if ($level <= $newbieMax) {
            return max(0.0, $this->gsFloat('world.events.tier_newbie_factor', 0.5));
        }
        if ($level >= $vetMin) {
            return max(0.0, $this->gsFloat('world.events.tier_veteran_factor', 1.3));
        }
        return 1.0;
    }

    /** Уровень из персонажа (array|CharacterEntity) → harmFactor. Хелпер для effect-классов. */
    public function harmFactorFor(mixed $character): float
    {
        $raw = is_array($character) || $character instanceof \ArrayAccess ? ($character['level'] ?? 1) : 1;
        return $this->harmFactor(is_numeric($raw) ? (int) $raw : 1);
    }

    // ── E17 Ф2: tier ПОЛЕЗНЫХ эффектов (boonFactor) ────────────────────────

    /**
     * Активен ли tier ПОЛЕЗНЫХ эффектов (отдельный killswitch от harm).
     * Отдельный — чтобы боны можно было выкатить dormant и активировать после ревью,
     * не трогая уже активный harm-tier (E17 Ф1 live на проде).
     */
    public function boonEnabled(): bool
    {
        return $this->gsBool('world.events.boon_tier_enabled', false);
    }

    /**
     * Множитель ПОЛЬЗЫ события по уровню (симметрия harmFactor, но без faucet ветеранам):
     *  - dormant → 1.0 (byte-identical);
     *  - level ≤ newbie_max → boon_newbie_factor (>1.0, щедрее — лечит/даёт больше, облегчает стену L1);
     *  - level ≥ veteran_min → boon_veteran_factor (1.0 по умолчанию — без инфляции, бонус-флат и так тривиален);
     *  - середина → 1.0.
     *
     * Мир щадит новичка (вред ×0.5 + польза ×1.3) и испытывает ветерана (вред ×1.3 + польза ×1.0).
     */
    public function boonFactor(int $level): float
    {
        if (! $this->boonEnabled()) {
            return 1.0;
        }
        $level     = max(1, $level);
        $newbieMax = $this->gsInt('world.events.tier_newbie_max_level', 9);
        $vetMin    = $this->gsInt('world.events.tier_veteran_min_level', 25);

        if ($level <= $newbieMax) {
            return max(0.0, $this->gsFloat('world.events.tier_boon_newbie_factor', 1.3));
        }
        if ($level >= $vetMin) {
            return max(0.0, $this->gsFloat('world.events.tier_boon_veteran_factor', 1.0));
        }
        return 1.0;
    }

    /** Уровень из персонажа (array|CharacterEntity) → boonFactor. Хелпер для effect-классов. */
    public function boonFactorFor(mixed $character): float
    {
        $raw = is_array($character) || $character instanceof \ArrayAccess ? ($character['level'] ?? 1) : 1;
        return $this->boonFactor(is_numeric($raw) ? (int) $raw : 1);
    }
}

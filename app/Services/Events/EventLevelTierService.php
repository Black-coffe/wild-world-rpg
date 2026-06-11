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
}

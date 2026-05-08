<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\CharacterModel;

/**
 * v0.51.70 (NotificationPolicy decomp Step 3) — extract sectoring/recipients
 * у dedicated finder.
 *
 * Public API:
 *   resolveBiomeIds(eventRow): list<int>      — JSON decode events.biome_ids ([] = global)
 *   findRecipientChars(biomeIds): list<array> — characters у biomes (або всі якщо global)
 *
 * Sectoring rule: empty biome_ids array → broadcast (global event);
 * non-empty → whereIn('biome_id', $ids) для гравців у affected biomes.
 */
final class EventRecipientFinder
{
    private CharacterModel $charModel;

    public function __construct(?CharacterModel $charModel = null)
    {
        $this->charModel = $charModel ?? new CharacterModel();
    }

    /**
     * Resolve biome_ids з events.biome_ids JSON. Empty array означает global → ALL biomes.
     *
     * @param array<string, mixed> $eventRow
     * @return list<int>
     */
    public function resolveBiomeIds(array $eventRow): array
    {
        $raw = $eventRow['biome_ids'] ?? null;
        $arr = json_decode($raw, true);
        if (!is_array($arr) || empty($arr)) {
            return [];
        }
        return array_map('intval', $arr);
    }

    /**
     * Найти characters у зазначених biomes (або всі якщо порожній список = global).
     *
     * @param list<int> $biomeIds
     * @return list<array<string, mixed>>
     */
    public function findRecipientChars(array $biomeIds): array
    {
        if (empty($biomeIds)) {
            return $this->charModel->findAll();
        }
        return $this->charModel->whereIn('biome_id', $biomeIds)->findAll();
    }
}

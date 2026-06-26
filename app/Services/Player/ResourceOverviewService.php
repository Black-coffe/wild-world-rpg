<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Services\GameSettings\GameSettingsService;
use Config\Database;

/**
 * Slice 1 (инициатива «ресурс-грамотность», след жалобы Arseny 2026-06-26) —
 * read-only агрегатор «📊 Все мои ресурсы»: единый срез ВСЕХ мест, где у игрока
 * лежат ресурсы/предметы, в одном экране.
 *
 * Аудит-факт: 5 хранилищ, и ВСЕ они per-character (общий пул), НЕ пер-базные —
 * даже при нескольких базах запас единый (base_storage.arrived_from_cell = ярлык,
 * не маршрутизация). Поэтому «итог» честно один на персонажа.
 *
 * Места: 🎒 character_resources (добыча) / 🔨 crafted_items_log (крафт) /
 * ⚔️ characters_weapons / 🛡 characters_outfits / 📦 base_storage (карго-дрон) / 💰 gold.
 *
 * net_worth = gold + стоимость добычи (×sell_price) + стоимость крафта (×price)
 * + стоимость склада базы (×sell_price). Зеркалит формулу {@see PlayerEconomyService}
 * + ЧИНИТ её пропуск base_storage (аудит view-surfaces). Оружие/броня — счётчики
 * (в net worth не входят, как и в «Моей экономике»).
 *
 * Killswitch inventory.overview.enabled (GameSettings, категория world, default OFF).
 */
final class ResourceOverviewService
{
    /** Killswitch: экран и кнопка входа активны только при inventory.overview.enabled. */
    public function enabled(): bool
    {
        try {
            $v = (new GameSettingsService())->get('inventory.overview.enabled', false);
        } catch (\Throwable) {
            return false;
        }
        if (is_bool($v)) {
            return $v;
        }
        return is_numeric($v) && (int) $v === 1;
    }

    /**
     * Срез всех мест хранения персонажа.
     *
     * @return array{
     *   backpack: array{kinds:int, units:int, value:int},
     *   crafted: array{kinds:int, units:int, value:int},
     *   weapons: int,
     *   outfits: int,
     *   base_storage: array{kinds:int, units:int, value:int},
     *   gold: int,
     *   net_worth: int
     * }
     */
    public function summary(int $charId): array
    {
        $db = Database::connect();

        $q       = $db->table('characters')->select('COALESCE(gold,0) AS gold')->where('id', $charId)->get();
        $goldRow = $q === false ? null : $q->getRowArray();
        $gold    = $this->toInt(is_array($goldRow) ? ($goldRow['gold'] ?? 0) : 0);

        $backpack = $this->aggResources('character_resources', 'id_characters', 'id_resources', $charId);
        $crafted  = $this->aggCrafted($charId);
        $weapons  = $this->sumQty('characters_weapons', 'character_id', $charId);
        $outfits  = $this->sumQty('characters_outfits', 'character_id', $charId);
        $storage  = $this->aggResources('base_storage', 'character_id', 'resource_id', $charId);

        return [
            'backpack'     => $backpack,
            'crafted'      => $crafted,
            'weapons'      => $weapons,
            'outfits'      => $outfits,
            'base_storage' => $storage,
            'gold'         => $gold,
            'net_worth'    => $gold + $backpack['value'] + $crafted['value'] + $storage['value'],
        ];
    }

    /**
     * Агрегат ресурс-хранилища (character_resources / base_storage): виды (строки),
     * штуки (Σ quantity), стоимость (Σ quantity × sell_price). Только quantity > 0.
     *
     * @return array{kinds:int, units:int, value:int}
     */
    private function aggResources(string $table, string $charCol, string $resCol, int $charId): array
    {
        $q = Database::connect()->table($table . ' t')
            ->select('COUNT(*) AS kinds, COALESCE(SUM(t.quantity),0) AS units, COALESCE(SUM(t.quantity * r.sell_price),0) AS value')
            ->join('resources r', 'r.id = t.' . $resCol)
            ->where('t.' . $charCol, $charId)
            ->where('t.quantity >', 0)
            ->get();
        $row = $q === false ? null : $q->getRowArray();

        return [
            'kinds' => $this->toInt(is_array($row) ? ($row['kinds'] ?? 0) : 0),
            'units' => $this->toInt(is_array($row) ? ($row['units'] ?? 0) : 0),
            'value' => $this->toInt(is_array($row) ? ($row['value'] ?? 0) : 0),
        ];
    }

    /**
     * Агрегат крафтовых предметов: виды (DISTINCT item), штуки (Σ quantity),
     * стоимость (Σ quantity × price). Только quantity > 0.
     *
     * @return array{kinds:int, units:int, value:int}
     */
    private function aggCrafted(int $charId): array
    {
        $q = Database::connect()->table('crafted_items_log cil')
            ->select('COUNT(DISTINCT cil.crafted_item_id) AS kinds, COALESCE(SUM(cil.quantity),0) AS units, COALESCE(SUM(cil.quantity * ci.price),0) AS value')
            ->join('crafted_items ci', 'ci.id = cil.crafted_item_id')
            ->where('cil.character_id', $charId)
            ->where('cil.quantity >', 0)
            ->get();
        $row = $q === false ? null : $q->getRowArray();

        return [
            'kinds' => $this->toInt(is_array($row) ? ($row['kinds'] ?? 0) : 0),
            'units' => $this->toInt(is_array($row) ? ($row['units'] ?? 0) : 0),
            'value' => $this->toInt(is_array($row) ? ($row['value'] ?? 0) : 0),
        ];
    }

    /** Σ quantity по простому per-character хранилищу (оружие/броня). */
    private function sumQty(string $table, string $charCol, int $charId): int
    {
        $q = Database::connect()->table($table)
            ->select('COALESCE(SUM(quantity),0) AS units')
            ->where($charCol, $charId)
            ->where('quantity >', 0)
            ->get();
        $row = $q === false ? null : $q->getRowArray();

        return $this->toInt(is_array($row) ? ($row['units'] ?? 0) : 0);
    }

    private function toInt(mixed $v): int
    {
        return is_numeric($v) ? (int) round((float) $v) : 0;
    }
}

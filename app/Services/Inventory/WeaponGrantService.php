<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\ResultInterface;
use Config\Database;

/**
 * Выдача оружия персонажу: upsert `characters_weapons` (+qty к имеющейся строке пары, иначе новая
 * строка с полной прочностью из определения `weapons.durability`, не экипировано).
 *
 * Вынесено из `LootTableService::grantWeapon` (ADR-107, PvE-лут), чтобы тем же путём выдавал оружие
 * стратегический лут (`StrategicLootHandler`, раздел `weapons`, H6.1). PvE зовёт с qty=1 — поведение
 * прежнее.
 */
class WeaponGrantService
{
    /**
     * @return array{id: int, name: string, rarity: string}|null null — оружия нет или qty ≤ 0
     */
    public function grantById(int $characterId, int $weaponId, int $qty = 1): ?array
    {
        if ($weaponId <= 0) {
            return null;
        }

        return $this->grantRow(
            $characterId,
            $this->rowOrNull(Database::connect()->table('weapons')->where('id', $weaponId)),
            $qty
        );
    }

    /**
     * @return array{id: int, name: string, rarity: string}|null null — нет оружия с таким `name_en` или qty ≤ 0
     */
    public function grantByNameEn(int $characterId, string $nameEn, int $qty = 1): ?array
    {
        if ($nameEn === '') {
            return null;
        }

        return $this->grantRow(
            $characterId,
            $this->rowOrNull(Database::connect()->table('weapons')->where('name_en', $nameEn)),
            $qty
        );
    }

    /**
     * @param array<string, mixed>|null $weapon
     * @return array{id: int, name: string, rarity: string}|null
     */
    private function grantRow(int $characterId, ?array $weapon, int $qty): ?array
    {
        if ($weapon === null || $characterId <= 0 || $qty <= 0 || ! is_numeric($weapon['id'] ?? null)) {
            return null;
        }
        $weaponId = (int) $weapon['id'];
        $db       = Database::connect();

        $existing = $this->rowOrNull(
            $db->table('characters_weapons')
                ->where('character_id', $characterId)
                ->where('weapon_id', $weaponId)
        );

        if (is_array($existing) && is_numeric($existing['id'] ?? null)) {
            $qRaw = $existing['quantity'] ?? 0;
            $db->table('characters_weapons')->where('id', (int) $existing['id'])->update([
                'quantity' => (is_numeric($qRaw) ? (int) $qRaw : 0) + $qty,
            ]);
        } else {
            $durRaw = $weapon['durability'] ?? 0;
            $db->table('characters_weapons')->insert([
                'character_id'       => $characterId,
                'weapon_id'          => $weaponId,
                'quantity'           => $qty,
                'current_durability' => is_numeric($durRaw) ? (int) $durRaw : 0,
                'equipped'           => 0,
            ]);
        }

        return [
            'id'     => $weaponId,
            'name'   => is_scalar($weapon['name'] ?? null) ? (string) $weapon['name'] : ('#' . $weaponId),
            'rarity' => is_scalar($weapon['rarity'] ?? null) ? (string) $weapon['rarity'] : '',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowOrNull(BaseBuilder $builder): ?array
    {
        $q = $builder->get();
        if (! $q instanceof ResultInterface) {
            return null;
        }
        $row = $q->getRowArray();

        return is_array($row) ? $row : null;
    }
}

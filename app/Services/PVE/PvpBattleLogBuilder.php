<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Models\CharactersOutfitsModel;
use App\Models\CharactersWeaponsModel;
use App\Models\MapModel;
use App\Models\OutfitModel;
use App\Models\WeaponModel;

/**
 * Asana «Расширение логирования PVP боёв» (Фаза 1) — сборка обогащённого log_data (v2).
 *
 * Старый log_data (v1) хранил статы игроков ТОЛЬКО на начало боя + rounds + outcome. v2 добавляет:
 *  - health_before / health_after (статы на начало И конец боя);
 *  - weapon (name+rarity) и armor (список name+rarity) — экипировка;
 *  - coords (cell/x/y) — координаты боя;
 *  - biome.
 *
 * Backward-compatible: старые бои остаются v1 (без новых полей); веб-вьюха покажет «—».
 * health_after берётся из fightResult winner/loser по id (для normal); exhausted → null.
 */
final class PvpBattleLogBuilder
{
    private CharactersWeaponsModel $charWeapons;
    private WeaponModel $weapons;
    private CharactersOutfitsModel $charOutfits;
    private OutfitModel $outfits;
    private MapModel $map;

    public function __construct(
        ?CharactersWeaponsModel $charWeapons = null,
        ?WeaponModel $weapons = null,
        ?CharactersOutfitsModel $charOutfits = null,
        ?OutfitModel $outfits = null,
        ?MapModel $map = null
    ) {
        $this->charWeapons = $charWeapons ?? new CharactersWeaponsModel();
        $this->weapons     = $weapons ?? new WeaponModel();
        $this->charOutfits = $charOutfits ?? new CharactersOutfitsModel();
        $this->outfits     = $outfits ?? new OutfitModel();
        $this->map         = $map ?? new MapModel();
    }

    /**
     * @param array<string,mixed>|\App\Entities\CharacterEntity $attacker  атакующий (статы на начало)
     * @param array<string,mixed>|\App\Entities\CharacterEntity $defender  защитник (статы на начало)
     * @param array<string,mixed> $fightResult  результат simulateFight (winner/loser/roundLogs/type)
     * @return array<string,mixed> log_data v2
     *
     * Принимает CharacterEntity (ArrayAccess) ИЛИ массив — доступ через `$p['key']` работает для обоих.
     * НЕ кастить Entity к (array) — у CI4-Entity это ломает ключи (приватный $attributes).
     */
    public function build(array|\App\Entities\CharacterEntity $attacker, array|\App\Entities\CharacterEntity $defender, array $fightResult, ?string $biomeName): array
    {
        $winnerId = $this->idOf($fightResult['winner'] ?? null);
        $loserId  = $this->idOf($fightResult['loser'] ?? null);

        return [
            'version'    => 2,
            'biome'      => $biomeName,
            'characters' => [
                'attacker' => $this->playerBlock($attacker, $fightResult),
                'defender' => $this->playerBlock($defender, $fightResult),
            ],
            'rounds'     => is_array($fightResult['roundLogs'] ?? null) ? $fightResult['roundLogs'] : [],
            'outcome'    => [
                'type'     => is_string($fightResult['type'] ?? null) ? $fightResult['type'] : null,
                'winnerId' => $winnerId,
                'loserId'  => $loserId,
            ],
        ];
    }

    /**
     * @param array<string,mixed>|\App\Entities\CharacterEntity $p
     * @param array<string,mixed> $fightResult
     * @return array<string,mixed>
     */
    private function playerBlock(array|\App\Entities\CharacterEntity $p, array $fightResult): array
    {
        $id = is_numeric($p['id'] ?? null) ? (int) $p['id'] : 0;

        $factionRaw = $p['faction'] ?? null;
        $faction    = is_array($factionRaw) ? ($factionRaw['name'] ?? null) : $factionRaw;

        return [
            'id'            => $id,
            'name'          => is_scalar($p['name'] ?? null) ? (string) $p['name'] : '',
            'level'         => is_numeric($p['level'] ?? null) ? (int) $p['level'] : 0,
            'faction'       => is_scalar($faction) ? (string) $faction : null,
            'strength'      => $this->num($p['strength'] ?? null),
            'agility'       => $this->num($p['agility'] ?? null),
            'intellect'     => $this->num($p['intellect'] ?? null),
            'health_before' => $this->num($p['health'] ?? null),
            'health_after'  => $this->postHealth($id, $fightResult),
            'coords'        => $this->coords($p),
            'weapon'        => $this->weaponOf($id),
            'armor'         => $this->armorOf($id),
        ];
    }

    /**
     * health на конец боя: из fightResult winner/loser по id. null если не определено (exhausted).
     *
     * @param array<string,mixed> $fightResult
     */
    private function postHealth(int $id, array $fightResult): ?float
    {
        foreach (['winner', 'loser'] as $key) {
            $side = $fightResult[$key] ?? null;
            if (is_array($side) && (is_numeric($side['id'] ?? null) ? (int) $side['id'] : -1) === $id) {
                return $this->num($side['health'] ?? null);
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed>|\App\Entities\CharacterEntity $p
     * @return array{cell:int, x:int, y:int}|null
     */
    private function coords(array|\App\Entities\CharacterEntity $p): ?array
    {
        $cell = is_numeric($p['cell_number'] ?? null) ? (int) $p['cell_number'] : 0;
        if ($cell <= 0) {
            return null;
        }
        try {
            $row = $this->map->where('cell_number', $cell)->first();
        } catch (\Throwable $e) {
            return null; // координаты — деталь; не роняем лог боя
        }
        if (! is_array($row)) {
            return null;
        }

        return [
            'cell' => $cell,
            'x'    => is_numeric($row['coordinate_x'] ?? null) ? (int) $row['coordinate_x'] : 0,
            'y'    => is_numeric($row['coordinate_y'] ?? null) ? (int) $row['coordinate_y'] : 0,
        ];
    }

    /**
     * @return array{name:string, rarity:int}|null
     */
    private function weaponOf(int $characterId): ?array
    {
        if ($characterId <= 0) {
            return null;
        }
        try {
            $row = $this->charWeapons->where('character_id', $characterId)->where('equipped', 1)->first();
            if (! is_array($row) || ! is_numeric($row['weapon_id'] ?? null)) {
                return null;
            }
            $weapon = $this->weapons->find((int) $row['weapon_id']);
        } catch (\Throwable $e) {
            return null; // экипировка — деталь; не роняем лог боя
        }
        if (! is_array($weapon)) {
            return null;
        }

        return [
            'name'   => is_scalar($weapon['name'] ?? null) ? (string) $weapon['name'] : '?',
            'rarity' => is_numeric($weapon['rarity'] ?? null) ? (int) $weapon['rarity'] : 0,
        ];
    }

    /**
     * Список надетой брони (name+rarity). Пустой массив если нет.
     *
     * @return list<array{name:string, rarity:int}>
     */
    private function armorOf(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }
        try {
            $equipped = $this->charOutfits->where('character_id', $characterId)->where('equipped', 1)->findAll();
            if ($equipped === []) {
                return [];
            }
            $ids = [];
            foreach ($equipped as $eq) {
                if (is_array($eq) && is_numeric($eq['outfit_id'] ?? null)) {
                    $ids[] = (int) $eq['outfit_id'];
                }
            }
            if ($ids === []) {
                return [];
            }
            $defs = $this->outfits->whereIn('id', $ids)->findAll();
        } catch (\Throwable $e) {
            return []; // экипировка — деталь; не роняем лог боя
        }
        $out = [];
        foreach ($defs as $def) {
            if (! is_array($def)) {
                continue;
            }
            $out[] = [
                'name'   => is_scalar($def['name'] ?? null) ? (string) $def['name'] : '?',
                'rarity' => is_numeric($def['rarity'] ?? null) ? (int) $def['rarity'] : 0,
            ];
        }

        return $out;
    }

    /** @param mixed $side */
    private function idOf($side): ?int
    {
        if (is_array($side) && is_numeric($side['id'] ?? null)) {
            return (int) $side['id'];
        }

        return null;
    }

    /** @param mixed $v */
    private function num($v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }
}

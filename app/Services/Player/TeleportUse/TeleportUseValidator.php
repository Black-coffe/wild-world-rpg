<?php

namespace App\Services\Player\TeleportUse;

use App\Entities\CharacterEntity;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\MapModel;
use App\Services\Player\TeleportCostService;
use DateTime;

/**
 * v0.51.63 (TeleportUseAction decomp Step 1) — extract повну validation
 * для 4 teleport variants (Backpack/Gold/Portable/Experience).
 *
 * Public API (per variant):
 *   validateBackpack(character)   : array{ok:bool, error?:string, context?:array}
 *   validateGold(character)       : array{ok:bool, error?:string, context?:array}
 *   validatePortable(character)   : array{ok:bool, error?:string, context?:array}
 *   validateExperience(character) : array{ok:bool, error?:string, context?:array}
 *
 * Context payloads (when ok=true):
 *   backpack:   {backpackItem, backpackLog, claimedCell, mapRow, customData}
 *   gold:       {charRow, claimedCell, mapRow, cost}
 *   portable:   {portableItem, portableLog, claimedCell, mapRow}
 *   experience: {charRow, claimedCell, mapRow}
 *
 * Behavior preservation: 1:1 з legacy TeleportUseAction (lines 77-435 у v0.51.62).
 * Помилки-тексти ідентичні. Quirks збережено:
 *   - Portable: всі fail-paths повертають той самий generic error
 *     («У тебя нет портативного телепорта») незалежно чи поломка у
 *     item lookup, log lookup, claimed cell або map row.
 *   - Experience: fail-paths повертають єдиний generic error
 *     («У тебя недостаточно опыта»).
 */
class TeleportUseValidator
{
    public const BACKPACK_COOLDOWN_MIN = 60;
    public const EXPERIENCE_COST       = 1.0;
    public const EXPERIENCE_THRESHOLD  = 1.01;

    private CraftedItemsModel $craftedItemModel;
    private CraftedItemsLogModel $craftedItemLogModel;
    private CharacterModel $characterModel;
    private ClaimedCellModel $claimedCellModel;
    private MapModel $mapModel;
    private TeleportCostService $teleportCostService;

    public function __construct(
        ?CraftedItemsModel $craftedItemModel = null,
        ?CraftedItemsLogModel $craftedItemLogModel = null,
        ?CharacterModel $characterModel = null,
        ?ClaimedCellModel $claimedCellModel = null,
        ?MapModel $mapModel = null,
        ?TeleportCostService $teleportCostService = null
    ) {
        $this->craftedItemModel    = $craftedItemModel    ?? new CraftedItemsModel();
        $this->craftedItemLogModel = $craftedItemLogModel ?? new CraftedItemsLogModel();
        $this->characterModel      = $characterModel      ?? new CharacterModel();
        $this->claimedCellModel    = $claimedCellModel    ?? new ClaimedCellModel();
        $this->mapModel            = $mapModel            ?? new MapModel();
        $this->teleportCostService = $teleportCostService ?? new TeleportCostService();
    }

    /**
     * @param array<string,mixed>|CharacterEntity $character
     * @return array<string,mixed>
     */
    public function validateBackpack(array|CharacterEntity $character): array
    {
        $backpackItem = $this->craftedItemModel->where('name_eng', 'TeleportBackpack')->first();
        if (!$backpackItem) {
            return ['ok' => false, 'error' => "Ошибка: предмет 'TeleportBackpack' не найден в базе."];
        }

        $backpackLog = $this->craftedItemLogModel
            ->where('crafted_item_id', $backpackItem['id'])
            ->where('character_id', $character['id'])
            ->first();
        if (!$backpackLog) {
            return ['ok' => false, 'error' => "У тебя нет рюкзака для телепорта!"];
        }

        if (((int) $backpackLog['quantity'] < 1) || ((int) $backpackLog['durability_count'] < 1)) {
            return ['ok' => false, 'error' => "Рюкзак больше не пригоден для телепортации (нет зарядов)."];
        }

        $customData = [];
        if (!empty($backpackLog['custom_setting'])) {
            $decoded = json_decode((string) $backpackLog['custom_setting'], true);
            if (is_array($decoded)) {
                $customData = $decoded;
            }
        }

        $lastUsedAtStr = $customData['lastUsedAt'] ?? null;
        if ($lastUsedAtStr) {
            $lastUsedTime = new DateTime((string) $lastUsedAtStr);
            $now          = new DateTime();
            $diff         = $now->diff($lastUsedTime);
            $minutesPassed = $diff->days * 24 * 60 + $diff->h * 60 + $diff->i;

            if ($minutesPassed < self::BACKPACK_COOLDOWN_MIN) {
                $remaining = self::BACKPACK_COOLDOWN_MIN - $minutesPassed;
                return [
                    'ok'    => false,
                    'error' => "Ты уже использовал рюкзак! Повторный телепорт будет доступен через ~{$remaining} мин.",
                ];
            }
        }

        $location = $this->findBaseLocation((int) $character['id']);
        if (!$location['ok']) {
            $err = $location['error'] ?? "Ошибка базы.";
            // Backpack: legacy text "У тебя нет базы, куда телепортироваться!" замість generic.
            if ($err === 'no_claimed_cell') {
                return ['ok' => false, 'error' => "У тебя нет базы, куда телепортироваться!"];
            }
            if ($err === 'no_map_row') {
                return ['ok' => false, 'error' => "Ошибка: не найдена ячейка базы на карте."];
            }
            return ['ok' => false, 'error' => $err];
        }

        return [
            'ok'      => true,
            'context' => [
                'backpackItem' => $backpackItem,
                'backpackLog'  => $backpackLog,
                'claimedCell'  => $location['claimedCell'],
                'mapRow'       => $location['mapRow'],
                'customData'   => $customData,
            ],
        ];
    }

    /**
     * @param array<string,mixed>|CharacterEntity $character
     * @return array<string,mixed>
     */
    public function validateGold(array|CharacterEntity $character): array
    {
        $charRow = $this->characterModel->find((int) $character['id']);
        if (!$charRow) {
            return ['ok' => false, 'error' => "Ошибка! Персонаж не найден."];
        }

        $cost = $this->teleportCostService->calculateTeleportCost((int) $charRow['level'], (int) $charRow['id']);
        if ((int) $charRow['gold'] < $cost) {
            return [
                'ok'    => false,
                'error' => "Недостаточно золота! Нужно {$cost}, а у тебя всего {$charRow['gold']}.",
            ];
        }

        $location = $this->findBaseLocation((int) $charRow['id']);
        if (!$location['ok']) {
            $err = $location['error'] ?? "Ошибка базы.";
            if ($err === 'no_claimed_cell') {
                return ['ok' => false, 'error' => "У тебя нет базы для телепорта!"];
            }
            if ($err === 'no_map_row') {
                return ['ok' => false, 'error' => "Ошибка: не найдена ячейка базы на карте."];
            }
            return ['ok' => false, 'error' => $err];
        }

        return [
            'ok'      => true,
            'context' => [
                'charRow'     => $charRow,
                'claimedCell' => $location['claimedCell'],
                'mapRow'      => $location['mapRow'],
                'cost'        => $cost,
            ],
        ];
    }

    /**
     * Portable: legacy-quirk preservation — всі fail-paths повертають той самий
     * generic error "У тебя нет портативного телепорта."
     *
     * @param array<string,mixed>|CharacterEntity $character
     * @return array<string,mixed>
     */
    public function validatePortable(array|CharacterEntity $character): array
    {
        $portableItem = $this->craftedItemModel->where('name_eng', 'PortableTeleport')->first();
        if (!$portableItem) {
            return ['ok' => false, 'error' => "У тебя нет портативного телепорта."];
        }

        $portableLog = $this->craftedItemLogModel
            ->where('crafted_item_id', $portableItem['id'])
            ->where('character_id', $character['id'])
            ->first();
        if (!$portableLog) {
            return ['ok' => false, 'error' => "У тебя нет портативного телепорта."];
        }

        // 2026-08-06: пока предмет был недостижим (рецепта не существовало), проверки
        // зарядов не было вовсе — пустое устройство сработало бы «бесплатно» и исчезло.
        // Теперь его крафтят, поэтому заряды проверяем явно.
        $portableQty     = (is_array($portableLog) && is_numeric($portableLog['quantity'] ?? null))
            ? (int) $portableLog['quantity'] : 0;
        $portableCharges = (is_array($portableLog) && is_numeric($portableLog['durability_count'] ?? null))
            ? (int) $portableLog['durability_count'] : 0;
        if ($portableQty < 1 || $portableCharges < 1) {
            return ['ok' => false, 'error' => "Портативный телепорт разряжен — заряды кончились."];
        }

        $location = $this->findBaseLocation((int) $character['id']);
        if (!$location['ok']) {
            return ['ok' => false, 'error' => "У тебя нет портативного телепорта."];
        }

        return [
            'ok'      => true,
            'context' => [
                'portableItem' => $portableItem,
                'portableLog'  => $portableLog,
                'claimedCell'  => $location['claimedCell'],
                'mapRow'       => $location['mapRow'],
            ],
        ];
    }

    /**
     * Experience: legacy-quirk preservation — всі fail-paths повертають той самий
     * generic error "У тебя недостаточно опыта для телепортации."
     *
     * @param array<string,mixed>|CharacterEntity $character
     * @return array<string,mixed>
     */
    public function validateExperience(array|CharacterEntity $character): array
    {
        $charRow = $this->characterModel->find((int) $character['id']);
        if (!$charRow || (float) $charRow['experience'] <= self::EXPERIENCE_THRESHOLD) {
            return ['ok' => false, 'error' => "У тебя недостаточно опыта для телепортации."];
        }

        $location = $this->findBaseLocation((int) $charRow['id']);
        if (!$location['ok']) {
            return ['ok' => false, 'error' => "У тебя недостаточно опыта для телепортации."];
        }

        return [
            'ok'      => true,
            'context' => [
                'charRow'     => $charRow,
                'claimedCell' => $location['claimedCell'],
                'mapRow'      => $location['mapRow'],
            ],
        ];
    }

    /**
     * Common helper: find claimed_cell + map_row for given character.
     * Returns discriminated tagged error для caller'ів якщо не знайдено.
     *
     * @return array{ok:bool, error?:string, claimedCell?:array<string,mixed>, mapRow?:array<string,mixed>}
     */
    private function findBaseLocation(int $characterId): array
    {
        $claimedCell = $this->claimedCellModel->where('character_id', $characterId)->first();
        if (!$claimedCell) {
            return ['ok' => false, 'error' => 'no_claimed_cell'];
        }

        $mapRow = $this->mapModel->where('cell_number', $claimedCell['map_cell_id'])->first();
        if (!$mapRow) {
            return ['ok' => false, 'error' => 'no_map_row'];
        }

        return [
            'ok'          => true,
            'claimedCell' => $claimedCell,
            'mapRow'      => $mapRow,
        ];
    }
}

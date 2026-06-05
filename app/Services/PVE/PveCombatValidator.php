<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Models\NpcSpawnModel;
use App\Models\NpcModel;

/**
 * v0.51.87 (PvEService decomp Step 2) — extract NPC validation chain
 * + npc_spawns/npcs merge у dedicated validator.
 *
 * Виконує 3 перевірки перед боєм:
 *   1. NPC spawn існує
 *   2. Гравець + NPC у одній клітинці
 *   3. NPC record існує у npcs catalog
 *
 * При успіху — повертає merged data (statics з npcs + dynamics з npc_spawns,
 * health з current_health, name з npc_name_ru з fallback).
 */
final class PveCombatValidator
{
    public function __construct(
        private NpcSpawnModel $npcSpawnModel,
        private NpcModel $npcModel
    ) {
    }

    /**
     * @param array<string, mixed>|\App\Entities\CharacterEntity $playerData characters row/Entity
     * @param array<string, mixed> $npcDataInput  NPC dispatch info — лише `id` (npc_spawn id) використовується
     *
     * @return array{ok: true, npcData: array<string, mixed>}|array{ok: false, response: array<string, string>}
     */
    public function validateAndLoadNpc(array|\App\Entities\CharacterEntity $playerData, array $npcDataInput): array
    {
        $spawnId = $npcDataInput['id'] ?? null;

        // 1. NPC spawn у БД?
        $npcSpawnData = $this->npcSpawnModel->find($spawnId);
        if (!$npcSpawnData) {
            log_message('error', "⛔ Ошибка: NPC спавн ID {$spawnId} не найден!");
            return ['ok' => false, 'response' => ['error' => "NPC спавн ID {$spawnId} не найден"]];
        }

        // 2. Один cell? Сравниваем как int: путь встречи передаёт CharacterEntity (cell_number — int),
        // а спавн из модели — string. Строгое !== давало ложное «разные клетки» (int !== string) и рубило
        // бой во встрече до начала → npc_kills не рос. Auto-PvE (массив, string vs string) проходил.
        $pCellRaw = $playerData['cell_number'] ?? null;
        $sCellRaw = $npcSpawnData['cell_number'] ?? null;
        if (! is_numeric($pCellRaw) || ! is_numeric($sCellRaw) || (int) $pCellRaw !== (int) $sCellRaw) {
            log_message('warning', "⚠️ Игрок ID={$playerData['id']} и NPC спавн ID={$spawnId} в разных клетках! Бой не начинается.");
            return ['ok' => false, 'response' => ['message' => "Игрок и NPC находятся в разных клетках"]];
        }

        // 3. NPC catalog запис існує?
        $npcRecord = $this->npcModel->find($npcSpawnData['npc_id']);
        if (!$npcRecord) {
            log_message('error', "⛔ Ошибка: NPC ID {$npcSpawnData['npc_id']} не найден в npcs!");
            return ['ok' => false, 'response' => ['error' => "NPC ID {$npcSpawnData['npc_id']} не найден"]];
        }

        // Merge: static (npcs) + dynamic (npc_spawns)
        $merged = array_merge($npcRecord, $npcSpawnData);
        $merged['health'] = $npcSpawnData['current_health'];
        $merged['name'] = $npcRecord['npc_name_ru'] ?? 'Неизвестный враг';

        return ['ok' => true, 'npcData' => $merged];
    }
}

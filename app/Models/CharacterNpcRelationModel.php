<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * ADR-089 Фаза 2 — отношения NPC к персонажу (память реактивности).
 */
class CharacterNpcRelationModel extends Model
{
    protected $table         = 'character_npc_relations';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['character_id', 'npc_id', 'relation_score', 'interactions', 'last_interaction_at'];

    /** Текущий relation_score для (character, npc) или 0 если связи ещё нет. */
    public function scoreFor(int $characterId, int $npcId): int
    {
        $row = $this->where('character_id', $characterId)->where('npc_id', $npcId)->first();
        if (! is_array($row)) {
            return 0;
        }
        $raw = $row['relation_score'] ?? null;

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /** Атомарно изменить relation_score на $delta (upsert), вернуть новое значение. */
    public function adjust(int $characterId, int $npcId, int $delta): int
    {
        $row = $this->where('character_id', $characterId)->where('npc_id', $npcId)->first();
        $now = date('Y-m-d H:i:s');

        if (! is_array($row)) {
            $this->insert([
                'character_id'        => $characterId,
                'npc_id'              => $npcId,
                'relation_score'      => $delta,
                'interactions'        => 1,
                'last_interaction_at' => $now,
            ]);

            return $delta;
        }

        $idRaw    = $row['id'] ?? null;
        $id       = is_numeric($idRaw) ? (int) $idRaw : 0;
        $curRaw   = $row['relation_score'] ?? null;
        $cur      = is_numeric($curRaw) ? (int) $curRaw : 0;
        $intRaw   = $row['interactions'] ?? null;
        $newScore = $cur + $delta;

        $this->update($id, [
            'relation_score'      => $newScore,
            'interactions'        => (is_numeric($intRaw) ? (int) $intRaw : 0) + 1,
            'last_interaction_at' => $now,
        ]);

        return $newScore;
    }
}

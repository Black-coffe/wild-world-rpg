<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * ADR-101 Фаза 4 — кулдаун повторяемого лута охраняемых руин (per character × ruin).
 *
 * Одна строка на пару (character_id, settlement_id) с last_looted_at. WipeManifest: PLAYER_DATA.
 */
class CharacterRuinLootModel extends Model
{
    protected $table         = 'character_ruin_loot';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields  = ['character_id', 'settlement_id', 'last_looted_at'];
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    /** Время последнего лута пары (character, ruin) или null, если ещё не лутил. */
    public function lastLootedAt(int $characterId, int $settlementId): ?string
    {
        $row = $this->where('character_id', $characterId)
            ->where('settlement_id', $settlementId)
            ->first();

        $v = is_array($row) ? ($row['last_looted_at'] ?? null) : null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Upsert last_looted_at=$when для пары (character, ruin). */
    public function touch(int $characterId, int $settlementId, string $when): void
    {
        $row = $this->where('character_id', $characterId)
            ->where('settlement_id', $settlementId)
            ->first();
        $id  = is_array($row) ? ($row['id'] ?? null) : null;

        if (is_numeric($id)) {
            $this->update((int) $id, ['last_looted_at' => $when]);

            return;
        }

        $this->insert([
            'character_id'   => $characterId,
            'settlement_id'  => $settlementId,
            'last_looted_at' => $when,
        ]);
    }
}

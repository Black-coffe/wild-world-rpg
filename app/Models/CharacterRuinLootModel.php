<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
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

    /**
     * s2-conditional-writes-04 (F10, ADR-181): резерв кулдауна пары (character, ruin) одной условной
     * записью — ДО выдачи лута. Строка есть → `UPDATE … WHERE last_looted_at IS NULL OR
     * last_looted_at <= $readyBefore` + `affectedRows`; строки нет → `insertUnique` по
     * UNIQUE(character_id, settlement_id), дубль (`Refused`) = кулдаун уже взят другим запросом.
     * Обе даты — PHP-часы вызывающего (`$now`, `$readyBefore = $now − cooldown`).
     *
     * @return bool true — окно зарезервировано (last_looted_at = $now), false — кулдаун
     */
    public function reserveCooldown(int $characterId, int $settlementId, string $now, string $readyBefore): bool
    {
        $table = $this->db->prefixTable($this->table);
        $this->db->query(
            "UPDATE {$table} SET last_looted_at = ?, updated_at = ?"
            . ' WHERE character_id = ? AND settlement_id = ?'
            . ' AND (last_looted_at IS NULL OR last_looted_at <= ?)',
            [$now, $now, $characterId, $settlementId, $readyBefore]
        );
        if ($this->db->affectedRows() > 0) {
            return true;
        }

        $outcome = (new ConditionalWriteService($this->db))->insertUnique($this->table, [
            'character_id'   => $characterId,
            'settlement_id'  => $settlementId,
            'last_looted_at' => $now,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        return $outcome === WriteOutcome::Applied;
    }
}

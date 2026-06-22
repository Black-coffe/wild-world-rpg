<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Services\GameSettings\GameSettingsReaderTrait;
use Config\Database;

/**
 * WB10 (ADR-137 «Узлы») — анти-кемп LOOT-LOCK: read-side проверки лока на kill-пути.
 *
 * Накопление dwell и выставление `loot_locked_until` ведёт {@see \App\TaskHandlers\NPC\AntiCampDwellHandler}
 * (крон). Этот сервис — лёгкий читатель для момента убийства узла:
 *   - {@see lockedSubset()} — кто из вкладчиков «Облавы» залочен у этой точки → исключаются из лотереи
 *     soulbound-трофея (`BossLootService::distributeLoot`); ЗОЛОТО по вкладу не трогаем (мотив кемпа —
 *     трофей, не золото; ADR-137 §0.3).
 *   - {@see isLootLocked()} — залочен ли killer → не записывается как `last_killer_character_id`
 *     (не killer для анонса WB11) + ему показывается «☢️ Гарь узла» в экране победы.
 *
 * Лок активен, пока строка boss_camp_state жива И `loot_locked_until > NOW`. Уход кемпера из радиуса
 * удаляет строку (сброс), оффлайн его dwell не копит. Чистый DB-read, без mt_rand → fixture-fence цел.
 */
class AntiCampService
{
    use GameSettingsReaderTrait;

    /** @var \CodeIgniter\Database\BaseConnection<object, object> */
    private $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /**
     * Залочен ли игрок у конкретного узла (активный loot-lock).
     */
    public function isLootLocked(int $charId, int $pointId): bool
    {
        if ($charId <= 0 || $pointId <= 0) {
            return false;
        }

        return $this->db->table('boss_camp_state')
            ->where('character_id', $charId)
            ->where('boss_point_id', $pointId)
            ->where('loot_locked_until >', date('Y-m-d H:i:s'))
            ->countAllResults() > 0;
    }

    /**
     * Подмножество переданных charId, залоченных у этой точки (для исключения из дележа трофея).
     *
     * @param list<int> $charIds
     * @return list<int>
     */
    public function lockedSubset(array $charIds, int $pointId): array
    {
        $charIds = array_values(array_unique(array_filter($charIds, static fn (int $c): bool => $c > 0)));
        if ($charIds === [] || $pointId <= 0) {
            return [];
        }

        $res = $this->db->table('boss_camp_state')
            ->select('character_id')
            ->where('boss_point_id', $pointId)
            ->whereIn('character_id', $charIds)
            ->where('loot_locked_until >', date('Y-m-d H:i:s'))
            ->get();
        $rows = $res === false ? [] : $res->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $cid = is_numeric($r['character_id'] ?? null) ? (int) $r['character_id'] : 0;
            if ($cid > 0) {
                $out[] = $cid;
            }
        }

        return $out;
    }
}

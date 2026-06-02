<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * ADR-089 Фаза 1 — реплики NPC (data-driven контент).
 * Строки по (npc_id, dialogue_type): greeting/talk/ask/trade/rob_success/rob_fail.
 */
class NpcDialogueModel extends Model
{
    protected $table         = 'npc_dialogues';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['npc_id', 'dialogue_type', 'text_ru', 'weight'];

    /**
     * Случайная (по весу — упрощённо: random row) реплика для (npc_id, type) или null.
     */
    public function lineFor(int $npcId, string $type): ?string
    {
        $rows = $this->where('npc_id', $npcId)->where('dialogue_type', $type)->findAll();
        if ($rows === []) {
            return null;
        }
        $pick = $rows[array_rand($rows)];
        if (! is_array($pick)) {
            return null;
        }
        $text = $pick['text_ru'] ?? null;

        return is_string($text) && $text !== '' ? $text : null;
    }
}

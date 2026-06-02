<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * ADR-089 Фаза 5+ — узлы ветвящихся диалогов именных NPC.
 */
class NpcDialogueNodeModel extends Model
{
    protected $table         = 'npc_dialogue_nodes';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['npc_id', 'node_key', 'text_ru', 'options'];

    /**
     * Узел (npc_id, node_key) или null.
     *
     * @return array<int|string,mixed>|null
     */
    public function nodeFor(int $npcId, string $nodeKey): ?array
    {
        $row = $this->where('npc_id', $npcId)->where('node_key', $nodeKey)->first();

        return is_array($row) ? $row : null;
    }

    /** Есть ли у NPC дерево диалога (узел root). */
    public function hasTree(int $npcId): bool
    {
        return $this->where('npc_id', $npcId)->where('node_key', 'root')->first() !== null;
    }
}

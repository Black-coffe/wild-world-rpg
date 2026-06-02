<?php

declare(strict_types=1);

namespace App\Services\NPC;

use App\Models\NpcDialogueNodeModel;

/**
 * ADR-089 Фаза 5+ — обход ветвящихся диалогов именных NPC.
 *
 * Узлы (npc_dialogue_nodes): (npc_id, node_key) → text + options [{label,next,rel}].
 * Старт — node_key='root'. Выбор опции применяет rel (через NpcRelationService) и ведёт
 * к next-узлу ('' = конец беседы). Дерево есть только у именных NPC → естественно dormant.
 */
final class NpcDialogueTreeService
{
    private NpcDialogueNodeModel $nodes;

    public function __construct(?NpcDialogueNodeModel $nodes = null)
    {
        $this->nodes = $nodes ?? new NpcDialogueNodeModel();
    }

    /** Есть ли у NPC дерево диалога. */
    public function hasTree(int $npcId): bool
    {
        return $this->nodes->hasTree($npcId);
    }

    /**
     * Узел: текст + декодированные опции. null если узла нет.
     *
     * Опция: {label, next, rel, gate?}. ADR-089 Phase 6:
     *  - `next` = node_key → переход вглубь; `act-quest|act-rob|act-fight|act-trade|act-leave|act-provoke` → исход.
     *  - `gate` (опц.) = минимальная ступень standing для показа опции (stranger|acquaintance|ally).
     *
     * @return array{text:string, options:list<array{label:string, next:string, rel:int, gate:string}>}|null
     */
    public function node(int $npcId, string $nodeKey): ?array
    {
        $row = $this->nodes->nodeFor($npcId, $nodeKey);
        if ($row === null) {
            return null;
        }
        $text = is_string($row['text_ru'] ?? null) ? $row['text_ru'] : '';

        $options = [];
        $raw     = $row['options'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $opt) {
                    if (! is_array($opt)) {
                        continue;
                    }
                    $label = is_string($opt['label'] ?? null) ? $opt['label'] : '';
                    $next  = is_string($opt['next'] ?? null) ? $opt['next'] : '';
                    $relR  = $opt['rel'] ?? 0;
                    $rel   = is_numeric($relR) ? (int) $relR : 0;
                    $gate  = is_string($opt['gate'] ?? null) ? $opt['gate'] : '';
                    if ($label !== '') {
                        $options[] = ['label' => $label, 'next' => $next, 'rel' => $rel, 'gate' => $gate];
                    }
                }
            }
        }

        return ['text' => $text, 'options' => $options];
    }
}

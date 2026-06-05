<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Ph6 — встраивание опции act-quest в деревья 2 новых именных квестгиверов.
 *
 * При rich_dialogue_enabled=ON встреча показывает ДЕРЕВО (не легаси-меню с кнопкой
 * «📜 Задание»), поэтому квест должен быть достижим через опцию next='act-quest' внутри
 * дерева (как у Варги узел «trial»). Добавляем по одной act-quest-опции в тематический узел:
 *   • Полынь → узел «barter» (она просит сырьё) → «Дай задание — принесу мха».
 *   • Карн   → узел «jointest» (описание испытания) → «Беру испытание. Принесу счёт».
 * NpcDialogueAction::dispatchOutcome('quest') → acceptOfferedQuest (гейты уровня/не-начат
 * внутри; ok=false → мягкое «нет работы для тебя»). Idempotent (skip если act-quest уже есть).
 *
 * WipeManifest: npc_dialogue_nodes = KEEP. Новых таблиц нет → coverage не затронут.
 */
class Adr089Ph6NamedNpcQuestDialogue extends Migration
{
    public function up(): void
    {
        $additions = [
            [
                'npc'  => 'HerbalistPolyn',
                'node' => 'barter',
                'opt'  => ['label' => '📜 Дай задание — принесу мха с чистых мест.', 'next' => 'act-quest', 'rel' => 1, 'gate' => ''],
            ],
            [
                'npc'  => 'RaiderWarlordKarn',
                'node' => 'jointest',
                'opt'  => ['label' => '🩸 Беру испытание. Принесу счёт.', 'next' => 'act-quest', 'rel' => 2, 'gate' => ''],
            ],
        ];

        foreach ($additions as $a) {
            $npc = $this->db->table('npcs')->where('npc_name_en', $a['npc'])->get()->getRowArray();
            if (empty($npc)) {
                continue;
            }
            $node = $this->db->table('npc_dialogue_nodes')
                ->where('npc_id', (int) $npc['id'])->where('node_key', $a['node'])->get()->getRowArray();
            if (empty($node)) {
                continue;
            }
            $opts = json_decode((string) ($node['options'] ?? '[]'), true);
            if (! is_array($opts)) {
                $opts = [];
            }
            // идемпотентность: пропускаем, если act-quest уже встроен
            foreach ($opts as $o) {
                if (is_array($o) && ($o['next'] ?? '') === 'act-quest') {
                    continue 2;
                }
            }
            // вставляем перед последней опцией (обычно «↩️ Назад»/«Уйти»)
            array_splice($opts, max(0, count($opts) - 1), 0, [$a['opt']]);
            $this->db->table('npc_dialogue_nodes')
                ->where('id', (int) $node['id'])
                ->update(['options' => json_encode($opts, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        foreach (['HerbalistPolyn' => 'barter', 'RaiderWarlordKarn' => 'jointest'] as $npcEn => $nodeKey) {
            $npc = $this->db->table('npcs')->where('npc_name_en', $npcEn)->get()->getRowArray();
            if (empty($npc)) {
                continue;
            }
            $node = $this->db->table('npc_dialogue_nodes')
                ->where('npc_id', (int) $npc['id'])->where('node_key', $nodeKey)->get()->getRowArray();
            if (empty($node)) {
                continue;
            }
            $opts = json_decode((string) ($node['options'] ?? '[]'), true);
            if (! is_array($opts)) {
                continue;
            }
            $opts = array_values(array_filter($opts, static fn ($o) => ! (is_array($o) && ($o['next'] ?? '') === 'act-quest')));
            $this->db->table('npc_dialogue_nodes')
                ->where('id', (int) $node['id'])
                ->update(['options' => json_encode($opts, JSON_UNESCAPED_UNICODE)]);
        }
    }
}

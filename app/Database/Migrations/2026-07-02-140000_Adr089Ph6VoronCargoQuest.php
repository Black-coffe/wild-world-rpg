<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Ph6 + ADR-088 — опц. квест для Ворона (торговец-кочевник): курьер/доставка груза.
 *
 * Ворон, NomadTraderVoron → «Груз для каравана» (NomadTraderVoronCargo): collect_resource
 * Ironstone(железная руда) ×60, min_level 8, 2200 золота. Курьер-тема в рамках системы:
 * прямого «доставь-в-точку» objective нет, а NPC-выдаваемый квест обязан быть startable-root
 * типом (collect_resource/building_level/level_milestone/npc_kills) → доставка груза ТОРГОВЦУ
 * (он перепродаёт Инженерам, игрок получает долю) ложится на collect_resource. Ironstone — по
 * лору Ворона («Инженеры — мой лучший поставщик/покупатель»).
 *
 * faction_id=NULL (нейтрал). Доступ через опцию act-quest в узле «stock» дерева (где он
 * говорит об источниках товара) + npcs.offers_quest_title_en. Гейты questgiver/extended ON.
 * Idempotent (квест по title_en; act-quest skip если уже есть).
 *
 * WipeManifest: quests/npcs/npc_dialogue_nodes = KEEP. Новых таблиц нет → coverage не затронут.
 */
class Adr089Ph6VoronCargoQuest extends Migration
{
    public function up(): void
    {
        // 1. Квест
        $quest = [
            'title_ru'           => 'Груз для каравана',
            'title_en'           => 'NomadTraderVoronCargo',
            'description'        => 'Ворон собирается в новую ходку, но без товара дорога — пустая трата сапог. «Привези мне шестьдесят кусков железной руды. Инженеры за неё платят звонким золотом, а я даю тебе долю. Честная сделка: твои ноги — мой барыш.»',
            'status'             => 'active',
            'min_level'          => 8,
            'reward_type'        => 'gold',
            'reward'             => 2200,
            'prerequisite_quest' => null,
            'objective_type'     => 'collect_resource',
            'objective_target'   => 'Ironstone',
            'objective_qty'      => 60,
            'branch_group'       => null,
            'branch_label'       => null,
            'faction_id'         => null,
        ];
        if (empty($this->db->table('quests')->where('title_en', $quest['title_en'])->get()->getRowArray())) {
            $this->db->table('quests')->insert($quest);
        }

        // 2. Привязка NPC → квест
        $this->db->table('npcs')->where('npc_name_en', 'NomadTraderVoron')->update(['offers_quest_title_en' => 'NomadTraderVoronCargo']);

        // 3. act-quest в узел «stock» дерева Ворона (идемпотентно)
        $npc = $this->db->table('npcs')->where('npc_name_en', 'NomadTraderVoron')->get()->getRowArray();
        if (! empty($npc)) {
            $node = $this->db->table('npc_dialogue_nodes')
                ->where('npc_id', (int) $npc['id'])->where('node_key', 'stock')->get()->getRowArray();
            if (! empty($node)) {
                $opts = json_decode((string) ($node['options'] ?? '[]'), true);
                if (! is_array($opts)) {
                    $opts = [];
                }
                $hasQuest = false;
                foreach ($opts as $o) {
                    if (is_array($o) && ($o['next'] ?? '') === 'act-quest') {
                        $hasQuest = true;
                    }
                }
                if (! $hasQuest) {
                    array_splice($opts, max(0, count($opts) - 1), 0, [[
                        'label' => '📦 Привезу тебе груз для ходки. Берусь.',
                        'next'  => 'act-quest',
                        'rel'   => 1,
                        'gate'  => '',
                    ]]);
                    $this->db->table('npc_dialogue_nodes')
                        ->where('id', (int) $node['id'])
                        ->update(['options' => json_encode($opts, JSON_UNESCAPED_UNICODE)]);
                }
            }
        }
    }

    public function down(): void
    {
        $this->db->table('quests')->where('title_en', 'NomadTraderVoronCargo')->delete();
        $this->db->table('npcs')->where('npc_name_en', 'NomadTraderVoron')->update(['offers_quest_title_en' => null]);
        $npc = $this->db->table('npcs')->where('npc_name_en', 'NomadTraderVoron')->get()->getRowArray();
        if (! empty($npc)) {
            $node = $this->db->table('npc_dialogue_nodes')
                ->where('npc_id', (int) $npc['id'])->where('node_key', 'stock')->get()->getRowArray();
            if (! empty($node)) {
                $opts = json_decode((string) ($node['options'] ?? '[]'), true);
                if (is_array($opts)) {
                    $opts = array_values(array_filter($opts, static fn ($o) => ! (is_array($o) && ($o['next'] ?? '') === 'act-quest')));
                    $this->db->table('npc_dialogue_nodes')->where('id', (int) $node['id'])->update(['options' => json_encode($opts, JSON_UNESCAPED_UNICODE)]);
                }
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Ph6 + ADR-088 — опц. квесты для 2 новых именных NPC-квестгиверов.
 *
 * - Полынь, знахарка (HerbalistPolyn) → «Сбор целебного мха» (HerbalistPolynHerbs):
 *   collect_resource Mosses(Мхи) ×30, min_level 10, 2400 золота. Мох — её канонический
 *   материал («вытягивает гниль»).
 * - Карн, вожак Песчаных Волков (RaiderWarlordKarn) → «Испытание стаи»
 *   (RaiderWarlordKarnTrial): npc_kills ×60, min_level 14, 5500 золота. «Докажи зубы».
 *
 * Оба faction_id=NULL (нейтралы) → видны без выбора фракции. Гейты выдачи: quests.
 * extended_enabled + npc.questgiver_enabled (оба ON на проде). Доступны через act-quest
 * в дереве диалога (миграция Adr089Ph6NamedNpcQuestDialogue). Привязка через
 * npcs.offers_quest_title_en. Idempotent (по title_en).
 *
 * WipeManifest: quests/npcs = KEEP (контент). Новых таблиц нет → coverage не затронут.
 */
class Adr089Ph6NamedNpcQuests extends Migration
{
    public function up(): void
    {
        $quests = [
            [
                'title_ru'           => 'Сбор целебного мха',
                'title_en'           => 'HerbalistPolynHerbs',
                'description'        => 'Полынь латает раны пустоши без устали, но болотный мох, что вытягивает гниль, кончается быстрее, чем хотелось бы. Собери 30 единиц мха с чистых мест — и в её землянке снова будет чем спасать.',
                'status'             => 'active',
                'min_level'          => 10,
                'reward_type'        => 'gold',
                'reward'             => 2400,
                'prerequisite_quest' => null,
                'objective_type'     => 'collect_resource',
                'objective_target'   => 'Mosses',
                'objective_qty'      => 30,
                'branch_group'       => null,
                'branch_label'       => null,
                'faction_id'         => null,
            ],
            [
                'title_ru'           => 'Испытание стаи',
                'title_en'           => 'RaiderWarlordKarnTrial',
                'description'        => 'Карн не верит словам — только клыкам. «Принеси мне не байки, а счёт: шестьдесят голов, что легли от твоей руки. Тогда и поговорим, есть ли в тебе волк.» Докажи, что выживаешь не милостью пустоши, а силой.',
                'status'             => 'active',
                'min_level'          => 14,
                'reward_type'        => 'gold',
                'reward'             => 5500,
                'prerequisite_quest' => null,
                'objective_type'     => 'npc_kills',
                'objective_target'   => null,
                'objective_qty'      => 60,
                'branch_group'       => null,
                'branch_label'       => null,
                'faction_id'         => null,
            ],
        ];

        foreach ($quests as $q) {
            $exists = $this->db->table('quests')->where('title_en', $q['title_en'])->get()->getRowArray();
            if (empty($exists)) {
                $this->db->table('quests')->insert($q);
            }
        }

        // Привязка именных NPC к их квестам (query-builder минует allowedFields — ок).
        $map = [
            'HerbalistPolyn'    => 'HerbalistPolynHerbs',
            'RaiderWarlordKarn' => 'RaiderWarlordKarnTrial',
        ];
        foreach ($map as $npcEn => $questEn) {
            $this->db->table('npcs')->where('npc_name_en', $npcEn)->update(['offers_quest_title_en' => $questEn]);
        }
    }

    public function down(): void
    {
        $this->db->table('quests')->whereIn('title_en', ['HerbalistPolynHerbs', 'RaiderWarlordKarnTrial'])->delete();
        $this->db->table('npcs')->whereIn('npc_name_en', ['HerbalistPolyn', 'RaiderWarlordKarn'])->update(['offers_quest_title_en' => null]);
    }
}

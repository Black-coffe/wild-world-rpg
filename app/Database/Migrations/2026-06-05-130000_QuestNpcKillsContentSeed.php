<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-088 Фаза 2 — контент категории «число драк с NPC» (objective_type=npc_kills).
 *
 * STANDALONE startable-«корни» (без prerequisite), невидимы/не прогрессят пока
 * killswitch `quests.extended_enabled`=OFF. Прогресс — монотонный characters.npc_kills
 * (инкремент в PvEService::attack при победе над NPC). Idempotent.
 */
class QuestNpcKillsContentSeed extends Migration
{
    public function up(): void
    {
        $quests = [
            $this->q('NpcHunterNovice', 'Первая кровь', 3, 10, 1200,
                'Пустошь кишит тварями и одичавшими роботами. Одолей 10 врагов в стычках — докажи, что умеешь не только убегать.'),
            $this->q('NpcHunterSkilled', 'Охотник пустоши', 10, 50, 3500,
                'Слабые гибнут, сильные охотятся. Победи 50 противников — твоё имя начнут запоминать у костров.'),
            $this->q('NpcHunterVeteran', 'Гроза тварей', 20, 200, 9000,
                'Двести поверженных врагов — счёт, который ведут немногие. Стань легендой боёв на этом острове.'),
        ];

        foreach ($quests as $q) {
            $exists = $this->db->table('quests')->where('title_en', $q['title_en'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('quests')->insert($q);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function q(string $titleEn, string $titleRu, int $minLevel, int $qty, int $reward, string $description): array
    {
        return [
            'title_ru'           => $titleRu,
            'title_en'           => $titleEn,
            'description'        => $description,
            'status'             => 'active',
            'min_level'          => $minLevel,
            'reward_type'        => 'gold',
            'reward'             => $reward,
            'prerequisite_quest' => null,
            'objective_type'     => 'npc_kills',
            'objective_target'   => null,
            'objective_qty'      => $qty,
            'branch_group'       => null,
            'branch_label'       => null,
        ];
    }

    public function down(): void
    {
        $this->db->table('quests')->whereIn('title_en', [
            'NpcHunterNovice', 'NpcHunterSkilled', 'NpcHunterVeteran',
        ])->delete();
    }
}

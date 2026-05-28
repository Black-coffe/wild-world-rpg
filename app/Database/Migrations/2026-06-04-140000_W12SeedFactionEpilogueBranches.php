<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W12 (vNext2, Фаза 3, ADR-067) — фракционные ветвящиеся эпилоги (контент-пасс).
 *
 * Чистый data-seed на branching-движке W11 (зеркало V13 на V12-движке — нового кода нет):
 * каждая фракционная цепочка получает развилку ПОСЛЕ сигнатурного оружия (contract-safe —
 * оружие уже получено, выбор не отнимает контент). Финал-крафт = branch-point; завершившись,
 * advanceChain видит 2 эпилог-ветки с branch_group → НЕ авто-назначает → pendingBranchOptions
 * предлагает игроку выбрать ОДИН эпилог (необратимо).
 *
 * 4 фракции × 2 эпилога = 8 квестов. Награды gold равны внутри пары (выбор = нарратив, не
 * мин-макс; admin-editable в quests.reward). Эмодзи — ТОЛЬКО в branch_label (utf8mb4 после
 * W11-fix); title_ru/description без эмодзи (колонки utf8mb3). objective char_level чуть выше
 * финала. Dormant: killswitch quests.branching_enabled OFF до активации. Idempotent.
 *
 *   Военные  (Bunker):     BunkerDominance      → IronRule / GarrisonPact
 *   Инженеры (Technopark):  TechnoparkBreakthrough → Automation / OpenSource
 *   Партизаны(GhostCity):   GhostCityDominance   → ShadowWar / UndergroundVoice
 *   Фермеры  (IslandFarm):  IslandFarmAbundance  → Granary / SeedsOfHope
 */
class W12SeedFactionEpilogueBranches extends Migration
{
    public function up(): void
    {
        $epilogues = [
            // ── Военные (Милитари) — после BunkerDominance (BunkerRifle) ──
            [
                'title_ru'           => 'Господство силой',
                'title_en'           => 'BunkerIronRule',
                'description'        => 'Бункерная винтовка в твоих руках — теперь решай, как держать регион. Путь силы: подчини окрестные банды страхом и сталью, пусть знают одного хозяина. Докажи право вести железной рукой.',
                'status'             => 'active',
                'min_level'          => 20,
                'reward_type'        => 'gold',
                'reward'             => 6000,
                'prerequisite_quest' => 'BunkerDominance',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 22,
                'branch_group'       => 'bunker_epilogue',
                'branch_label'       => '⚔️ Господство силой',
            ],
            [
                'title_ru'           => 'Союз гарнизонов',
                'title_en'           => 'BunkerGarrisonPact',
                'description'        => 'Бункерная винтовка в твоих руках — теперь решай, как держать регион. Путь союза: собери выживших командиров под одно знамя, раздели оборону и припасы. Сила в дисциплине многих, а не в страхе.',
                'status'             => 'active',
                'min_level'          => 20,
                'reward_type'        => 'gold',
                'reward'             => 6000,
                'prerequisite_quest' => 'BunkerDominance',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 22,
                'branch_group'       => 'bunker_epilogue',
                'branch_label'       => '🤝 Союз гарнизонов',
            ],
            // ── Инженеры — после TechnoparkBreakthrough (TechnoBeamShotgun) ──
            [
                'title_ru'           => 'Эра автоматизации',
                'title_en'           => 'TechnoparkAutomation',
                'description'        => 'Лучевой дробовик собран, лаборатория твоя. Путь машин: поставь производство на конвейер дронов и автоматов, пусть железо работает за людей. Будущее принадлежит тем, кто его собирает.',
                'status'             => 'active',
                'min_level'          => 22,
                'reward_type'        => 'gold',
                'reward'             => 7000,
                'prerequisite_quest' => 'TechnoparkBreakthrough',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 24,
                'branch_group'       => 'technopark_epilogue',
                'branch_label'       => '🤖 Эра автоматизации',
            ],
            [
                'title_ru'           => 'Открытый код',
                'title_en'           => 'TechnoparkOpenSource',
                'description'        => 'Лучевой дробовик собран, лаборатория твоя. Путь знания: открой чертежи пустоши, обучи мастеров в соседних поселениях. Технология, которой делятся, переживёт любую крепость.',
                'status'             => 'active',
                'min_level'          => 22,
                'reward_type'        => 'gold',
                'reward'             => 7000,
                'prerequisite_quest' => 'TechnoparkBreakthrough',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 24,
                'branch_group'       => 'technopark_epilogue',
                'branch_label'       => '📡 Открытый код',
            ],
            // ── Партизаны — после GhostCityDominance (GhostCityKnife) ──
            [
                'title_ru'           => 'Тихая война',
                'title_en'           => 'GhostCityShadowWar',
                'description'        => 'Нож Города-призрака отточен. Путь теней: веди войну исподтишка — саботаж, засады, страх в спину врагу. Пусть пустошь шепчет твоё имя, но не видит лица.',
                'status'             => 'active',
                'min_level'          => 14,
                'reward_type'        => 'gold',
                'reward'             => 5000,
                'prerequisite_quest' => 'GhostCityDominance',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 16,
                'branch_group'       => 'ghostcity_epilogue',
                'branch_label'       => '🗡️ Тихая война',
            ],
            [
                'title_ru'           => 'Голос подполья',
                'title_en'           => 'GhostCityUndergroundVoice',
                'description'        => 'Нож Города-призрака отточен. Путь голоса: запусти подпольное радио, неси правду и надежду по разрушенным кварталам. Идея, услышанная многими, опаснее любого клинка.',
                'status'             => 'active',
                'min_level'          => 14,
                'reward_type'        => 'gold',
                'reward'             => 5000,
                'prerequisite_quest' => 'GhostCityDominance',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 16,
                'branch_group'       => 'ghostcity_epilogue',
                'branch_label'       => '📻 Голос подполья',
            ],
            // ── Фермеры — после IslandFarmAbundance (FarmersHarvestScythe) ──
            [
                'title_ru'           => 'Закрома общины',
                'title_en'           => 'IslandFarmGranary',
                'description'        => 'Коса Жатвы выкована, угодья плодоносят. Путь запаса: построй общинные закрома, переживи любую зиму и осаду. Кто владеет хлебом — владеет волей выживших.',
                'status'             => 'active',
                'min_level'          => 16,
                'reward_type'        => 'gold',
                'reward'             => 6000,
                'prerequisite_quest' => 'IslandFarmAbundance',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 18,
                'branch_group'       => 'islandfarm_epilogue',
                'branch_label'       => '🌾 Закрома общины',
            ],
            [
                'title_ru'           => 'Семена надежды',
                'title_en'           => 'IslandFarmSeedsOfHope',
                'description'        => 'Коса Жатвы выкована, угодья плодоносят. Путь щедрости: раздай семена и саженцы соседним поселениям, озелени мёртвые клетки пустоши. Жизнь, которую ты сеешь, вернётся урожаем доверия.',
                'status'             => 'active',
                'min_level'          => 16,
                'reward_type'        => 'gold',
                'reward'             => 6000,
                'prerequisite_quest' => 'IslandFarmAbundance',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 18,
                'branch_group'       => 'islandfarm_epilogue',
                'branch_label'       => '🌱 Семена надежды',
            ],
        ];

        foreach ($epilogues as $q) {
            $exists = $this->db->table('quests')->where('title_en', $q['title_en'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('quests')->insert($q);
        }
    }

    public function down(): void
    {
        $this->db->table('quests')->whereIn('title_en', [
            'BunkerIronRule', 'BunkerGarrisonPact',
            'TechnoparkAutomation', 'TechnoparkOpenSource',
            'GhostCityShadowWar', 'GhostCityUndergroundVoice',
            'IslandFarmGranary', 'IslandFarmSeedsOfHope',
        ])->delete();
    }
}

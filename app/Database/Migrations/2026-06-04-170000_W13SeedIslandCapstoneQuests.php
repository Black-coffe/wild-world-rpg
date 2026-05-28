<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W13 (vNext2, Фаза 3, ADR-067) — островная story-arc: капстон главы 1 «остров».
 *
 * Data-seed на branching-движке W11 + strategic-discovery W13-ландмарка. Арка:
 *   StrategicCaptureIslandHeart (discover_object IslandHeart, root — авто-завершается
 *      на discovery через StrategicLootHandler::checkStrategicCaptureQuest + advanceChain)
 *   → IslandHeartAwakening   (char_level 25) — Сердце реагирует на присутствие
 *   → IslandHeartRevelation  (char_level 28) — раскрыта тайна острова
 *   → ВЕТВЯЩИЙСЯ ФИНАЛ (branch_group='island_fate', char_level 30, выбор необратим):
 *        ⚡ Зажечь маяк      (позвать помощь извне — конец изоляции)
 *        🤫 Хранить тайну    (остров останется сокрыт от мёртвого мира)
 *        🤝 Объединить выживших (остров как новый дом, без внешнего мира)
 *
 * Награды эскалируют (капстон): 5k → 8k → 12k → 20k. Финал-ветки = равный gold
 * (выбор = нарратив, admin-editable). Эмодзи только в branch_label (utf8mb4);
 * title_ru/description без эмодзи (utf8mb3).
 *
 * **Dormant:** root недостижим (ландмарк spawn=0), финал-ветки gated branching_enabled=OFF.
 * Idempotent.
 */
class W13SeedIslandCapstoneQuests extends Migration
{
    public function up(): void
    {
        $quests = [
            // ── Root: discover_object (авто-завершается на discovery) ──
            [
                'title_ru'           => 'Сердце острова',
                'title_en'           => 'StrategicCaptureIslandHeart',
                'description'        => 'Ты нашёл то, о чём шептались выжившие, не веря сами себе: глубоко в вулканической породе бьётся источник, что держал остров живым посреди мёртвого мира. Доколлапсная машина? Нечто большее? Подойди ближе — остров ждал тебя.',
                'status'             => 'active',
                'min_level'          => 20,
                'reward_type'        => 'gold',
                'reward'             => 5000,
                'prerequisite_quest' => null,
                'objective_type'     => 'discover_object',
                'objective_target'   => 'IslandHeart',
                'objective_qty'      => 1,
                'branch_group'       => null,
                'branch_label'       => null,
            ],
            // ── Mid 1 ──
            [
                'title_ru'           => 'Пробуждение Сердца',
                'title_en'           => 'IslandHeartAwakening',
                'description'        => 'Сердце острова откликнулось на твоё присутствие — гул стал ровнее, погасшие приборы замигали. Оно проверяет тебя: достаточно ли ты силён, чтобы вынести то, что узнаешь. Расти, выживший — достигни 25 уровня.',
                'status'             => 'active',
                'min_level'          => 20,
                'reward_type'        => 'gold',
                'reward'             => 8000,
                'prerequisite_quest' => 'StrategicCaptureIslandHeart',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 25,
                'branch_group'       => null,
                'branch_label'       => null,
            ],
            // ── Mid 2 ──
            [
                'title_ru'           => 'Откровение острова',
                'title_en'           => 'IslandHeartRevelation',
                'description'        => 'Сердце раскрылось перед тобой. Остров — не случайное убежище: его удержали намеренно, ценой выбора тех, кто был до тебя. Катастрофа не закончилась — она лишь замерла здесь. Теперь решение за тобой. Достигни 28 уровня и встань перед последним выбором главы.',
                'status'             => 'active',
                'min_level'          => 25,
                'reward_type'        => 'gold',
                'reward'             => 12000,
                'prerequisite_quest' => 'IslandHeartAwakening',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 28,
                'branch_group'       => null,
                'branch_label'       => null,
            ],
            // ── Ветвящийся финал: судьба острова (branch_group='island_fate') ──
            [
                'title_ru'           => 'Зажечь маяк',
                'title_en'           => 'IslandFateBeacon',
                'description'        => 'Ты решил покончить с изоляцией: направить силу Сердца в старый маяк и послать сигнал за пределы острова. Может, там кто-то выжил. Может, ты зовёшь беду. Но молчать дальше ты не станешь. Доведи дело до конца — достигни 30 уровня.',
                'status'             => 'active',
                'min_level'          => 28,
                'reward_type'        => 'gold',
                'reward'             => 20000,
                'prerequisite_quest' => 'IslandHeartRevelation',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 30,
                'branch_group'       => 'island_fate',
                'branch_label'       => '⚡ Зажечь маяк',
            ],
            [
                'title_ru'           => 'Хранить тайну',
                'title_en'           => 'IslandFateSanctuary',
                'description'        => 'Ты решил сберечь тайну: остров должен остаться сокрытым от мёртвого мира — единственный островок стабильности нельзя выдавать. Ты запечатаешь Сердце и станешь его хранителем. Прими эту ношу — достигни 30 уровня.',
                'status'             => 'active',
                'min_level'          => 28,
                'reward_type'        => 'gold',
                'reward'             => 20000,
                'prerequisite_quest' => 'IslandHeartRevelation',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 30,
                'branch_group'       => 'island_fate',
                'branch_label'       => '🤫 Хранить тайну',
            ],
            [
                'title_ru'           => 'Объединить выживших',
                'title_en'           => 'IslandFateUnity',
                'description'        => 'Ты решил строить будущее здесь: не звать чужих и не прятаться, а объединить фракции выживших вокруг Сердца. Остров станет новым домом, а не временным убежищем. Поведи людей — достигни 30 уровня.',
                'status'             => 'active',
                'min_level'          => 28,
                'reward_type'        => 'gold',
                'reward'             => 20000,
                'prerequisite_quest' => 'IslandHeartRevelation',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 30,
                'branch_group'       => 'island_fate',
                'branch_label'       => '🤝 Объединить выживших',
            ],
        ];

        foreach ($quests as $q) {
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
            'StrategicCaptureIslandHeart', 'IslandHeartAwakening', 'IslandHeartRevelation',
            'IslandFateBeacon', 'IslandFateSanctuary', 'IslandFateUnity',
        ])->delete();
    }
}

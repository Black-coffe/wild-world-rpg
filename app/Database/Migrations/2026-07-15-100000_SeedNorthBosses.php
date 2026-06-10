<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-106 — уникальные L30+ боссы глубокого севера (контент-хвост ADR-105).
 *
 * Три уникальных aggressive-босса (is_boss=1, по одному живому экземпляру в мире,
 * поддерживает RaiderGradientHandler при killswitch world.bosses.enabled):
 *  • Шрам, Мясник Пустоши (L30) — изгнанный вожак рейдеров (канон: Песчаные Волки);
 *  • Прототип «Цербер» (L32) — довоенная боевая машина сектора Технопарка;
 *  • Патриарх Стаи (L35) — волк-мутант, в честь которого рейдеры назвали себя.
 *
 * Награды — существующий level-based механизм RewardService (босс ≥ уровня игрока →
 * сильный пакет: статы, 1-10k золота, ресурсы rarity 2-6, дорогие предметы); лут-таблиц
 * в движке нет (loot_table_id — мёртвая колонка, NULL). Картинка не требуется: у
 * aggressive-NPC нет image-поверхности (авто-PvE уведомления текстовые; encounter-экран
 * только для passive, прецедент — стражи руин и именные NPC без ImageRegistry-записей).
 *
 * + /tips-намёк (UX-DISCOVERABILITY): боссы находятся исследованием глубокого севера.
 * Idempotent (npcs по npc_name_en, game_tips по title_en). npcs = KEEP (WipeManifest).
 */
class SeedNorthBosses extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $bosses = [
            [
                'en'    => 'boss_scar_butcher',
                'ru'    => 'Шрам, Мясник Пустоши',
                'descr' => 'Бывший вожак Песчаных Волков, изгнанный стаей за жестокость, дикую даже по меркам рейдеров. '
                    . 'Ушёл на глубокий север и одичал окончательно: о его лагере из костей рассказывают у костров шёпотом. '
                    . 'Огромный человек в латаной броне из дорожных знаков, с тесаком из рессоры.',
                'level' => 30, 'health' => 400, 'str' => 14, 'agi' => 14, 'int' => 10,
                'dmg'   => 16.0, 'speed' => 1.0, 'armor' => 6, 'range' => 6,
            ],
            [
                'en'    => 'boss_cerberus_prototype',
                'ru'    => 'Прототип «Цербер»',
                'descr' => 'Довоенная автономная боевая платформа из закрытого проекта Технопарка. Коллапс она не заметила: '
                    . 'протоколы целы, патрульный маршрут по северному сектору исполняется до сих пор. Три сенсорные головы '
                    . 'на шарнирах, ржавая композитная броня и оружейные подвесы, которым не нужен оператор.',
                'level' => 32, 'health' => 500, 'str' => 12, 'agi' => 10, 'int' => 16,
                'dmg'   => 18.0, 'speed' => 1.1, 'armor' => 10, 'range' => 8,
            ],
            [
                'en'    => 'boss_pack_patriarch',
                'ru'    => 'Патриарх Стаи',
                'descr' => 'Гигантский волк-мутант, переживший Коллапс в северных пустошах. Это в его честь рейдеры когда-то '
                    . 'назвали себя Песчаными Волками — и до сих пор не суются туда, где он метит территорию. Шрамы от пуль '
                    . 'на шкуре зарастали быстрее, чем стрелявшие успевали перезарядиться.',
                'level' => 35, 'health' => 600, 'str' => 18, 'agi' => 16, 'int' => 8,
                'dmg'   => 20.0, 'speed' => 1.2, 'armor' => 8, 'range' => 10,
            ],
        ];

        foreach ($bosses as $b) {
            $exist = $this->db->table('npcs')->where('npc_name_en', $b['en'])->get()->getRowArray();
            if (! empty($exist)) {
                continue;
            }
            $this->db->table('npcs')->insert([
                'npc_name_en'      => $b['en'],
                'npc_name_ru'      => $b['ru'],
                'npc_type'         => 'hostile',
                'description'      => $b['descr'],
                'level'            => $b['level'],
                'health'           => $b['health'],
                'strength'         => $b['str'],
                'agility'          => $b['agi'],
                'intellect'        => $b['int'],
                'damage_type'      => 'Physical',
                'damage_value'     => $b['dmg'],
                'attack_speed'     => $b['speed'],
                'armor'            => $b['armor'],
                'aggression_range' => $b['range'],
                'is_boss'          => 1,
                'ai_behavior'      => 'aggressive',
            ]);
        }

        // /tips-намёк (markdown-safe: парные *; utf8mb4). Idempotent по title_en.
        $titleEn = 'NorthBosses';
        if (empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            $content = '💀 *Хозяева глубокого севера:* выше руин, у самой кромки мира, бродят трое, о которых не врут '
                . 'даже рейдеры — *Шрам, Мясник Пустоши*, машина-страж *«Цербер»* и сам *Патриарх Стаи*, в честь '
                . 'которого Песчаные Волки взяли имя. Каждый — единственный в своём роде и сильнее любого стража руин. '
                . 'Они нападают первыми, едва ступишь на их землю. Свалишь такого — заберёшь добычу, какой юг не видел. '
                . 'Но идти стоит только с лучшим снаряжением и полным здоровьем — север ошибок не прощает.';

            $this->db->table('game_tips')->insert([
                'title_ru'   => '💀 Хозяева глубокого севера',
                'title_en'   => $titleEn,
                'tip_type'   => 'world',
                'content'    => $content,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('npcs')->whereIn('npc_name_en', ['boss_scar_butcher', 'boss_cerberus_prototype', 'boss_pack_patriarch'])->delete();
        $this->db->table('game_tips')->where('title_en', 'NorthBosses')->delete();
    }
}

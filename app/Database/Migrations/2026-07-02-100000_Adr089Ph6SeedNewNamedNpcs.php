<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Phase 6 (контент-пасс №2) — 3 новых именных NPC: разнообразное трио,
 * закрывающее канон-gap'ы (Блок 2).
 *
 * - Карн, вожак Песчаных Волков (RaiderWarlordKarn) — антагонист с лицом (GAP: не было
 *   именных злодеев). Нейтрал (faction_id=NULL), сильный, ai_behavior='passive' (можно
 *   подойти и говорить; провокация→бой). Тема: угрозы/откуп/идеология/вызов.
 * - Полынь, знахарка (HerbalistPolyn) — целитель (GAP: медицина без лица). Слабый бой,
 *   лор болезней/трав/Коллапса, торговля снадобьями.
 * - Ворон, торговец-кочевник (NomadTraderVoron) — торговец (GAP: торговля безлична). Лор
 *   мира глазами бродяги (фракции, руины, рейдеры, радист Корвин), торг, наводки.
 *
 * Все npc_type='named' → размещаются NamedNpcPlacementHandler (killswitch
 * npc.named_placement_enabled). faction_id=NULL (нейтралы), offers_quest_title_en=NULL
 * (чистые диалог/лор/act-исходы; квесты — опц. позже). Глубокие деревья — отдельной
 * миграцией Adr089Ph6NewNamedNpcTrees (npc_dialogue_nodes, 122 узла). Здесь — npcs-строки
 * + базовые npc_dialogues (greeting + attitude-варианты, фолбэк при rich-OFF / media-off).
 * Тон ADR-062. Idempotent (по npc_name_en / (npc_id,dialogue_type)).
 *
 * WipeManifest: npcs/npc_dialogues = KEEP (контент). Новых таблиц нет → coverage не затронут.
 */
class Adr089Ph6SeedNewNamedNpcs extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $npcs = [
            [
                'npc_name_en'  => 'RaiderWarlordKarn',
                'npc_name_ru'  => 'Карн, вожак Песчаных Волков',
                'description'  => 'Поджарый, как высушенная пустошью кость. Выцветший китель без знаков различия, мачете на бедре, взгляд того, кто давно перестал спешить. За спиной — два молчаливых волка.',
                'level'        => 16, 'health' => 200, 'strength' => 16, 'agility' => 10, 'intellect' => 9,
                'damage_value' => 16, 'armor' => 8,
                'dialogues'    => [
                    'greeting'          => 'Карн не встаёт с остова сгоревшей фуры. Точит мачете, меряет тебя взглядом. «Гость. В моей пустоши гостей нет — есть падаль, что ещё дышит. Говори, зачем пришёл.»',
                    'greeting_friendly' => 'Карн коротко кивает, не отрываясь от точила. «Снова ты, дышащий. Пески тебя пока терпят. Говори.»',
                    'greeting_wary'     => 'Карн кладёт ладонь на мачете. «Опять ты. Смотри без глупостей — у меня сегодня плохое настроение и острый клинок.»',
                    'greeting_hostile'  => 'Карн поднимается во весь рост, волки за спиной подбираются. «У нас с тобой незакрытый счёт. Лучше тебе развернуться, пока можешь.»',
                    'ask'               => '«Дороги? На востоке переправа — там мои волки берут дань. На севере фронт. Хочешь жить — плати или не суйся.»',
                    'trade'             => '«Я не лавочник. Бери что нужно — но цена будет в крови или в дани. Выбирай.»',
                ],
            ],
            [
                'npc_name_en'  => 'HerbalistPolyn',
                'npc_name_ru'  => 'Полынь, знахарка',
                'description'  => 'Жилистая женщина в латаном переднике, пропахшем сушёной травой и едкими отварами. Руки в мелких ожогах и порезах, но не дрожат. Смотрит так, будто уже знает, чем ты болен.',
                'level'        => 9, 'health' => 85, 'strength' => 6, 'agility' => 7, 'intellect' => 13,
                'damage_value' => 6, 'armor' => 2,
                'dialogues'    => [
                    'greeting'          => 'Полынь толчёт горькие корни в ступке, не поднимая головы. «Живой пришёл или раненого приволок? Если живой — не топчись на сухой траве. Говори, чего надо.»',
                    'greeting_friendly' => 'Полынь кивает на табурет у очага. «А, это ты. Садись, коли с миром. Чай горький, зато живой. Чего болит?»',
                    'greeting_wary'     => 'Полынь заслоняет рукой короб со снадобьями. «Ты в прошлый раз косился на мои склянки. Смотри, я приметливая.»',
                    'greeting_hostile'  => 'Полынь перехватывает ступку покрепче. «От таких, как ты, у меня горсть жгучего порошка наготове. Уходи.»',
                    'ask'               => '«Дороги? Я по ним хожу за травой, не за славой. Где почище — туда и тебе можно. Где трава пышная да тишина — обходи, там отрава.»',
                    'trade'             => '«Снадобья и травы — за плату или за честное сырьё. Я не наживаюсь на хвори, но и даром не раздаю. Показать?»',
                ],
            ],
            [
                'npc_name_en'  => 'NomadTraderVoron',
                'npc_name_ru'  => 'Ворон, торговец-кочевник',
                'description'  => 'Жилистый бродяга, обвешанный тюками и связками непонятного добра. Глаза, что оценивают тебя, как товар на весах. Говорит легко, держит руку у пояса по привычке.',
                'level'        => 10, 'health' => 100, 'strength' => 8, 'agility' => 10, 'intellect' => 11,
                'damage_value' => 9, 'armor' => 4,
                'dialogues'    => [
                    'greeting'          => 'Ворон не разгибается над тюками сразу — сперва оценивает тебя, как товар. «О, живой кошелёк на ножках. Шучу. Почти. Ворон, торговец. Чего ищешь — товар, слухи или тень присесть?»',
                    'greeting_friendly' => 'Ворон расплывается в улыбке бывалого. «А, мой любимый покупатель. Заходи, заходи. Товар свежий, слухи свежее. С чего начнём?»',
                    'greeting_wary'     => 'Ворон кладёт руку на тюк, ближе к рукояти. «Снова ты. Гляди, не лапай, что не купил — у меня память хорошая на такое.»',
                    'greeting_hostile'  => 'Ворон не улыбается. «А вот тебе я не рад. Иди мимо, путник, пока мы оба в плюсе. Второй раз по-доброму не скажу.»',
                    'ask'               => '«Дороги? Это мой хлеб. Держись низин у воды, иди вдоль старых столбов. И не лезь туда, откуда кто-то уже не вернулся, как бы коротко ни манило.»',
                    'trade'             => '«Наконец деловой разговор. Товар редкий, цена дорожная. Поторгуемся — это половина удовольствия. Гляди.»',
                ],
            ],
        ];

        foreach ($npcs as $n) {
            $existing = $this->db->table('npcs')->where('npc_name_en', $n['npc_name_en'])->get()->getRowArray();
            if (empty($existing)) {
                $this->db->table('npcs')->insert([
                    'npc_name_en'           => $n['npc_name_en'],
                    'npc_name_ru'           => $n['npc_name_ru'],
                    'npc_type'              => 'named',
                    'description'           => $n['description'],
                    'level'                 => $n['level'],
                    'health'                => $n['health'],
                    'strength'              => $n['strength'],
                    'agility'               => $n['agility'],
                    'intellect'             => $n['intellect'],
                    'damage_type'           => 'Physical',
                    'damage_value'          => $n['damage_value'],
                    'attack_speed'          => 1.00,
                    'armor'                 => $n['armor'],
                    'aggression_range'      => 0,
                    'is_boss'               => 0,
                    'faction_id'            => null,
                    'ai_behavior'           => 'passive',
                    'offers_quest_title_en' => null,
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ]);
                $npcId = (int) $this->db->insertID();
            } else {
                $npcId = (int) $existing['id'];
            }

            foreach ($n['dialogues'] as $type => $text) {
                $hasLine = $this->db->table('npc_dialogues')
                    ->where('npc_id', $npcId)->where('dialogue_type', $type)->get()->getRowArray();
                if (empty($hasLine)) {
                    $this->db->table('npc_dialogues')->insert([
                        'npc_id'        => $npcId,
                        'dialogue_type' => $type,
                        'text_ru'       => $text,
                        'weight'        => 1,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $names = ['RaiderWarlordKarn', 'HerbalistPolyn', 'NomadTraderVoron'];
        foreach ($names as $name) {
            $row = $this->db->table('npcs')->where('npc_name_en', $name)->get()->getRowArray();
            if (! empty($row)) {
                $id = (int) $row['id'];
                $this->db->table('npc_dialogue_nodes')->where('npc_id', $id)->delete();
                $this->db->table('npc_dialogues')->where('npc_id', $id)->delete();
                $this->db->table('npcs')->where('id', $id)->delete();
            }
        }
    }
}

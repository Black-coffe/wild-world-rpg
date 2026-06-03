<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Phase 6 (контент-пасс) — 4 именных фракционных NPC (по представителю на фракцию).
 *
 * Милитари (1) / Партизаны (2) / Инженеры (3) / Фермеры (4). npc_type='named', ai_behavior='passive'
 * (не атакуются авто, размещаются NamedNpcPlacementHandler на фикс-ландмарках). faction_id → автоматическая
 * faction-реакция (NpcRelationService::factionModifier: своя фракция +25 / чужая −15). offers_quest_title_en
 * → тематический faction-квест (хук ADR-088, уже засеяны ids 51-58). Глубокие деревья диалога — отдельной
 * миграцией (DeepFactionDialogueTrees, workflow-сгенерены). Здесь — npcs-строки + базовые npc_dialogues
 * (фолбэк/attitude-greeting, контент полноценен без дерева). Тон — скупой постапок (ADR-062). Idempotent.
 */
class SeedFactionNamedNpcs extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $npcs = [
            [
                'npc_name_en'           => 'MilitarySergeantVargas',
                'npc_name_ru'           => 'Варга, сержант Милитари',
                'description'           => 'Прямой, как ствол. Выцветший китель с нашивкой Милитари, взгляд, что меряет тебя по одной шкале — годен или нет.',
                'level'                 => 12,
                'health'                => 130,
                'strength'              => 11,
                'agility'               => 7,
                'intellect'             => 8,
                'damage_value'          => 11,
                'armor'                 => 5,
                'faction_id'            => 1,
                'offers_quest_title_en' => 'MilitarySweep',
                'dialogues'             => [
                    'greeting'          => 'Сержант скользит по тебе оценивающим взглядом. «Ещё один с пустоши. Стоять прямо умеешь? Говори, чего надо.»',
                    'greeting_friendly' => 'Варга коротко кивает. «А, это ты. Из тех, кто не бежит. Уважаю. Слушаю.»',
                    'greeting_wary'     => 'Сержант не убирает руку с ремня. «Снова ты. Смотри у меня без глупостей.»',
                    'greeting_hostile'  => 'Варга шагает навстречу, плечом вперёд. «У нас с тобой счёт. Лучше тебе развернуться.»',
                    'ask'               => '«Дороги? На севере — линия фронта, на юге — салаги вроде тебя. Хочешь выжить — учись держать строй.»',
                    'trade'             => '«Я не лавочник. Патроны идут тем, кто их заслужил, а не тем, кто звенит золотом.»',
                ],
            ],
            [
                'npc_name_en'           => 'PartisanLiaisonShade',
                'npc_name_ru'           => 'Тень, связная Партизан',
                'description'           => 'Появляется из ниоткуда, будто была здесь всегда. Лица не разглядеть под капюшоном. Говорит тихо, слушает ещё тише.',
                'level'                 => 10,
                'health'                => 90,
                'strength'              => 8,
                'agility'               => 13,
                'intellect'             => 9,
                'damage_value'          => 9,
                'armor'                 => 2,
                'faction_id'            => 2,
                'offers_quest_title_en' => 'PartisanExplosives',
                'dialogues'             => [
                    'greeting'          => 'Тень не двигается, только глаза следят за тобой из-под капюшона. «Тихо. Ты громкий, как стадо. Чего тебе?»',
                    'greeting_friendly' => 'Капюшон чуть склоняется. «Свой. Хорошо. Говори, но вполголоса.»',
                    'greeting_wary'     => 'Рука Тени уже у пояса. «Ты слишком много про меня знаешь для случайного. Осторожнее.»',
                    'greeting_hostile'  => 'Тень растворяется на полшага в сторону. «Один неверный жест — и тебя не станет раньше, чем поймёшь.»',
                    'ask'               => '«Дороги — для тех, кто хочет, чтоб его нашли. Я хожу там, где не ходит никто. И тебе советую забыть прямые тропы.»',
                    'trade'             => '«Менять? Я не торгую. Я договариваюсь. И не с каждым.»',
                ],
            ],
            [
                'npc_name_en'           => 'EngineerMechanicDietrich',
                'npc_name_ru'           => 'Дитрих, механик Инженеров',
                'description'           => 'Руки в масле по локоть, на поясе — связка инструмента. Смотрит на людей так же, как на механизм: что тут можно починить, а что — на запчасти.',
                'level'                 => 11,
                'health'                => 100,
                'strength'              => 8,
                'agility'               => 7,
                'intellect'             => 13,
                'damage_value'          => 8,
                'armor'                 => 4,
                'faction_id'            => 3,
                'offers_quest_title_en' => 'EngineerElectronics',
                'dialogues'             => [
                    'greeting'          => 'Дитрих не отрывается от разобранного блока. «Секунду… так. Живой, ходишь, говоришь — уже неплохо собран. Чего хотел?»',
                    'greeting_friendly' => 'Механик откладывает отвёртку. «О, рабочие руки пришли. Садись, не мешай только проводам.»',
                    'greeting_wary'     => 'Дитрих прикрывает ладонью схему. «Ты в прошлый раз слишком долго смотрел на мои чертежи. Чего надо?»',
                    'greeting_hostile'  => 'Механик опускает руку к разводному ключу. «От тебя одни поломки. Иди отсюда, пока цел.»',
                    'ask'               => '«Дороги? Карта — это просто данные. Север фонит, юг безопасен и потому бесполезен. Хочешь дальше — нужна не удача, а исправный механизм. И голова.»',
                    'trade'             => '«Детали — за детали. Электронику до Коллапса не купишь за золото, её надо приносить.»',
                ],
            ],
            [
                'npc_name_en'           => 'FarmerElderMarta',
                'npc_name_ru'           => 'Марта, старшая по полям',
                'description'           => 'Жилистая, обветренная женщина с мозолистыми ладонями. От неё пахнет землёй и сухими травами. Едва ли не единственная здесь, кто говорит о будущем, а не о вчерашнем.',
                'level'                 => 9,
                'health'                => 110,
                'strength'              => 7,
                'agility'               => 6,
                'intellect'             => 10,
                'damage_value'          => 7,
                'armor'                 => 3,
                'faction_id'            => 4,
                'offers_quest_title_en' => 'FarmerGranary',
                'dialogues'             => [
                    'greeting'          => 'Марта разгибается над грядкой, вытирая ладони о фартук. «Живой человек, не падальщик. Редкость. Присаживайся, коли с миром.»',
                    'greeting_friendly' => 'Лицо Марты теплеет. «А, это ты. Заходи, заходи. Земля помнит добрые руки.»',
                    'greeting_wary'     => 'Марта заслоняет собой корзину с семенами. «Ты в прошлый раз ушёл не с пустыми руками. Смотри, я считаю.»',
                    'greeting_hostile'  => 'Она крепче перехватывает мотыгу. «Таким, как ты, на моём поле не место. Уходи.»',
                    'ask'               => '«Дороги? Все дороги одинаковы, если в конце нет своего поля. Найди клочок земли, что прокормит, — и не нужно будет никуда бежать.»',
                    'trade'             => '«Семена и хлеб — тем, кто будет сеять, а не проедать. Покажи, что от тебя будет толк.»',
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
                    'faction_id'            => $n['faction_id'],
                    'ai_behavior'           => 'passive',
                    'offers_quest_title_en' => $n['offers_quest_title_en'],
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
        $names = ['MilitarySergeantVargas', 'PartisanLiaisonShade', 'EngineerMechanicDietrich', 'FarmerElderMarta'];
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

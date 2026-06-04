<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-099 — «Фракционные рядовые» (массовка фракций).
 *
 * 4 рядовых NPC-представителя фракций (по одному на фракцию): Патрульный Милитари (1),
 * Разведчик Партизан (2), Техник Инженеров (3), Работник полей (4). npc_type='faction',
 * ai_behavior='passive'. В ОТЛИЧИЕ от именных (Варга/Тень/Дитрих/Марта) — это безымянная
 * массовка: НЕ квестгиверы (offers_quest_title_en = NULL), без фикс-placement; спавнятся
 * вперемешку с истинными нейтралами через WandererSpawnHandler (доля = GameSetting
 * npc.faction_wanderer_ratio). faction_id → автоматическая faction-реакция
 * (NpcRelationService::factionModifier: своя фракция +25 / чужая −15) — кода реакции не
 * требует. Деревья диалога НЕ нужны: при отсутствии root-узла встреча падает в легаси-меню
 * (NpcEncounterAction). Реплики — greeting + attitude-варианты (friendly/wary/hostile) +
 * ask/trade/talk/rob — чтобы своя/чужая фракция читалась по тону. Тон — скупой постапок
 * (ADR-062). Idempotent (по npc_name_en / по (npc_id,dialogue_type)).
 *
 * WipeManifest: npcs/npc_dialogues = KEEP (контент-определения, не player-данные). Новых
 * таблиц нет → coverage-тест не затронут.
 */
class Adr099SeedFactionWandererNpcs extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $npcs = [
            [
                'npc_name_en'  => 'MilitaryPatrolman',
                'npc_name_ru'  => 'Патрульный Милитари',
                'description'  => 'Латаный бронежилет с выгоревшей нашивкой Милитари, автомат на ремне. Стоит так, будто за спиной — устав, а не пустошь.',
                'level'        => 7, 'health' => 85, 'strength' => 8, 'agility' => 7, 'intellect' => 5,
                'damage_value' => 9, 'armor' => 4, 'faction_id' => 1,
                'dialogues'    => [
                    'greeting'          => 'Патрульный коротко ведёт стволом к земле. «Стой. Назовись. На землях Милитари чужие долго не ходят.»',
                    'greeting_friendly' => 'Боец узнаёт нашивку и опускает оружие. «Свои. Проходи. Сержанту лишние руки на построении не помешают.»',
                    'greeting_wary'     => 'Патрульный не убирает палец со спускового крючка. «Ты уже мелькал тут. Без резких движений.»',
                    'greeting_hostile'  => 'Он передёргивает затвор. «Тебя предупреждали. Уходи, пока я не вызвал подмогу.»',
                    'ask'               => '«Дороги? На севере фронт, туда лезут дураки и герои — разница невелика. Держись юга, если хочешь дослужить до завтра.»',
                    'trade'             => '«Снаряжение со склада — только по предписанию. У тебя его нет. Разговор окончен.»',
                    'talk'              => '«Болтать на посту не положено. Скажу одно: дисциплина держит дольше, чем стены. Запомни.»',
                    'rob_success'       => 'Ты сбиваешь его с ног и срываешь подсумок. Он уже хрипит в рацию про нарушителя — пора уходить.',
                    'rob_fail'          => '«Нападение на патруль!» — приклад идёт тебе в лицо, рация надрывается.',
                ],
            ],
            [
                'npc_name_en'  => 'PartisanScout',
                'npc_name_ru'  => 'Разведчик Партизан',
                'description'  => 'Бесцветная ветошь поверх лёгкой брони, лицо в саже. Замечаешь его, только когда он сам позволяет себя заметить.',
                'level'        => 7, 'health' => 70, 'strength' => 6, 'agility' => 11, 'intellect' => 7,
                'damage_value' => 8, 'armor' => 2, 'faction_id' => 2,
                'dialogues'    => [
                    'greeting'          => 'Разведчик возникает сбоку, будто стоял там всегда. «Тихо иди. Громких в пустоши быстро находят. Чего тебе?»',
                    'greeting_friendly' => 'Он чуть опускает шарф с лица. «Свой. Ладно. Партизаны помнят тех, кто умеет молчать.»',
                    'greeting_wary'     => 'Рука разведчика ложится на рукоять ножа. «Ты слишком приметлив для случайного. Не нравишься ты мне.»',
                    'greeting_hostile'  => 'Он делает шаг назад, в тень. «Ещё ближе — и тебя найдут под утро. Если найдут.»',
                    'ask'               => '«Прямых троп не держу — они для тех, кого ищут. Хочешь пройти незаметно — иди оврагами, вдоль колеи.»',
                    'trade'             => '«Партизаны не торгуют на виду. Принесёшь нужное — может, и сговоримся. Не здесь.»',
                    'talk'              => '«Сила Милитари — в стенах. Наша — в том, что стен нет. Нас не осадить. Запомни это.»',
                    'rob_success'       => 'Ты выхватываешь его сумку прежде, чем он успевает раствориться. В ней — чьи-то метки на карте.',
                    'rob_fail'          => 'Нож чиркает у самого горла. «Зря.» — и он уже не там, где был.',
                ],
            ],
            [
                'npc_name_en'  => 'EngineerTechnician',
                'npc_name_ru'  => 'Техник Инженеров',
                'description'  => 'Комбинезон в масляных пятнах, на поясе — тестер и моток провода. Смотрит на тебя так, словно прикидывает, из чего ты собран.',
                'level'        => 7, 'health' => 80, 'strength' => 6, 'agility' => 6, 'intellect' => 11,
                'damage_value' => 7, 'armor' => 3, 'faction_id' => 3,
                'dialogues'    => [
                    'greeting'          => 'Техник не отрывается от вскрытого щитка. «Не трогай ничего под напряжением. Говори, чего надо, только быстро.»',
                    'greeting_friendly' => 'Он кивает на нашивку. «А, наши. Держи руки чистыми и не лезь в проводку — поладим.»',
                    'greeting_wary'     => 'Техник прикрывает ладонью планшет с чертежом. «Ты в прошлый раз слишком долго пялился на схему. Чего опять?»',
                    'greeting_hostile'  => 'Рука ложится на разводной ключ. «От тебя одни короткие замыкания. Уходи, пока цел.»',
                    'ask'               => '«Маршрут? Север фонит — счётчик там зашкаливает. Юг чист, но пуст. Нужен исправный транспорт и голова на плечах.»',
                    'trade'             => '«Электронику за золото не беру — её до Коллапса делали, теперь не достать. Принеси платы, провод — тогда поговорим.»',
                    'talk'              => '«Всё в мире — механизм. И человек тоже. Сломанное чинят, безнадёжное — на запчасти. Ты пока чинишься.»',
                    'rob_success'       => 'Ты сгребаешь его инструмент и горсть деталей. Техник ругается терминами, которых ты не знаешь.',
                    'rob_fail'          => '«Ах ты—» — разводной ключ свистит у виска; бить он умеет не только по железу.',
                ],
            ],
            [
                'npc_name_en'  => 'FarmFieldHand',
                'npc_name_ru'  => 'Работник полей',
                'description'  => 'Загорелый дочерна, в холщовой рубахе, под ногтями земля. От него пахнет соломой и потом — редкий в пустоши запах труда, а не тлена.',
                'level'        => 6, 'health' => 90, 'strength' => 8, 'agility' => 5, 'intellect' => 6,
                'damage_value' => 7, 'armor' => 3, 'faction_id' => 4,
                'dialogues'    => [
                    'greeting'          => 'Работник разгибается над бороздой, опираясь на тяпку. «Живой, и не с оружием наперевес. Уже хорошо. Чего ищешь?»',
                    'greeting_friendly' => 'Лицо его теплеет. «Свой. Заходи, воды дам. Фермеры своих с поля не гонят.»',
                    'greeting_wary'     => 'Он заслоняет собой корзину. «Ты в прошлый раз ушёл не с пустыми руками. Я приметливый.»',
                    'greeting_hostile'  => 'Тяпка перехвачена, как древко. «Таким, как ты, на моём поле не место. Прочь.»',
                    'ask'               => '«Дороги? Все одинаковы, коли в конце нет своей борозды. Найди землю, что прокормит, — и бежать перестанешь.»',
                    'trade'             => '«Хлеб и семена — тем, кто будет сеять, а не проедать. Покажешь толк — поделюсь.»',
                    'talk'              => '«Воюют все, а есть хотят тоже все. Пока другие жгут — мы растим. Кто, по-твоему, переживёт зиму?»',
                    'rob_success'       => 'Ты опрокидываешь корзину и выгребаешь припасы. Работник кричит вслед что-то про проклятую саранчу.',
                    'rob_fail'          => '«Вор на поле!» — тяпка бьёт под колено, и на крик уже бегут от соседних борозд.',
                ],
            ],
        ];

        foreach ($npcs as $n) {
            $existing = $this->db->table('npcs')->where('npc_name_en', $n['npc_name_en'])->get()->getRowArray();
            if (empty($existing)) {
                $this->db->table('npcs')->insert([
                    'npc_name_en'           => $n['npc_name_en'],
                    'npc_name_ru'           => $n['npc_name_ru'],
                    'npc_type'              => 'faction',
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
        $names = ['MilitaryPatrolman', 'PartisanScout', 'EngineerTechnician', 'FarmFieldHand'];
        foreach ($names as $name) {
            $row = $this->db->table('npcs')->where('npc_name_en', $name)->get()->getRowArray();
            if (! empty($row)) {
                $id = (int) $row['id'];
                $this->db->table('npc_dialogues')->where('npc_id', $id)->delete();
                $this->db->table('npcs')->where('id', $id)->delete();
            }
        }
    }
}

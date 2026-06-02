<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Фаза 1 — сид нейтральных NPC (ai_behavior='passive') + их реплик (dormant).
 *
 * Нейтральные «выжившие пустоши»: не атакуют первыми, при встрече игрок выбирает действие.
 * Скупой постапок-тон (ADR-062). Контент засеян, но виден только при killswitch
 * npc.interaction_enabled=ON. Спавнятся WandererSpawnHandler'ом (тоже killswitch-gated).
 * Idempotent (по npc_name_en / по (npc_id,dialogue_type)).
 */
class SeedNeutralNpcsAndDialogues extends Migration
{
    public function up(): void
    {
        $npcs = [
            [
                'npc_name_en' => 'WastelandWanderer',
                'npc_name_ru' => 'Странник пустоши',
                'description' => 'Сгорбленная фигура в выцветшем плаще. Идёт куда-то по своим делам, не ищет беды — но и спину не подставляет.',
                'level' => 8, 'health' => 80, 'strength' => 7, 'agility' => 6, 'intellect' => 5,
                'damage_value' => 9, 'armor' => 3,
                'dialogues' => [
                    'greeting' => 'Странник останавливается в нескольких шагах. «Чего тебе?» — голос сухой, как песок.',
                    'talk'     => '«Видал я города до Коллапса. Теперь там только ветер да кости. Не ходи на север — там нечего искать, кроме своей смерти.»',
                    'ask'      => '«Дорога? Дорог нет. Есть направления и удача. У тебя, похоже, и того, и другого в обрез.»',
                    'trade'    => '«Менять? У меня только фляга да нож. Нож не отдам, флягу — тем более.»',
                    'rob_success' => 'Ты сбиваешь его с ног и выгребаешь из карманов всё, что нащупал. Он не сопротивляется — только смотрит вслед.',
                    'rob_fail'    => '«Ошибся ты, парень.» — нож появляется в его руке быстрее, чем ты ждал.',
                ],
            ],
            [
                'npc_name_en' => 'ScrapScavenger',
                'npc_name_ru' => 'Мусорщик',
                'description' => 'Обвешан мешками с хламом, под ногтями въевшаяся ржавчина. Перебирает находки, косясь на тебя одним глазом.',
                'level' => 6, 'health' => 65, 'strength' => 5, 'agility' => 8, 'intellect' => 6,
                'damage_value' => 7, 'armor' => 2,
                'dialogues' => [
                    'greeting' => 'Мусорщик прижимает мешок к груди. «Это моё. Всё моё. Тебе чего?»',
                    'talk'     => '«Хлам? Это не хлам. Это будущее. Из этой ржавчины кто-то соберёт жизнь. Может, ты. Может, я.»',
                    'ask'      => '«Где брать добро? Везде, где другие померли. Их добра уже не жалко.»',
                    'trade'    => '«Поменяемся? Покажи, что есть. Только без фокусов — я кусаюсь.»',
                    'rob_success' => 'Мешок рвётся, и половина добра — теперь твоя. Мусорщик с воплем ползает в пыли, собирая остатки.',
                    'rob_fail'    => '«А-а, вор!» — он швыряет в тебя обломок арматуры и кидается следом.',
                ],
            ],
            [
                'npc_name_en' => 'SilentHermit',
                'npc_name_ru' => 'Молчаливый отшельник',
                'description' => 'Старик у потухшего костра. Лицо изрезано шрамами, взгляд — спокойнее, чем должна позволять пустошь.',
                'level' => 12, 'health' => 110, 'strength' => 9, 'agility' => 5, 'intellect' => 10,
                'damage_value' => 12, 'armor' => 5,
                'dialogues' => [
                    'greeting' => 'Отшельник не поднимает головы. «Садись, коли с миром. Или иди, коли нет.»',
                    'talk'     => '«Я говорю редко. Слова — как вода: тратишь зря, а потом жажда. Но тебе скажу: выживает не сильный, а тот, кто умеет ждать.»',
                    'ask'      => '«Спрашиваешь дорогу? Все дороги ведут в одно. Важно лишь, кем ты придёшь туда.»',
                    'trade'    => '«Мне ничего не нужно. И тебе скоро не будет. Но это не сегодня.»',
                    'rob_success' => 'Старик не двигается, пока ты обираешь его. «Бери. Это всё равно не моё было.» — от его спокойствия стынет кровь.',
                    'rob_fail'    => 'Ты не успеваешь коснуться его мешка — посох бьёт по запястью с неожиданной силой. «Зря.»',
                ],
            ],
        ];

        foreach ($npcs as $n) {
            $existing = $this->db->table('npcs')->where('npc_name_en', $n['npc_name_en'])->get()->getRowArray();
            if (! empty($existing)) {
                $npcId = (int) $existing['id'];
            } else {
                $this->db->table('npcs')->insert([
                    'npc_name_en'      => $n['npc_name_en'],
                    'npc_name_ru'      => $n['npc_name_ru'],
                    'npc_type'         => 'neutral',
                    'description'      => $n['description'],
                    'level'            => $n['level'],
                    'health'           => $n['health'],
                    'strength'         => $n['strength'],
                    'agility'          => $n['agility'],
                    'intellect'        => $n['intellect'],
                    'damage_type'      => 'Physical',
                    'damage_value'     => $n['damage_value'],
                    'attack_speed'     => 1.00,
                    'armor'            => $n['armor'],
                    'aggression_range' => 0,
                    'is_boss'          => 0,
                    'ai_behavior'      => 'passive',
                ]);
                $npcId = (int) $this->db->insertID();
            }

            foreach ($n['dialogues'] as $type => $text) {
                $has = $this->db->table('npc_dialogues')
                    ->where('npc_id', $npcId)->where('dialogue_type', $type)->get()->getRowArray();
                if (! empty($has)) {
                    continue;
                }
                $this->db->table('npc_dialogues')->insert([
                    'npc_id'        => $npcId,
                    'dialogue_type' => $type,
                    'text_ru'       => $text,
                    'weight'        => 1,
                    'created_at'    => date('Y-m-d H:i:s'),
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    public function down(): void
    {
        $names = ['WastelandWanderer', 'ScrapScavenger', 'SilentHermit'];
        $ids = [];
        foreach ($this->db->table('npcs')->whereIn('npc_name_en', $names)->get()->getResultArray() as $row) {
            $ids[] = (int) $row['id'];
        }
        if ($ids !== []) {
            $this->db->table('npc_dialogues')->whereIn('npc_id', $ids)->delete();
        }
        $this->db->table('npcs')->whereIn('npc_name_en', $names)->delete();
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Фаза 5+ — именной NPC «Корвин, бывший радист» + ветвящийся диалог.
 *
 * Нейтральный (passive) именной NPC с деревом диалога (npc_dialogue_nodes): беседа ветвится,
 * выбор реплик влияет на отношение. Тон — скупой постапок (ADR-062). Idempotent (по npc_name_en).
 * Узлы без подчёркиваний в node_key (callback парсится по '_').
 */
class SeedNamedNpcKorvin extends Migration
{
    public function up(): void
    {
        $nameEn = 'RadioOperatorKorvin';
        $row    = $this->db->table('npcs')->where('npc_name_en', $nameEn)->get()->getRowArray();

        if (empty($row)) {
            $this->db->table('npcs')->insert([
                'npc_name_en'      => $nameEn,
                'npc_name_ru'      => 'Корвин, бывший радист',
                'npc_type'         => 'named',
                'description'      => 'Седой человек у самодельной рации, обмотанной проволокой. Крутит верньер, ловя то, чего, возможно, уже нет.',
                'level'            => 10,
                'health'           => 95,
                'strength'         => 7,
                'agility'          => 5,
                'intellect'        => 12,
                'damage_type'      => 'Physical',
                'damage_value'     => 8,
                'attack_speed'     => 1.00,
                'armor'            => 3,
                'aggression_range' => 0,
                'is_boss'          => 0,
                'ai_behavior'      => 'passive',
            ]);
            $npcId = (int) $this->db->insertID();
        } else {
            $npcId = (int) $row['id'];
        }

        // Базовые реплики (greeting для экрана встречи; ask/trade fallback есть в сервисе).
        $dlg = [
            'greeting' => 'Корвин на миг отрывается от рации. «Редко кто доходит сюда живым. Чего тебе, выживший?»',
            'ask'      => '«Дороги? Я слушаю эфир, а не топчу пустошь. Но на север не ходи без крайней нужды.»',
        ];
        foreach ($dlg as $type => $text) {
            if (empty($this->db->table('npc_dialogues')->where('npc_id', $npcId)->where('dialogue_type', $type)->get()->getRowArray())) {
                $this->db->table('npc_dialogues')->insert([
                    'npc_id' => $npcId, 'dialogue_type' => $type, 'text_ru' => $text, 'weight' => 1,
                    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        // Дерево диалога: node_key => [text, options[{label,next,rel}]].
        $nodes = [
            'root' => [
                'text'    => 'Корвин крутит верньер. Сквозь треск помех пробивается чей-то далёкий голос — и снова тонет в шуме. «Говори, раз пришёл.»',
                'options' => [
                    ['label' => 'Кто ты?', 'next' => 'about', 'rel' => 2],
                    ['label' => 'Что слышно в эфире?', 'next' => 'radio', 'rel' => 2],
                    ['label' => 'Молча уйти', 'next' => 'end', 'rel' => 0],
                ],
            ],
            'about' => [
                'text'    => '«Был радистом до Коллапса. Ловил последние передачи, пока они не смолкли одна за другой. Теперь ловлю только ветер да чужие шаги.»',
                'options' => [
                    ['label' => 'Зачем ты здесь?', 'next' => 'purpose', 'rel' => 1],
                    ['label' => 'Назад', 'next' => 'root', 'rel' => 0],
                ],
            ],
            'radio' => [
                'text'    => '«Помехи. Но иногда — голоса. Кто-то на севере всё ещё передаёт координаты, раз за разом, одни и те же. Живые так не делают. Не ходи туда без нужды.»',
                'options' => [
                    ['label' => 'Спасибо за совет', 'next' => 'end', 'rel' => 3],
                    ['label' => 'Бред выжившего из ума', 'next' => 'end', 'rel' => -5],
                ],
            ],
            'purpose' => [
                'text'    => '«Жду сигнал. Тот самый — что всё это кончилось. Может, его и нет. Но без ожидания пустошь сжирает человека быстрее пули.»',
                'options' => [
                    ['label' => 'Удачи тебе, Корвин', 'next' => 'end', 'rel' => 3],
                    ['label' => 'Назад', 'next' => 'root', 'rel' => 0],
                ],
            ],
        ];
        foreach ($nodes as $key => $n) {
            if (! empty($this->db->table('npc_dialogue_nodes')->where('npc_id', $npcId)->where('node_key', $key)->get()->getRowArray())) {
                continue;
            }
            $this->db->table('npc_dialogue_nodes')->insert([
                'npc_id'     => $npcId,
                'node_key'   => $key,
                'text_ru'    => $n['text'],
                'options'    => json_encode($n['options'], JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function down(): void
    {
        $row = $this->db->table('npcs')->where('npc_name_en', 'RadioOperatorKorvin')->get()->getRowArray();
        if (! empty($row)) {
            $id = (int) $row['id'];
            $this->db->table('npc_dialogue_nodes')->where('npc_id', $id)->delete();
            $this->db->table('npc_dialogues')->where('npc_id', $id)->delete();
            $this->db->table('npcs')->where('id', $id)->delete();
        }
    }
}

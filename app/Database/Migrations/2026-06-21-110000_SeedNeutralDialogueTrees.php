<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Phase 6 — ветвящиеся деревья диалога для рядовых нейтралов.
 *
 * Странник/Мусорщик/Отшельник получают полноценные деревья (npc_dialogue_nodes):
 * лор-ветки (+rel) · «Есть работа?» (act-quest, хук ADR-088) · торговля (act-trade) ·
 * РИСКОВАННАЯ провокация (act-provoke, резко -rel → бой/побег — «тонкая грань») ·
 * gated-дружелюбная ветка (gate=acquaintance раскрывает лор/наводку). Тон — скупой
 * постапок (ADR-062). Корвину добавляется реплика-провокация. node_key без '_'.
 * Idempotent (skip существующих узлов). Контент-only (таблица KEEP, WipeManifest не трогаем).
 */
class SeedNeutralDialogueTrees extends Migration
{
    public function up(): void
    {
        $trees = [
            'WastelandWanderer' => [
                'root' => [
                    'text'    => 'Странник останавливается, не снимая руки с лямки мешка. «Чего тебе? Я тут не задержусь.»',
                    'options' => [
                        ['label' => 'Кто ты такой?', 'next' => 'about', 'rel' => 2],
                        ['label' => 'Что видел в пути?', 'next' => 'roads', 'rel' => 2],
                        ['label' => 'Есть работа?', 'next' => 'act-quest', 'rel' => 1],
                        ['label' => 'Не поделишься припасами?', 'next' => 'act-trade', 'rel' => 0],
                        ['label' => 'Идём вместе — ты знаешь тропы.', 'next' => 'trust', 'rel' => 3, 'gate' => 'acquaintance'],
                        ['label' => '🗡 Выворачивай мешок, живо.', 'next' => 'act-provoke', 'rel' => -30],
                        ['label' => 'Иди своей дорогой.', 'next' => 'end', 'rel' => 0],
                    ],
                ],
                'about' => [
                    'text'    => '«Иду, пока ноги несут. Осел — значит мёртв, рано или поздно. Пустошь не любит корней.»',
                    'options' => [
                        ['label' => 'Куда держишь путь?', 'next' => 'roads', 'rel' => 1],
                        ['label' => 'Назад', 'next' => 'root', 'rel' => 0],
                    ],
                ],
                'roads' => [
                    'text'    => '«На юге тише — там новички жмутся к берегу. На севере кости да ветер. Не суйся туда без причины.»',
                    'options' => [
                        ['label' => 'Спасибо, учту.', 'next' => 'end', 'rel' => 3],
                        ['label' => 'Назад', 'next' => 'root', 'rel' => 0],
                    ],
                ],
                'trust' => [
                    'text'    => '«Хех. Мало кто доходит до того, чтоб я заговорил без нужды. Держи на заметку: у старой водокачки к востоку бывает, что-то лежит.»',
                    'options' => [
                        ['label' => 'Благодарю.', 'next' => 'end', 'rel' => 3],
                    ],
                ],
            ],

            'ScrapScavenger' => [
                'root' => [
                    'text'    => 'Мусорщик ворошит хлам, не поднимая глаз. «Ходишь тут... Чего надо? Только не трогай моё.»',
                    'options' => [
                        ['label' => 'Что собираешь?', 'next' => 'about', 'rel' => 2],
                        ['label' => 'Есть работа?', 'next' => 'act-quest', 'rel' => 1],
                        ['label' => 'Торгуем?', 'next' => 'act-trade', 'rel' => 0],
                        ['label' => '🗡 Это всё теперь моё.', 'next' => 'act-provoke', 'rel' => -30],
                        ['label' => 'Бывай.', 'next' => 'end', 'rel' => 0],
                    ],
                ],
                'about' => [
                    'text'    => '«Железо, провода — всё, что ещё на что-то годится. Один выбросил, другой выжил. Так и крутимся.»',
                    'options' => [
                        ['label' => 'Нашёл что ценное?', 'next' => 'loot', 'rel' => 1],
                        ['label' => 'Назад', 'next' => 'root', 'rel' => 0],
                    ],
                ],
                'loot' => [
                    'text'    => '«Ценное? Ха. Ценное я первому встречному не показываю.»',
                    'options' => [
                        ['label' => 'Я не первый встречный.', 'next' => 'loottrust', 'rel' => 0, 'gate' => 'acquaintance'],
                        ['label' => 'Справедливо.', 'next' => 'end', 'rel' => 2],
                    ],
                ],
                'loottrust' => [
                    'text'    => '«...Ладно. К северу разбитый грузовик, туда не каждый сунется. Там я брал хорошее. Только молчи.»',
                    'options' => [
                        ['label' => 'Молчу.', 'next' => 'end', 'rel' => 3],
                    ],
                ],
            ],

            'SilentHermit' => [
                'root' => [
                    'text'    => 'Отшельник долго смотрит, прежде чем заговорить. «...Ты не похож на тех, кто приходит за бедой. Или похож. Говори.»',
                    'options' => [
                        ['label' => 'Почему ты один?', 'next' => 'about', 'rel' => 2],
                        ['label' => 'Есть работа?', 'next' => 'act-quest', 'rel' => 1],
                        ['label' => 'Мне нужна не нажива, а понимание.', 'next' => 'deep', 'rel' => 2, 'gate' => 'acquaintance'],
                        ['label' => '🗡 Старик, отдай что есть.', 'next' => 'act-provoke', 'rel' => -30],
                        ['label' => 'Не буду мешать.', 'next' => 'end', 'rel' => 1],
                    ],
                ],
                'about' => [
                    'text'    => '«Люди — это шум. Шум привлекает беду. Здесь, в тишине, я протяну дольше, чем за любой стеной.»',
                    'options' => [
                        ['label' => 'В чём твоя мудрость?', 'next' => 'wisdom', 'rel' => 1],
                        ['label' => 'Назад', 'next' => 'root', 'rel' => 0],
                    ],
                ],
                'wisdom' => [
                    'text'    => '«Совет? Жадность в пустоши убивает быстрее голода. Бери ровно столько, сколько унесёшь, и ни крохой больше.»',
                    'options' => [
                        ['label' => 'Запомню.', 'next' => 'end', 'rel' => 3],
                        ['label' => 'Пустые слова.', 'next' => 'end', 'rel' => -4],
                    ],
                ],
                'deep' => [
                    'text'    => '«...Редкие слова. На юго-западе, у солёного озера, есть место, где земля ещё родит. Тем, кто умеет ждать.»',
                    'options' => [
                        ['label' => 'Благодарю, отец.', 'next' => 'end', 'rel' => 3],
                    ],
                ],
            ],
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($trees as $nameEn => $nodes) {
            $npc = $this->db->table('npcs')->where('npc_name_en', $nameEn)->get()->getRowArray();
            if (empty($npc)) {
                continue;
            }
            $npcId = (int) $npc['id'];
            foreach ($nodes as $key => $n) {
                if (! empty($this->db->table('npc_dialogue_nodes')->where('npc_id', $npcId)->where('node_key', $key)->get()->getRowArray())) {
                    continue;
                }
                $this->db->table('npc_dialogue_nodes')->insert([
                    'npc_id'     => $npcId,
                    'node_key'   => $key,
                    'text_ru'    => $n['text'],
                    'options'    => json_encode($n['options'], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Корвину (named) — добавить реплику-провокацию в root, если её ещё нет.
        $korvin = $this->db->table('npcs')->where('npc_name_en', 'RadioOperatorKorvin')->get()->getRowArray();
        if (! empty($korvin)) {
            $kid  = (int) $korvin['id'];
            $rootRow = $this->db->table('npc_dialogue_nodes')->where('npc_id', $kid)->where('node_key', 'root')->get()->getRowArray();
            if (! empty($rootRow) && is_string($rootRow['options'] ?? null) && ! str_contains($rootRow['options'], 'act-provoke')) {
                $opts = json_decode($rootRow['options'], true);
                if (is_array($opts)) {
                    // вставить провокацию перед последней опцией («Молча уйти»)
                    array_splice($opts, max(0, count($opts) - 1), 0, [
                        ['label' => '🗡 Хватит бредить, отдай рацию.', 'next' => 'act-provoke', 'rel' => -30],
                    ]);
                    $this->db->table('npc_dialogue_nodes')->where('id', (int) $rootRow['id'])->update([
                        'options'    => json_encode($opts, JSON_UNESCAPED_UNICODE),
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $names = ['WastelandWanderer', 'ScrapScavenger', 'SilentHermit'];
        foreach ($this->db->table('npcs')->whereIn('npc_name_en', $names)->get()->getResultArray() as $row) {
            $this->db->table('npc_dialogue_nodes')->where('npc_id', (int) $row['id'])->delete();
        }
        // Корвина-провокацию откатывать не будем (косметика, idempotent-guard не даст дублей).
    }
}

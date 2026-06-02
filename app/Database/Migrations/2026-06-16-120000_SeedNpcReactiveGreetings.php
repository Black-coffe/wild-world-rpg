<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Фаза 2 — реактивные приветствия нейтралов (greeting_hostile / greeting_friendly).
 *
 * Используются NpcInteractionService::greetingFor() при attitude≠neutral (Фаза 2 reactivity).
 * Поиск npc_id по npc_name_en (id может отличаться между средами). Idempotent. Скупой постапок.
 */
class SeedNpcReactiveGreetings extends Migration
{
    public function up(): void
    {
        $byName = [
            'WastelandWanderer' => [
                'greeting_hostile'  => 'Странник узнаёт тебя. «Опять ты.» — рука уже на рукояти ножа. Слова кончились.',
                'greeting_friendly' => 'Странник чуть расслабляет плечи. «А, это ты. Ну, здравствуй. С тобой хоть говорить можно.»',
            ],
            'ScrapScavenger' => [
                'greeting_hostile'  => 'Мусорщик шипит и пятится, закрывая мешок телом. «Вор! Опять пришёл грабить?!»',
                'greeting_friendly' => 'Мусорщик кивает почти приветливо. «А, знакомое лицо. Ты не из тех, кто отбирает. Уважаю.»',
            ],
            'SilentHermit' => [
                'greeting_hostile'  => 'Отшельник медленно встаёт, опираясь на посох. «Ты приносишь беду. Уходи, пока цел.»',
                'greeting_friendly' => 'Отшельник кивает на место у костра. «Снова ты. Садись. С тобой пустошь чуть менее холодна.»',
            ],
        ];

        foreach ($byName as $nameEn => $greetings) {
            $npc = $this->db->table('npcs')->where('npc_name_en', $nameEn)->get()->getRowArray();
            if (empty($npc)) {
                continue;
            }
            $npcId = (int) $npc['id'];
            foreach ($greetings as $type => $text) {
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
        $this->db->table('npc_dialogues')
            ->whereIn('dialogue_type', ['greeting_hostile', 'greeting_friendly'])
            ->delete();
    }
}

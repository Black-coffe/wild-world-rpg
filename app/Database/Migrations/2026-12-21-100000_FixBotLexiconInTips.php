<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W-REG.2 (cold-pass F7) — бот-лексика в советах, которые уходят и в веб.
 *
 * Советы рассылаются и в бот, и в `/play` (мост ADR-189), а пять из них звали кнопку «в нижнем
 * меню» и считали «шесть кнопок» (в доке `/play` их восемь). Код переведён на одну фразу для
 * обоих клиентов — «внизу экрана»; эта миграция чинит то, что лежит строками в `game_tips`.
 * Состав сверен SELECT'ом на testbot 2026-10-26. «/команды» не трогаем: они работают и из
 * поля ввода `/play` (решение владельца, `docs/specs/w-reg-bot-lexicon/brief.md`).
 *
 * Идемпотентна: замена адресная (по `title_en` и точному старому фрагменту), повторный прогон
 * ничего не меняет. Таблица уже в WipeManifest (`game_tips` = KEEP).
 */
class FixBotLexiconInTips extends Migration
{
    /** Советы: title_en → колонка → [что искать, на что заменить]. */
    private const REPLACEMENTS = [
        'FirstShelter' => [
            'content' => ['*«{{menu:base}}»* в нижнем меню →', '*«{{menu:base}}»* внизу экрана →'],
        ],
        'WorldHubNav' => [
            'content' => ['*«🌍 Мир»* в нижнем меню (или команду /go)', '*«🌍 Мир»* внизу экрана (или команду /go)'],
        ],
        'TasksHubNav' => [
            'content' => ['*«📋 Дела»* в нижнем меню (или команду /tasks)', '*«📋 Дела»* внизу экрана (или команду /tasks)'],
        ],
        'BattleJournal' => [
            'content' => ['*⚔️ Бои* в нижнем меню', '*⚔️ Бои* внизу экрана'],
        ],
        'NavGridFinal' => [
            'content'  => ['🧭 *Шесть кнопок внизу — это шесть мест, а не шесть команд.*', '🧭 *Кнопки внизу экрана — это места, а не команды.*'],
            'title_ru' => ['🧭 Шесть кнопок внизу — шесть мест', '🧭 Кнопки внизу — это места'],
        ],
    ];

    public function up(): void
    {
        $this->apply(false);
    }

    public function down(): void
    {
        $this->apply(true);
    }

    private function apply(bool $reverse): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::REPLACEMENTS as $titleEn => $columns) {
            $row = $this->db->table('game_tips')
                ->where('title_en', $titleEn)
                ->get()
                ->getRowArray();

            if (! is_array($row)) {
                continue;
            }

            $update = [];
            foreach ($columns as $column => [$old, $new]) {
                [$from, $to] = $reverse ? [$new, $old] : [$old, $new];
                $value = $row[$column] ?? null;
                if (is_string($value) && str_contains($value, $from)) {
                    $update[$column] = str_replace($from, $to, $value);
                }
            }

            if ($update === []) {
                continue; // уже поправлено — идемпотентность
            }

            $update['updated_at'] = $now;
            $this->db->table('game_tips')
                ->where('title_en', $titleEn)
                ->update($update);
        }
    }
}

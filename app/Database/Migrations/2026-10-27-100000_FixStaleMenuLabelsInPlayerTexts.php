<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Инцидент 2026-07-24 — тексты звали кнопки, которых в меню нет.
 *
 * После ADR-150 (`navigation.final_grid.enabled=1` на проде) нижнее меню стало
 * `[🌍 Мир · 🧑 Я · 🔨 Крафт]/[🏠 База · 📋 Дела · ⚙️ Ещё]`, но player-facing тексты
 * продолжали звать «Перс», «Карта» и «🗺 Карту». Код уже переведён на единый источник
 * меток ({@see \App\Services\Telegram\BotMenuService::menuLabel()}); эта миграция чинит
 * то, что лежит СТРОКАМИ В БД и потому кодом не лечится:
 *
 *  1. **Советы** (`game_tips`, 4 штуки) — переводим на плейсхолдеры `{{menu:<группа>}}`,
 *     которые {@see \App\Services\Player\TipService::applyMenuLabels()} подставляет в момент
 *     показа. Это снимает класс целиком: текст переживёт любую следующую смену каркаса.
 *  2. **Шаги обучающей цепочки** (`quests.description` / `quest_steps`) — их сид персистит
 *     текст, следовать за killswitch'ами он не может, поэтому переписываем на формулировки
 *     БЕЗ названий кнопок («открой крафт-хаб», «открой экран базы»), синхронно с
 *     {@see \App\Services\Onboarding\OnboardingChainCatalog}.
 *
 * Идемпотентна: каждое обновление адресное (по `title_en` / точному старому тексту) и
 * повторный прогон ничего не меняет. Таблицы уже классифицированы в WipeManifest
 * (`game_tips` = KEEP, `quests` = KEEP) — новых таблиц не создаём.
 */
class FixStaleMenuLabelsInPlayerTexts extends Migration
{
    /** Советы: title_en → [что искать, на что заменить]. */
    private const TIP_REPLACEMENTS = [
        'GuideReplayOnboarding' => ['«Перс»', '«{{menu:me}}»'],
        'TributeDominance'      => ['«Перс»', '«{{menu:me}}»'],
        'EquipGear'             => ['«Перс»', '«{{menu:me}}»'],
        'LivingIsland'          => ['«Карта»', '«{{menu:world}}»'],
    ];

    /** Квест-описания: точный старый текст → новый (без названий кнопок). */
    private const QUEST_DESCRIPTIONS = [
        'Пустошь не изучить, стоя на месте. Открой движение (кнопка «Двигаться») и жми стрелку направления — переместись на 3 новые клетки. Для дальних переходов есть «Поход». Каждый шаг открывает кусочек карты вокруг.'
            => 'Пустошь не изучить, стоя на месте. Открой компас ходьбы и жми стрелку направления — переместись на 3 новые клетки. Для дальних переходов есть «Поход». Каждый шаг открывает кусочек карты вокруг.',
        'Выживший умеет делать вещи из хлама. Скрафти любой предмет — открой «Крафт» в нижнем меню и собери что-нибудь простое.'
            => 'Выживший умеет делать вещи из хлама. Скрафти любой предмет — открой крафт-хаб и собери что-нибудь простое.',
        'Дом — половина выживания. Разбей свой лагерь: открой «База» в нижнем меню и нажми «Разбить лагерь» на подходящей клетке.'
            => 'Дом — половина выживания. Разбей свой лагерь: открой экран базы и нажми «Разбить лагерь» на подходящей клетке.',
        'Открой экран своей базы — нажми кнопку «База» в нижнем меню. Там живут все твои постройки и оборона; отсюда же строятся новые.'
            => 'Открой экран своей базы — там живут все твои постройки и оборона; отсюда же строятся новые.',
        'Излишки — это золото. Продай любые ресурсы: «⚙️ Ещё» → «Магазин» → «Продать ресы». Золото пригодится на постройки, ремонт и налоги.'
            => 'Излишки — это золото. Продай любые ресурсы в Магазине → «Продать ресы». Золото пригодится на постройки, ремонт и налоги.',
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 1. Советы → плейсхолдеры (адресно, только если старая метка ещё в тексте).
        foreach (self::TIP_REPLACEMENTS as $titleEn => [$from, $to]) {
            $row = $this->db->table('game_tips')
                ->where('title_en', $titleEn)
                ->get()
                ->getRowArray();

            if ($row === null || ! isset($row['content']) || ! is_string($row['content'])) {
                continue;
            }
            if (! str_contains($row['content'], $from)) {
                continue; // уже поправлено — идемпотентность
            }

            $this->db->table('game_tips')
                ->where('title_en', $titleEn)
                ->update([
                    'content'    => str_replace($from, $to, $row['content']),
                    'updated_at' => $now,
                ]);
        }

        // 2. Квест-описания обучающей цепочки → формулировки без названий кнопок.
        if ($this->db->tableExists('quests')) {
            foreach (self::QUEST_DESCRIPTIONS as $old => $new) {
                $this->db->table('quests')
                    ->where('description', $old)
                    ->update(['description' => $new]);
            }
        }
    }

    public function down(): void
    {
        // Возвращаем советы к literal-меткам (плейсхолдер → метка финальной сетки).
        foreach (self::TIP_REPLACEMENTS as $titleEn => [$from, $to]) {
            $row = $this->db->table('game_tips')
                ->where('title_en', $titleEn)
                ->get()
                ->getRowArray();

            if ($row === null || ! isset($row['content']) || ! is_string($row['content'])) {
                continue;
            }
            if (! str_contains($row['content'], $to)) {
                continue;
            }

            $this->db->table('game_tips')
                ->where('title_en', $titleEn)
                ->update(['content' => str_replace($to, $from, $row['content'])]);
        }

        if ($this->db->tableExists('quests')) {
            foreach (self::QUEST_DESCRIPTIONS as $old => $new) {
                $this->db->table('quests')
                    ->where('description', $new)
                    ->update(['description' => $old]);
            }
        }
    }
}

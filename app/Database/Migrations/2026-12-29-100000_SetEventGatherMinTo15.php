<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-200 (спека sudden-death-1125, S5, ask 10) — порог добычи в биоме события `health.event_gather_min`: 15
 * вместо 30. Слово владельца после preprod-смоука 25.11: «15 (Рекомендую)» — Ураган покрывает все 9 биомов, и с
 * порогом 30 каждый ниже 30 HP не мог добывать нигде.
 *
 * Дефолт (Reset-to-default) и тексты ключа обновляются всегда; значение — только если там ещё старый дефолт 30
 * (подкрученное в админке не трогаем). `down()` симметричен: 15 → 30 только если значение всё ещё 15.
 * game_settings = KEEP (WipeManifest не трогаем). Идемпотентно.
 */
class SetEventGatherMinTo15 extends Migration
{
    public const KEY = 'health.event_gather_min';

    public const OLD_VALUE = 30.0;

    public const NEW_VALUE = 15.0;

    /** @var array<string, string> */
    public const NEW_META = [
        'default_value_text' => '15',
        'recommended_min'    => '10',
        'recommended_max'    => '40',
        'rationale_text'     => 'Цена пола (слово владельца «Прервать добычу в биоме»): с низким здоровьем в биоме активного события с уроном новая добыча не начинается. 15 — слово владельца после preprod-смоука 25.11: с 30 Ураган (все 9 биомов) запрещал добычу везде каждому ниже 30 HP. Событие бьёт 3–10 HP примерно каждую третью минуту — 15 HP хватает на короткую добычу до пола.',
        'effect_text'        => 'Порог в `GatherAction` (бот и /play): здоровье ниже — отказ «в биоме идёт событие, ранит; иди на базу / подлечись». В биомах без такого события добыча стартует как раньше. На клетке своей базы порог не действует, если событие базу не бьёт (`state_modifier.base_idle` 0: Толчки, Ураган); Эпидемия и Лихорадка бьют и на базе — там порог остаётся.',
        'above_effect_text'  => 'При 30 и выше глобальное событие (Ураган — все 9 биомов) запрещает добычу везде каждому раненому — игроки сидят без дела часами, пока лечатся; так было на preprod до 25.11.',
        'below_effect_text'  => 'При 5–10 добыча стартует почти с пола и тут же прерывается полом — игрок тратит запуск впустую и видит два отказа подряд вместо одного понятного.',
    ];

    /** @var array<string, string> тексты посева `SeedPassiveFloorSettings` — для `down()` */
    public const OLD_META = [
        'default_value_text' => '30',
        'recommended_min'    => '15',
        'recommended_max'    => '60',
        'rationale_text'     => 'Цена пола (слово владельца «Прервать добычу в биоме»): с низким здоровьем в биоме активного события с уроном новая добыча не начинается. Событие бьёт 3–10 HP примерно каждую третью минуту — 30 HP хватает на короткую добычу, после которой персонаж ещё не на полу.',
        'effect_text'        => 'Порог в `GatherAction` (бот и /play): здоровье ниже — отказ «в биоме идёт событие, ранит; иди на базу / подлечись». В биомах без такого события добыча стартует как раньше.',
        'above_effect_text'  => 'При 60–80 в биом события почти не зайти добывать даже слегка раненым — событие превращается в запрет на биом, игроки сидят без дела часами.',
        'below_effect_text'  => 'При 5–10 добыча стартует почти с пола и тут же прерывается полом — игрок тратит запуск впустую и видит два отказа подряд вместо одного понятного.',
    ];

    public function up(): void
    {
        $this->apply(self::NEW_META, self::OLD_VALUE, self::NEW_VALUE);
    }

    public function down(): void
    {
        $this->apply(self::OLD_META, self::NEW_VALUE, self::OLD_VALUE);
    }

    /** @param array<string, string> $meta */
    private function apply(array $meta, float $from, float $to): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('game_settings')
            ->where('setting_key', self::KEY)
            ->update($meta + ['updated_at' => $now]);

        $this->db->table('game_settings')
            ->where('setting_key', self::KEY)
            ->where('value_float', $from)
            ->update(['value_float' => $to, 'updated_at' => $now]);
    }
}

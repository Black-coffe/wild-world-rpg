<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-117 (E17) — настройки уровневого tier'инга воздействия событий.
 *
 * 5 ключей категории world. killswitch default OFF → harmFactor=1.0 = byte-identical (события
 * как раньше). Seed game_settings (KEEP). Idempotent. ADR-024: rationale/effect/above/below NOT NULL.
 */
class Adr117EventLevelTierSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            [
                'setting_key'        => 'world.events.level_tier_enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch уровневого tier-модификатора вреда событий (E17, ADR-117). ON: вредные события (урон HP / потеря ресурсов / gather-дебафф) мягче новичкам (обучают) и жёстче ветеранам (испытывают). OFF: harmFactor=1.0 — события одинаковы для всех (старое поведение, byte-identical).',
                'effect_text'        => 'ON: магнитуда вредных эффектов × harmFactor(level). OFF: ×1.0.',
                'above_effect_text'  => 'true: новички защищены от несправедливого урона, ветеранам события — вызов. Включать после Tier-3.',
                'below_effect_text'  => 'false: tier выключен (события плоские по уровню).',
                'recommended_min'    => null, 'recommended_max' => null, 'hard_min' => null, 'hard_max' => null,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'world.events.tier_newbie_max_level',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 9,
                'default_value_text' => '9',
                'rationale_text'     => 'Верхняя граница «новичкового» бэнда tier. level ≤ это → tier_newbie_factor (сниженный вред). 9 = совпадает с защищённой зоной L1-9 (CLAUDE.md §новичок).',
                'effect_text'        => 'Игрок level ≤ это получает tier_newbie_factor к вреду событий.',
                'above_effect_text'  => 'Выше: больше игроков в «щадящем» бэнде.',
                'below_effect_text'  => 'Ниже: меньше защищённых новичков.',
                'recommended_min'    => '5', 'recommended_max' => '15', 'hard_min' => '1', 'hard_max' => '50',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'world.events.tier_newbie_factor',
                'category'           => 'world',
                'value_type'         => 'float',
                'value_float'        => 0.5,
                'default_value_text' => '0.5',
                'rationale_text'     => 'Множитель вреда событий для новичков (≤ newbie_max). 0.5 = половина урона/потерь — события скорее обучают, чем убивают (мягкий tier, защита L1-9).',
                'effect_text'        => 'Вред вредных событий новичку × это.',
                'above_effect_text'  => 'Выше (ближе к 1.0): события жёстче для новичков.',
                'below_effect_text'  => 'Ниже: события почти безвредны новичку (риск обесценивания).',
                'recommended_min'    => '0.2', 'recommended_max' => '0.9', 'hard_min' => '0.0', 'hard_max' => '1.0',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'world.events.tier_veteran_min_level',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 25,
                'default_value_text' => '25',
                'rationale_text'     => 'Нижняя граница «ветеранского» бэнда tier. level ≥ это → tier_veteran_factor (повышенный вред). 25 = порог средней полосы (пояс E15/E16).',
                'effect_text'        => 'Игрок level ≥ это получает tier_veteran_factor к вреду событий.',
                'above_effect_text'  => 'Выше: меньше игроков в «испытательном» бэнде.',
                'below_effect_text'  => 'Ниже: больше игроков получают усиленные события.',
                'recommended_min'    => '15', 'recommended_max' => '40', 'hard_min' => '1', 'hard_max' => '100',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'world.events.tier_veteran_factor',
                'category'           => 'world',
                'value_type'         => 'float',
                'value_float'        => 1.3,
                'default_value_text' => '1.3',
                'rationale_text'     => 'Множитель вреда событий для ветеранов (≥ veteran_min). 1.3 = +30% — события ощутимы для прокачанных (испытывают), не неощутимы. Умеренно, чтобы не наказывать.',
                'effect_text'        => 'Вред вредных событий ветерану × это.',
                'above_effect_text'  => 'Выше (напр. 2.0): события — серьёзная угроза старшим (следить за балансом HP/ресурсов).',
                'below_effect_text'  => 'Ниже (=1.0): для ветеранов как обычно.',
                'recommended_min'    => '1.0', 'recommended_max' => '2.5', 'hard_min' => '1.0', 'hard_max' => '10.0',
                'created_at' => $now, 'updated_at' => $now,
            ],
        ];

        foreach ($rows as $row) {
            if ($this->db->table('game_settings')->where('setting_key', $row['setting_key'])->countAllResults() === 0) {
                $this->db->table('game_settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'world.events.level_tier_enabled', 'world.events.tier_newbie_max_level',
            'world.events.tier_newbie_factor', 'world.events.tier_veteran_min_level',
            'world.events.tier_veteran_factor',
        ])->delete();
    }
}

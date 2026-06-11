<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-114 (E14) — грандмастер-band модернизации: расширение cap 25%→50% для L40+ через
 * редкий компонент-синк («Древние реликвии»).
 *
 * 6 ключей категории craft. killswitch grandmaster_enabled default OFF → стандартный cap (25%)
 * для всех = byte-identical поведение. Seed game_settings (KEEP). Idempotent (setting_key).
 * ADR-024: rationale/effect/above/below NOT NULL.
 */
class Adr114GrandmasterModifierSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $rows = [
            [
                'setting_key'        => 'craft.modifier.grandmaster_enabled',
                'category'           => 'craft',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch грандмастер-band модернизации (E14, ADR-113 гейт L40). Расширяет потолок модернизации со стандартных 25% до grandmaster_max (50%) для игроков level≥grandmaster_level. Грандмастер-тиры стоят дороже золота И требуют редкий компонент (Древние реликвии) = синк против инфляции у L25+ (10-16M золота). default=false → cap остаётся 25% у всех = byte-identical (боевой путь не меняется).',
                'effect_text'        => 'ON: L40+ игрок может качать модернизацию выше 25% до grandmaster_max, платя grandmaster_resource + золото×grandmaster_gold_mult за тиры >25%. OFF: cap 25% (старое поведение).',
                'above_effect_text'  => 'true: ветераны получают горизонтальную цель (25%→50% урона/брони) — закрывает «потолок модернизации» (2 игрока уже на 25%). Включать после Tier-3 и эконом-проверки.',
                'below_effect_text'  => 'false: грандмастер недоступен, cap 25%. Emergency-off.',
                'recommended_min'    => null, 'recommended_max' => null, 'hard_min' => null, 'hard_max' => null,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'craft.modifier.grandmaster_level',
                'category'           => 'craft',
                'value_type'         => 'int',
                'value_int'          => 40,
                'default_value_text' => '40',
                'rationale_text'     => 'Минимальный уровень для грандмастер-тиров модернизации (гейт L40 по лестнице ADR-113). До него cap=25%. 40 — середина пути к T4, ветеранская планка.',
                'effect_text'        => 'Игрок level≥это значение видит грандмастер-тиры (>25%); ниже — стандартный cap 25%.',
                'above_effect_text'  => 'Выше: грандмастер доступен позже (строже гейт).',
                'below_effect_text'  => 'Ниже: раньше — но обесценивает «грандмастер» как ветеранскую веху.',
                'recommended_min'    => '25', 'recommended_max' => '60', 'hard_min' => '1', 'hard_max' => '100',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'craft.modifier.grandmaster_max_bonus_pct',
                'category'           => 'craft',
                'value_type'         => 'int',
                'value_int'          => 50,
                'default_value_text' => '50',
                'rationale_text'     => 'Потолок грандмастер-модернизации (накопленный bonus_pct) для L40+. 50% = вдвое выше стандартных 25%, заметная горизонтальная прогрессия T3+ до прихода T4 (этап II). Шаг тира — общий craft.modifier.bonus_pct (5%) → тиры 6-10.',
                'effect_text'        => 'Максимум, до которого L40+ может довести +%урона/брони через грандмастер-тиры.',
                'above_effect_text'  => 'Выше (напр. 75): сильнее ветеранский разрыв в бою — следить за PvP-балансом.',
                'below_effect_text'  => 'Ниже (=25): грандмастер не даёт прироста (равен стандарту).',
                'recommended_min'    => '30', 'recommended_max' => '75', 'hard_min' => '25', 'hard_max' => '200',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'craft.modifier.grandmaster_resource_name',
                'category'           => 'craft',
                'value_type'         => 'string',
                'value_string'       => 'Древние реликвии',
                'default_value_text' => 'Древние реликвии',
                'rationale_text'     => 'Редкий компонент для грандмастер-тиров (>25%) — синк против инфляции. «Древние реликвии» (rarity 1, L20) тематичны (реликвии старого мира толкают снаряжение за обычные пределы) и редки. До прихода элиток/севера (E15/E16) это существующий редкий ресурс; позже добавится их лут.',
                'effect_text'        => 'Грандмастер-тиры списывают этот ресурс (по grandmaster_resource_qty×тир) вместо обычных Минералов.',
                'above_effect_text'  => '—',
                'below_effect_text'  => '—',
                'recommended_min'    => null, 'recommended_max' => null, 'hard_min' => null, 'hard_max' => null,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'craft.modifier.grandmaster_resource_qty',
                'category'           => 'craft',
                'value_type'         => 'int',
                'value_int'          => 2,
                'default_value_text' => '2',
                'rationale_text'     => 'Базовое кол-во редкого компонента за грандмастер-тир (×тир). 2×тир (тир 6-10 → 12-20 реликвий за тир) — ощутимый, но достижимый синк для L40+.',
                'effect_text'        => 'Списываемое кол-во grandmaster_resource = это значение × номер тира.',
                'above_effect_text'  => 'Выше: жёстче синк (дороже грандмастер).',
                'below_effect_text'  => 'Ниже: дешевле, слабее анти-инфляция.',
                'recommended_min'    => '1', 'recommended_max' => '10', 'hard_min' => '0', 'hard_max' => '1000',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'craft.modifier.grandmaster_gold_mult',
                'category'           => 'craft',
                'value_type'         => 'float',
                'value_float'        => 2.0,
                'default_value_text' => '2.0',
                'rationale_text'     => 'Множитель золото-цены грандмастер-тиров (поверх обычной цены×тир). 2.0 = грандмастер вдвое дороже золота — основной gold-синк для богатых L25+ (10-16M).',
                'effect_text'        => 'Золото за грандмастер-тир = craft.modifier.gold_cost × тир × это значение.',
                'above_effect_text'  => 'Выше: сильнее gold-синк.',
                'below_effect_text'  => 'Ниже (1.0): грандмастер по обычной цене — слабее анти-инфляция.',
                'recommended_min'    => '1.0', 'recommended_max' => '5.0', 'hard_min' => '0.1', 'hard_max' => '100.0',
                'created_at' => $now, 'updated_at' => $now,
            ],
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->countAllResults();
            if ($exists === 0) {
                $this->db->table('game_settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'craft.modifier.grandmaster_enabled', 'craft.modifier.grandmaster_level',
            'craft.modifier.grandmaster_max_bonus_pct', 'craft.modifier.grandmaster_resource_name',
            'craft.modifier.grandmaster_resource_qty', 'craft.modifier.grandmaster_gold_mult',
        ])->delete();
    }
}

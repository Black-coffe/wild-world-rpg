<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Спрос-01 — гейт уровня на покупке ресурсов в магазине.
 *
 * Аудит 2026-12-05 (прод, 30 дней firehose): куплено 10 разных ресурсов из 80, все id 1–10.
 * Витрина при этом показывает все редкости целиком, и сделка не смотрела ни на `is_tradeable`,
 * ни на `level_required`: персонаж 1 уровня мог купить «Корону подземного короля»
 * (`level_required=100`), а семена (`is_tradeable=0`, `buy_price=0.00`) отдавались даром.
 *
 * Фильтр `is_tradeable` — это исправление дефекта, килсвитча ему не полагается. Гейт уровня
 * меняет поведение для живых игроков, поэтому живёт под этим ключом.
 *
 * `game_settings` = KEEP (WipeManifest не трогаем). Идемпотентно по `setting_key`.
 */
class SeedBuyLevelGateGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'economy.shop.buy_level_gate_enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Торговец не продаёт ресурс персонажу ниже `resources.level_required`. true (default) — гейт включён: покупка отвечает отказом с точной цифрой нужного уровня. Включён сразу, а не dormant, потому что закрывает обход прогрессии: витрина показывает все десять редкостей любому, и до этого ключа уровень при покупке не проверялся нигде — только при добыче.',
                'effect_text'        => 'ResourceTradeService::buyResource — сравнивает characters.level с resources.level_required и отклоняет сделку до списания золота. Список редкости при этом ресурс по-прежнему показывает (UX-Discoverability: вход виден, отказ объясняет причину).',
                'above_effect_text'  => 'true — прогрессия ресурсов честная: высокоуровневое сырьё нельзя купить в обход уровня, золото перестаёт быть лифтом через контент.',
                'below_effect_text'  => 'false — прежнее поведение: любой ресурс покупается на любом уровне, если хватает золота (персонаж 1 уровня берёт «Корону подземного короля» за 5000).',
            ],
        ];

        $defaults = [
            'category'        => 'resources',
            'value_int'       => null,
            'value_float'     => null,
            'value_bool'      => null,
            'value_string'    => null,
            'recommended_min' => null,
            'recommended_max' => null,
            'hard_min'        => null,
            'hard_max'        => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($defaults, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'economy.shop.buy_level_gate_enabled')->delete();
    }
}

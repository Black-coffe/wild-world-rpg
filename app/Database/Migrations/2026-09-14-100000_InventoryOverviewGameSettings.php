<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Slice 1 (инициатива «ресурс-грамотность») — killswitch экрана «📊 Все мои ресурсы».
 *
 * Единый read-only срез ВСЕХ мест хранения (добыча / крафт / оружие / броня /
 * склад базы / золото) + честный итог по стоимости (включая склад базы, который
 * «Моя экономика» пропускала). Вход — кнопка на хабе «🎒 Инвентарь», видна только
 * при enabled=true. false (default) → DORMANT: кнопки нет, хаб byte-identical.
 * Активация после Tier-3 + решение владельца. game_settings = KEEP (WipeManifest
 * не трогаем). Idempotent по setting_key. Категория world.
 */
class InventoryOverviewGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'inventory.overview.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch экрана «📊 Все мои ресурсы» (ResourceOverviewService/Action). false (default) — DORMANT: кнопка входа на хабе «Инвентарь» не рендерится, экран недоступен, хаб byte-identical. true — игрок видит единый read-only срез всех мест хранения (добыча/крафт/оружие/броня/склад базы/золото) + итог по стоимости. Read-only, ничего не мутирует. Активация после Tier-3 + решение владельца.',
                'effect_text'        => 'InventoryAction добавляет кнопку «📊 Все мои ресурсы» только при этом флаге; ResourceOverviewAction рендерит экран только при нём.',
                'above_effect_text'  => 'true — игроки получают единое окно «где что лежит» (снимает путаницу рюкзак/склад базы, видят полный запас и стоимость). Чисто информационно, без влияния на баланс.',
                'below_effect_text'  => 'false — экрана нет, игрок по-прежнему ходит по 3+ раздельным экранам инвентаря (текущее поведение).',
            ],
        ];

        $defaults = [
            'category'        => 'world',
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
        $this->db->table('game_settings')->where('setting_key', 'inventory.overview.enabled')->delete();
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-150 Слайс 5 — killswitch группы «🔨 Крафт / 🏠 База».
 *
 * Разводит две группы, которые растеряли свои же сущности:
 *  - **ремонт ИНСТРУМЕНТОВ** лежит в неймспейсе `Craft\Repair`, но кнопки на экране Крафта
 *    не было ВООБЩЕ: попасть можно было только из Инвентаря («Крафтовые ресурсы») или из хаба
 *    поселения. 96 тапов у 6 игроков — при 148 тапах у «Инструментов» (20 игроков);
 *  - **склад базы** (`baseStorageList`) — тема Базы, но входа с экрана Базы нет: только
 *    Инвентарь / «Все ресурсы» / карго-дрон / движение. 14 тапов у 9 игроков;
 *  - экран Базы упирался в cap 7±2 (8–9 кнопок), причём две самые опасные операции (снос,
 *    полноценный переезд) висели прямо на нём, рядом с бытовыми.
 *
 * Ремонт ЗДАНИЙ (`repairBuilding_*`, ADR-041) остаётся на Базе — он уже в своей группе.
 *
 * false (default) — DORMANT: оба экрана byte-identical. true — Крафт получает «🪛 Ремонт
 * инструментов», База — «📦 Склад базы», а снос/переезд схлопываются в одну кнопку
 * «⚠️ Снести / переехать» → существующая дверь `DeleteBase` (она уже показывает три варианта
 * с честными числами: −70% ресурсов / −80% крафта при моментальном сносе).
 *
 * game_settings = KEEP (WipeManifest не трогаем). Idempotent по setting_key. Категория world.
 */
class Adr150NavCraftBaseHubGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'navigation.craftbase_hub.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'ADR-150 Слайс 5: killswitch группы «Крафт / База». Аудит 2026-07-09: ремонт инструментов живёт в неймспейсе Craft\Repair, но кнопки на экране Крафта не было вовсе (входы только из Инвентаря и хаба поселения) — 96 тапов у 6 игроков; склад базы — тема Базы, но с экрана Базы недостижим — 14 тапов у 9 игроков; экран Базы упирался в cap 7±2 (8-9 кнопок), а снос и полноценный переезд висели прямо на нём рядом с бытовыми кнопками. false (default) — DORMANT, byte-identical. true — каждая группа забирает своё. Активация после Tier-3 + решение владельца.',
                'effect_text'        => 'Экран Крафта получает кнопку «🪛 Ремонт инструментов» (callback repairToolsList) и строку-пояснение. Экран Базы получает «📦 Склад базы» (baseStorageList), а «❌ Удалить базу» + «🚚 Полноценный переезд» схлопываются в одну кнопку «⚠️ Снести / переехать» → существующая дверь DeleteBase, которая уже показывает три варианта с честными числами. Ремонт зданий не трогаем — он и так на Базе.',
                'above_effect_text'  => 'true — изношенный инструмент чинится из Крафта в один тап (раньше игрок искал ремонт в Инвентаре), склад базы виден с самой базы, необратимые операции убраны с бытового экрана за дверь. Экран Базы возвращается в пределы cap 7±2.',
                'below_effect_text'  => 'false — текущее поведение: ремонт инструментов достижим только из Инвентаря/поселения, склад базы — не с экрана Базы, снос и переезд в один тап с обычной навигацией.',
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
        $this->db->table('game_settings')->where('setting_key', 'navigation.craftbase_hub.enabled')->delete();
    }
}

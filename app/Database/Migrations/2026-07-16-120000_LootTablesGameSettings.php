<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-107 — killswitch движка лут-таблиц (LootTableService).
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Шансы/количества дропа — контент-данные в loot_table_items (как рецепты/NPC),
 * не скалярные balance-ключи. Seed game_settings → WipeManifest не затронут. Идемпотентно.
 */
class LootTablesGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = [
            'setting_key'        => 'combat.loot_tables.enabled',
            'category'           => 'combat',
            'value_type'         => 'bool',
            'value_bool'         => 0,
            'default_value_text' => 'false',
            'rationale_text'     => 'ADR-107: killswitch data-driven лут-таблиц (LootTableService). При победе игрока над NPC с npcs.loot_table_id и строками в loot_table_items выдаётся доп. лут (трофеи боссов ADR-106: rarity-1 ресурсы, золото, шанс легендарного оружия). default=false → dormant: движок no-op, награды только стандартные level-based.',
            'effect_text'        => 'При ON: PvEService после стандартных наград прокатывает лут-таблицу побеждённого NPC (roll per строка: drop_chance% → qty min..max → ресурс/золото/оружие в инвентарь) и показывает трофеи в сообщении боя. Затрагивает оба пути боя (авто-PvE крон + встреча).',
            'above_effect_text'  => 'true: боссы севера дропают уникальные трофеи — экономический смысл охоты на них; рейдерские лут-таблицы (501/502/601) пусты → для обычных NPC ничего не меняется, пока админ не наполнит.',
            'below_effect_text'  => 'false: трофейного лута нет (dormant), победа над боссом даёт только стандартный level-based пакет. Emergency-off без редеплоя.',
            'recommended_min'    => null,
            'recommended_max'    => null,
            'hard_min'           => null,
            'hard_max'           => null,
        ];

        $existing = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
        if (empty($existing)) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'combat.loot_tables.enabled')->delete();
    }
}

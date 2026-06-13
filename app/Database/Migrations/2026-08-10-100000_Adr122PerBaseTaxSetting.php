<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * E23 (ROADMAP-100, ADR-122) — per-base налог: одна недофинансированная база не морозит
 * производство на всех базах игрока.
 *
 * Audit (прод 2026-06-13): TaxCollectionHandler агрегирует `SUM(tax) GROUP BY character_id`
 * и при нехватке золота на ПОЛНЫЙ налог ставит `tax_collection_status='FAILURE'` на ВСЕ
 * постройки персонажа разом (WHERE character_id, без map_cell_id). Производство гейтится
 * статусом ПОСТРОЧНО (HandPumpProductionHandler / GymProductionHandler) → недофинансированная
 * одна база замораживает производство на ВСЕХ базах. Затронуты 2 мультибэйс-игрока из 8 (баг
 * латентный, оба сейчас платёжеспособны), но владелец планирует мир из 10-20 баз на игрока.
 *
 * Решение (Gate-1 владельца): золото — единый кошелёк, налог базы списывается из общего
 * золота, НО статус и реакция на неуплату — per-base. Жадно по возрастанию налога (дешёвые
 * базы платятся первыми → максимум баз продолжают производить; partial-collect на первой
 * неоплатимой → для одно-базового byte-identical со старым агрегатом).
 *
 *  - buildings.tax.per_base_enabled — killswitch (default OFF → старый агрегатный путь,
 *    byte-identical). true → per-base путь. Для одно-базовых игроков ON ≡ OFF.
 *
 * category=buildings (когезия с остальными настройками построек). Idempotent.
 */
class Adr122PerBaseTaxSetting extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = [
            'setting_key'        => 'buildings.tax.per_base_enabled',
            'value_type'         => 'bool',
            'value_bool'         => 0,
            'default_value_text' => 'false',
            'category'           => 'buildings',
            'rationale_text'     => 'Killswitch per-base налога (ADR-122). false (default) — налог агрегируется по игроку и статус ставится на все постройки разом (текущее поведение, byte-identical). true — налог считается/списывается/статусится отдельно по каждой базе (map_cell_id), одна недофинансированная база не морозит производство на других. Для одно-базовых игроков (≈все) ON ≡ OFF.',
            'effect_text'        => 'TaxCollectionHandler::handle. true → ветка collectBuildingTaxPerBase: группировка построек по map_cell_id, оплата из общего золота жадно по возрастанию налога базы, tax_collection_status ставится WHERE character_id AND map_cell_id. Производственные гейты (HandPump/Gym) уже читают статус построчно → морозят только недофинансированную базу.',
            'above_effect_text'  => 'true: налог и заморозка производства независимы по базам — недоплата одной базы не наказывает остальные. Уместно для мультибэйс-игроков.',
            'below_effect_text'  => 'false: мгновенный откат к агрегатному налогу без редеплоя (старое поведение: недоплата → FAILURE на всех постройках игрока).',
            'hard_min'           => '0',
            'hard_max'           => '1',
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
        if (empty($exists)) {
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'buildings.tax.per_base_enabled')->delete();
    }
}

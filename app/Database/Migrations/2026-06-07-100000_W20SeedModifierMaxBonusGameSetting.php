<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W20 (vNext2, Фаза 4, ADR-075) — потолок накапливаемой модернизации (admin-tunable, ADR-024).
 *
 * `craft.modifier.bonus_pct` (W19, =5) теперь трактуется как ШАГ за одну модернизацию; этот ключ —
 * ПОТОЛОК накопленного бонуса. Цена растёт ×тир. Idempotent.
 */
class W20SeedModifierMaxBonusGameSetting extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $row = [
            'setting_key'        => 'craft.modifier.max_bonus_pct',
            'category'           => 'craft',
            'value_type'         => 'int',
            'value_int'          => 25,
            'default_value_text' => '25',
            'rationale_text'     => 'Потолок накопленной модернизации предмета (в процентах). 25 при шаге 5 = 5 тиров (+5/+10/+15/+20/+25). Умеренный максимум: заметный прирост урона/брони, но не доминирует над уровнем/статами. Цена каждого следующего тира растёт (×номер тира) — растущий gold-sink.',
            'effect_text'        => 'ItemModifierService::maxBonusPct — enchant не поднимает bonus_pct выше; на потолке возвращает max_tier.',
            'above_effect_text'  => 'Выше → больше тиров и сильнее предметы; при больших значениях оружие/броня перекрывают вклад статов и уровня (ломает PvE/PvP-баланс).',
            'below_effect_text'  => 'Ниже → меньше тиров, модернизация быстро упирается в потолок (слабее прогрессия и gold-sink).',
            'recommended_min'    => '5',
            'recommended_max'    => '50',
            'hard_min'           => '0',
            'hard_max'           => '100',
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
        $this->db->table('game_settings')->where('setting_key', 'craft.modifier.max_bonus_pct')->delete();
    }
}

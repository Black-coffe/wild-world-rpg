<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * F (аудит бафов, находка S6) — admin-tunable кап силы от Спортзала (анти-creep).
 * constitutional admin-tunable, ADR-024. category=buildings. Idempotent.
 *
 * До F: GymProductionHandler прибавлял силу без потолка → бесконечный stat-creep у
 * долгоживущих чаров. F вводит ЛЕВЕР (cap), но **по умолчанию 0 = выключен** — текущее
 * поведение не меняется (без сюрприз-нерфа живым игрокам). Админ включит кап, когда
 * телеметрия покажет, что creep пора ограничить.
 */
class FSeedGymStrengthCapGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = [
            'setting_key'        => 'building.gym.strength_cap',
            'category'           => 'buildings',
            'value_type'         => 'int',
            'value_int'          => 0,
            'default_value_text' => '0',
            'rationale_text'     => 'Потолок силы, выше которого Спортзал перестаёт её начислять (анти-creep). 0 = кап ВЫКЛЮЧЕН (текущее поведение, без потолка). >0 = Gym не добавляет силу чарам с strength ≥ этого значения (другие источники силы не затрагиваются).',
            'effect_text'        => 'GymProductionHandler: если cap>0 и strength ≥ cap, тик Спортзала пропускает начисление силы этому чару.',
            'above_effect_text'  => 'Чем выше cap, тем дольше Спортзал качает силу (при очень большом — фактически без потолка).',
            'below_effect_text'  => 'При малом cap сильные чары быстро упираются в потолок Gym-прокачки; при 0 — потолка нет вовсе.',
            'recommended_min'    => '0',
            'recommended_max'    => '500',
            'hard_min'           => '0',
            'hard_max'           => '100000',
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
        $this->db->table('game_settings')->where('setting_key', 'building.gym.strength_cap')->delete();
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W21 (ADR-076) — GameSettings killswitch для Housing customisation.
 * Default OFF (dormant) до активации батчем в конце ROADMAP vNext2.
 */
class W21SeedHousingDecorationGameSetting extends Migration
{
    public function up(): void
    {
        $this->db->table('game_settings')->insertBatch([
            [
                'setting_key'        => 'housing.decoration.enabled',
                'value_type'         => 'bool',
                'value_bool'         => false,
                'default_value_text' => 'false',
                'category'           => 'world',
                'rationale_text'     => 'Killswitch для W21 Housing customisation (декор базы: имя лагеря из 12 пресетов + emoji-флаг из 16 вариантов). Default OFF — функции dormant до активации в конце ROADMAP vNext2.',
                'effect_text'        => 'При true — в экране базы появляется кнопка «🎨 Декор»; игрок выбирает имя и флаг лагеря (бесплатно, чисто косметически). Выбранный декор отображается в caption базы и в PvP-атаке (имя базы атакуемого). При false — кнопка скрыта, ранее выбранный декор в БД хранится, но не отображается.',
                'above_effect_text'  => 'n/a — булев ключ',
                'below_effect_text'  => 'n/a — булев ключ',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
            ],
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->where('setting_key', 'housing.decoration.enabled')
            ->delete();
    }
}

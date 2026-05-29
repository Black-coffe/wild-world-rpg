<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W27 (ADR-082) — killswitch переключателя языка интерфейса.
 *
 * Default OFF (dormant): пока локализована лишь часть поверхностей, переключатель
 * скрыт, чтобы не выставлять half-localized UI. Активируется когда покрытие en
 * достаточно (W28+). Плюмбинг (characters.locale + LocaleService + lang()) работает
 * независимо — при активации язык сразу применится к локализованным экранам.
 */
class W27SeedLocaleSwitchGameSetting extends Migration
{
    public function up(): void
    {
        $exists = $this->db->table('game_settings')
            ->where('setting_key', 'i18n.locale_switch.enabled')
            ->countAllResults();
        if ($exists > 0) {
            return;
        }

        $this->db->table('game_settings')->insert([
            'setting_key'        => 'i18n.locale_switch.enabled',
            'value_type'         => 'bool',
            'value_bool'         => false,
            'default_value_text' => 'false',
            'category'           => 'experimental',
            'rationale_text'     => 'Killswitch переключателя языка интерфейса (🌐 Язык/Language в Настройках). Default OFF — пока локализована лишь часть поверхностей; включаем когда en-покрытие достаточно, чтобы не показывать игроку half-localized UI.',
            'effect_text'        => 'При true — в Настройках появляется кнопка переключения ru/en; выбранный язык (characters.locale) применяется ко всем локализованным экранам. При false — кнопка скрыта (плюмбинг работает, но язык не переключить).',
            'above_effect_text'  => 'n/a — булев ключ',
            'below_effect_text'  => 'n/a — булев ключ',
            'recommended_min'    => null,
            'recommended_max'    => null,
            'hard_min'           => null,
            'hard_max'           => null,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->where('setting_key', 'i18n.locale_switch.enabled')
            ->delete();
    }
}

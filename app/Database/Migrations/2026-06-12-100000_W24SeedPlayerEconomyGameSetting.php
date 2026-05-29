<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W24 (ADR-079) — killswitch для «💰 Моя экономика» (персональный экономический срез).
 * Default OFF (dormant) до активации батчем в конце ROADMAP vNext2.
 */
class W24SeedPlayerEconomyGameSetting extends Migration
{
    public function up(): void
    {
        $exists = $this->db->table('game_settings')
            ->where('setting_key', 'economy.player_report.enabled')
            ->countAllResults();
        if ($exists > 0) {
            return;
        }

        $this->db->table('game_settings')->insert([
            'setting_key'        => 'economy.player_report.enabled',
            'value_type'         => 'bool',
            'value_bool'         => false,
            'default_value_text' => 'false',
            'category'           => 'experimental',
            'rationale_text'     => 'Killswitch для W24 «Моя экономика» — персональный snapshot-отчёт (net worth = золото + стоимость ресурсов и крафта, топ-холдинги, сводка торговли). Default OFF — dormant до активации батчем в конце ROADMAP vNext2.',
            'effect_text'        => 'При true — на карточке Перс появляется кнопка «💰 Моя экономика», открывающая честный снимок личной экономики из существующих данных (без исторического ledger). При false — кнопка скрыта.',
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
            ->where('setting_key', 'economy.player_report.enabled')
            ->delete();
    }
}

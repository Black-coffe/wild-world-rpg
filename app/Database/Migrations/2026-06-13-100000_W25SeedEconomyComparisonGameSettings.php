<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W25 (ADR-080) — GameSettings для блока сравнения «на фоне выживших».
 *
 * Killswitch economy.comparison.enabled (OFF dormant) + tunable порог членов
 * фракции для осмысленного сравнения (faction-строка показывается только при ≥N).
 * Audit: фракцию выбрали 23/365 → основное сравнение server-wide, faction опционально.
 */
class W25SeedEconomyComparisonGameSettings extends Migration
{
    public function up(): void
    {
        $rows = [
            [
                'setting_key'        => 'economy.comparison.enabled',
                'value_type'         => 'bool',
                'value_bool'         => false,
                'default_value_text' => 'false',
                'category'           => 'experimental',
                'rationale_text'     => 'Killswitch для W25 блока «📊 На фоне выживших» в экране «Моя экономика» (server-wide перцентиль net worth + сравнение с фракцией если членов достаточно). Default OFF — dormant до активации батчем в конце ROADMAP vNext2.',
                'effect_text'        => 'При true — в отчёте «💰 Моя экономика» добавляется блок: «богаче Y% выживших, позиция #N из M, медиана сервера», + строка по фракции при ≥ порога членов. При false — блок скрыт.',
                'above_effect_text'  => 'n/a — булев ключ',
                'below_effect_text'  => 'n/a — булев ключ',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
            ],
            [
                'setting_key'        => 'economy.comparison.faction_min_members',
                'value_type'         => 'int',
                'value_int'          => 5,
                'default_value_text' => '5',
                'category'           => 'experimental',
                'rationale_text'     => 'Минимум членов фракции, чтобы показывать сравнение с медианой фракции. Audit: у малых фракций (Фермеры=1, Милитари=3) медиана = сам игрок → бессмысленно. При меньшем числе показывается только server-wide сравнение.',
                'effect_text'        => 'Faction-строка в блоке «На фоне выживших» появляется только если в фракции игрока ≥ этого числа членов.',
                'above_effect_text'  => 'Выше — faction-сравнение почти никогда не показывается (фракции малы).',
                'below_effect_text'  => 'Ниже — faction-медиана считается на крошечной выборке (2-3 чел), статистически бессмысленна.',
                'recommended_min'    => 3,
                'recommended_max'    => 20,
                'hard_min'           => 2,
                'hard_max'           => 1000,
            ],
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->countAllResults();
            if ($exists === 0) {
                $this->db->table('game_settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', ['economy.comparison.enabled', 'economy.comparison.faction_min_members'])
            ->delete();
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * story multibase-picker-01 — радиус покрытия Вышки связи на один её уровень.
 *
 * Было зашито в `CommunicationTowerCoverageService` как `towerLevel * 100`; ключ сеется
 * дефолтом 100, поэтому поведение после выката байт-идентично прежнему.
 *
 * `game_settings` = KEEP (WipeManifest не трогаем — новых таблиц/колонок нет).
 * Идемпотентно по `setting_key`.
 */
class SeedTowerCoveragePerLevelSetting extends Migration
{
    private const KEY = 'communication_tower.coverage_per_level';

    public function up(): void
    {
        $exists = $this->db->table('game_settings')->where('setting_key', self::KEY)->get()->getRowArray();
        if (! empty($exists)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('game_settings')->insert([
            'setting_key'        => self::KEY,
            'category'           => 'buildings',
            'value_type'         => 'int',
            'value_int'          => 100,
            'value_float'        => null,
            'value_bool'         => null,
            'value_string'       => null,
            'default_value_text' => '100',
            'rationale_text'     => 'Сколько клеток (ходов, метрика Чебышёва) покрывает сигнал Вышки связи на каждый её уровень. 100 — прежнее зашитое в код значение: вышка 1 уровня уже покрывает заметную часть острова, и игрок работает с базой издалека, не возвращаясь на неё.',
            'effect_text'        => 'CommunicationTowerCoverageService: maxCoverage = уровень Вышки × это значение, отдельно для каждой базы. Решает, какие базы доступны с экрана «🏠 База» вдали от них, открываются ли карточки построек и апгрейд не стоя на базе.',
            'above_effect_text'  => 'Выше — вышка 1 уровня покрывает весь остров: выбор базы и возвращение на неё теряют смысл, прокачка Вышки перестаёт что-то давать.',
            'below_effect_text'  => 'Ниже — работа с базой почти только стоя на ней: вторая база вдали снова недоступна без телепорта, Вышка связи ощущается бесполезной.',
            'recommended_min'    => '50',
            'recommended_max'    => '200',
            'hard_min'           => '1',
            'hard_max'           => '1000',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', self::KEY)->delete();
    }
}

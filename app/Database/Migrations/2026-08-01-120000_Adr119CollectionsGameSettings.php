<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-119 (E19) — настройки коллекций/музея. Категория endgame.
 *
 * killswitch collections.enabled default OFF → музей-кнопка скрыта, donate отклоняется (dormant).
 * collections.min_level default 50 — гейт доступа (live-tunable). game_settings = KEEP. Idempotent.
 * ADR-024: rationale/effect/above/below NOT NULL.
 */
class Adr119CollectionsGameSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            [
                'setting_key'        => 'collections.enabled',
                'category'           => 'endgame',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch коллекций + музея базы (E19, ADR-113 гейт L50). Долгая горизонтальная цель собирательства: игрок ЖЕРТВУЕТ редкие предметы (реликвии/север/легендарные находки) в музей навсегда (item-sink), за полный сет — косметический титул. default=false → кнопка музея скрыта, сдача отклоняется (dormant) до активации после Tier-3.',
                'effect_text'        => 'ON: в хабе «Прогресс» появляется «🏛 Музей» (с L50-lock для младших); CollectionService принимает сдачу ресурсов и выдаёт титул при завершении коллекции. OFF: фича невидима/неактивна.',
                'above_effect_text'  => 'true: ветераны L50+ получают собирательскую цель + терминальный синк редких предметов (анти-инфляция). Включать после Tier-3.',
                'below_effect_text'  => 'false: музея нет (dormant). Emergency-off — мгновенно, без редеплоя.',
                'recommended_min'    => null, 'recommended_max' => null, 'hard_min' => null, 'hard_max' => null,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'collections.min_level',
                'category'           => 'endgame',
                'value_type'         => 'int',
                'value_int'          => 50,
                'default_value_text' => '50',
                'rationale_text'     => 'Уровень доступа к музею/коллекциям (гейт L50 «Коллекционер» лестницы ADR-113). Ниже — lock-кнопка с пояснением (UX-discoverability). 50 = ступень, к которой ветеран подходит, накопив редкие предметы для сдачи.',
                'effect_text'        => 'Персонаж level ≥ min_level видит «🏛 Музей» и может сдавать; ниже — lock-кнопка «🔒 Музей (с lvl N)».',
                'above_effect_text'  => 'Выше: музей доступен позже (для совсем поздней игры).',
                'below_effect_text'  => 'Ниже: музей открывается раньше (риск — у младших нет редких предметов на сдачу).',
                'recommended_min'    => '30', 'recommended_max' => '70', 'hard_min' => '1', 'hard_max' => '250',
                'created_at' => $now, 'updated_at' => $now,
            ],
        ];

        foreach ($rows as $row) {
            if ($this->db->table('game_settings')->where('setting_key', $row['setting_key'])->countAllResults() === 0) {
                $this->db->table('game_settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'collections.enabled', 'collections.min_level',
        ])->delete();
    }
}

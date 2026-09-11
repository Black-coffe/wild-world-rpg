<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-186 (`docs/specs/pvp-detection-clarity/`, story `-02`) — три хардкод-гейта
 * `PvPRestrictionService` уезжают в `GameSettings` (категория `combat`).
 *
 * 🔴 Дефолты байт-в-байт равны сегодняшнему хардкоду (решение владельца и
 * редколлегии 11.09, `docs/grill/2026-09-11-inactive-character-purge.md`): в день релиза
 * популяция, которая может драться, не меняется ни на одного персонажа. Все три ручки
 * **заморожены** до снятия замера окна противостояния (ADR-186 §6) — поворот любой из
 * трёх сейчас загрязнит замер, поэтому `rationale_text` явно называет заморозку.
 *
 * Idempotent по `setting_key` (паттерн `SeedWorldMoveGameSettings`). `game_settings` = KEEP
 * (WipeManifest не трогаем — новых таблиц/player-колонок эта миграция не создаёт).
 */
class Adr186SeedPvpRestrictionSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'pvp.restriction.min_level',
                'value_type'         => 'int',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'Минимальный уровень персонажа, ниже которого он не может ни атаковать, ни быть атакован в полевом PvP. Значение перенесено 1:1 из hardcoded `PvPRestrictionService::checkPvPAllowed()` — свежий персонаж без экипировки и навыков не должен становиться жертвой. 🔴 Ручка ЗАМОРОЖЕНА (ADR-186 §6, решение владельца и редколлегии 11.09): не менять до снятия замера окна противостояния — поворот сейчас загрязнит замер тем же релизом, что меняет популяцию боеспособных.',
                'effect_text'        => 'PvPRestrictionService::checkPvPAllowed() отклоняет бой, если уровень атакующего ИЛИ защитника ниже этого порога (reason_code=level).',
                'above_effect_text'  => 'Выше 5 — больше низкоуровневых персонажей защищены от PvP, но и позже открывается доступ к дуэлям/рейтингу, часть контента ощущается недоступной дольше.',
                'below_effect_text'  => 'Ниже 5 — вчерашний новичок без снаряжения попадает под удар веторана раньше; риск гарантированных поражений для П5/П2 растёт.',
                'recommended_min'    => '3',
                'recommended_max'    => '15',
                'hard_min'           => '1',
                'hard_max'           => '100',
            ],
            [
                'setting_key'        => 'pvp.restriction.safe_zone_min_y',
                'value_type'         => 'int',
                'value_int'          => 900,
                'default_value_text' => '900',
                'rationale_text'     => 'Южная граница безопасной зоны (координата Y): персонаж на клетке с `coordinate_y` не ниже этого значения не может ни атаковать, ни быть атакован. Значение перенесено 1:1 из hardcoded `PvPRestrictionService::checkPvPAllowed()`. 🔴 Ручка ЗАМОРОЖЕНА (ADR-186 §6, решение владельца и редколлегии 11.09) — до снятия замера окна противостояния; побочный эффект переезда брошенных персонажей на юг (story `-03`) в полосу `y` 850–899, а не в эту зону, уже учтён и не требует правки этой ручки.',
                'effect_text'        => 'PvPRestrictionService::checkPvPAllowed() отклоняет бой, если `coordinate_y` клетки атакующего ИЛИ защитника ≥ этого значения (reason_code=safe_zone).',
                'above_effect_text'  => 'Выше 900 — безопасная полоса у южного края сужается, больше клеток становятся PvP-доступными у самой границы карты.',
                'below_effect_text'  => 'Ниже 900 — безопасная зона расширяется на север, больше игроков около спавна временно защищены от PvP, но и часть уже освоенной территории внезапно становится безопасной.',
                'recommended_min'    => '800',
                'recommended_max'    => '950',
                'hard_min'           => '0',
                'hard_max'           => '1000',
            ],
            [
                'setting_key'        => 'pvp.restriction.min_account_age_days',
                'value_type'         => 'int',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => 'Минимальный возраст аккаунта в днях, ниже которого персонаж не может ни атаковать, ни быть атакован. Значение перенесено 1:1 из hardcoded `PvPRestrictionService::checkPvPAllowed()` — даёт новичку окно на освоение до того, как он станет мишенью. 🔴 Ручка ЗАМОРОЖЕНА (ADR-186 §6, решение владельца и редколлегии 11.09): не менять до снятия замера окна противостояния.',
                'effect_text'        => 'PvPRestrictionService::checkPvPAllowed() отклоняет бой, если аккаунт атакующего ИЛИ защитника моложе этого числа дней (reason_code=account_age).',
                'above_effect_text'  => 'Выше 10 — дольше защищённый период для новичка, но и дольше недоступен полевой PvP как часть контента для тех, кто хочет драться раньше.',
                'below_effect_text'  => 'Ниже 10 — свежий аккаунт раньше становится доступной целью, растёт риск, что первое знакомство с PvP будет гарантированным поражением.',
                'recommended_min'    => '3',
                'recommended_max'    => '30',
                'hard_min'           => '0',
                'hard_max'           => '365',
            ],
        ];

        $defaults = [
            'category'        => 'combat',
            'value_float'     => null,
            'value_bool'      => null,
            'value_string'    => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($defaults, $row));
        }
    }

    public function down(): void
    {
        $keys = [
            'pvp.restriction.min_level',
            'pvp.restriction.safe_zone_min_y',
            'pvp.restriction.min_account_age_days',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-135 Ф3b — GameSettings «Доски розыска» (bounty) + сид титула «Охотник за головами».
 *
 * Owner-pick: престиж без золота (0 эмиссии, 0 вектора отмывания). Все ключи category='combat',
 * под общим killswitch tribute.enabled + собственным tribute.bounty_enabled. Rich rationale (ADR-024).
 * Idempotent (по setting_key / title_key).
 */
class Adr135bBountyGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'tribute.bounty_enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch «Доски розыска» (ADR-135 Ф3b). false = bounty-слой dormant: доска скрыта, клеймы охотника не пишутся, титул не выдаётся. Работает поверх tribute.enabled (без подати нет «хозяев» → нет розыска). Включать вместе/после активации подати.',
                'effect_text'        => 'true (И tribute.enabled) → игрок, держащий активную подать, попадает в «розыск»; третья сторона, сразившая его в полевом PvP, получает охотничий трофей (престиж: счётчик + титул). false → bounty-слой выключен без редеплоя.',
                'above_effect_text'  => 'true (вкл): доминирование становится публично наказуемым (статус + охота) — включать осознанно после анонса.',
                'below_effect_text'  => 'false (выкл): мгновенный откат bounty-слоя; сама подать (tribute.*) не затрагивается.',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'tribute.bounty_claim_cooldown_hours',
                'value_type'         => 'int',
                'value_int'          => 24,
                'default_value_text' => '24',
                'rationale_text'     => 'Кулдаун (часов) между засчитанными трофеями ОДНОГО охотника над ОДНИМ доминатором. Анти-фарм: нельзя накручивать счётчик, многократно добивая одну и ту же цель.',
                'effect_text'        => 'При downing\'е доминатора: трофей засчитывается, только если прошлый клейм этой пары (hunter→target) старше N часов. Иначе — бой проходит, но трофей не пишется.',
                'above_effect_text'  => 'При 168+ (неделя) повторный трофей с той же цели почти недостижим → охота на серийного доминатора менее «отзывчива».',
                'below_effect_text'  => 'При 0 кулдаун снят → один охотник накручивает счётчик на одной цели (фарм престижа).',
                'recommended_min'    => '6',
                'recommended_max'    => '72',
                'hard_min'           => '0',
                'hard_max'           => '8760',
            ],
            [
                'setting_key'        => 'tribute.bounty_hunter_title_threshold',
                'value_type'         => 'int',
                'value_int'          => 3,
                'default_value_text' => '3',
                'rationale_text'     => 'Сколько уникальных трофеев нужно набрать охотнику, чтобы получить титул «Охотник за головами» (🎯). Престиж-веха, не боевая сила. Выдаётся напрямую (минуя cron титулов — у него source_type level/achievement).',
                'effect_text'        => 'BountyService при записи клейма: если суммарных трофеев охотника ≥ N → TitleService::award титула bounty_hunter (если titles.enabled). Идемпотентно.',
                'above_effect_text'  => 'При 20+ титул становится труднодостижимым эндгейм-маркером (мало кто доминирует достаточно, чтобы их было кого ловить).',
                'below_effect_text'  => 'При 1 титул даётся за первый же трофей → теряет престиж-вес.',
                'recommended_min'    => '3',
                'recommended_max'    => '15',
                'hard_min'           => '1',
                'hard_max'           => '1000',
            ],
        ];

        $defaults = [
            'category'        => 'combat',
            'value_int'       => null,
            'value_float'     => null,
            'value_bool'      => null,
            'value_string'    => null,
            'recommended_min' => null,
            'recommended_max' => null,
            'hard_min'        => null,
            'hard_max'        => null,
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

        // Сид титула «Охотник за головами» (source_type='bounty' — cron его не трогает, выдаёт
        // BountyService напрямую при достижении порога). Idempotent по title_key.
        $titleExists = $this->db->table('titles')->where('title_key', 'bounty_hunter')->countAllResults();
        if ($titleExists === 0) {
            $this->db->table('titles')->insert([
                'title_key'   => 'bounty_hunter',
                'name'        => 'Охотник за головами',
                'description' => 'Срази троих угнетателей, державших трофейную подать над другими игроками. Пустошь запомнит охотника.',
                'source_type' => 'bounty',
                'source_ref'  => '3',
                'icon'        => '🎯',
                'sort_order'  => 190,
                'enabled'     => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'tribute.bounty_enabled',
            'tribute.bounty_claim_cooldown_hours',
            'tribute.bounty_hunter_title_threshold',
        ])->delete();
        $this->db->table('titles')->where('title_key', 'bounty_hunter')->delete();
    }
}

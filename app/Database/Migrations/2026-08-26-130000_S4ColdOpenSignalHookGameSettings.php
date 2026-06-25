<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S4 (ROADMAP-RETENTION-10, ADR-139) — cold-open нарратив радио-крючок + bait у спавна (спина-слайс 3).
 *
 * Суб-killswitch + tunable-награда приманки. Audit S4: первый-ход payoff уже жив (lucky-find/starter-kit),
 * но НАПРАВЛЕННОГО крючка «там что-то есть → иди» нет; ObjectSignalService (ADR-098) march-only + эндгейм.
 * signal_hook=ON → ColdOpenSignalService кладёт достижимую приманку у спавна (reactive), 🎯 на карте +
 * Роби-нарратив; на клетке — авто-находка (gold + ресурс). OFF (default) → 3 хука no-op, byte-identical.
 * ОТДЕЛЬНЫЙ от мастер-флага 1a и суб-флагов 1b/2. game_settings = KEEP (WipeManifest не трогаем). Idempotent.
 * Категория world.
 */
class S4ColdOpenSignalHookGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'onboarding.cold_open_v2.signal_hook',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Суб-killswitch S4-3: cold-open радио-крючок + bait у спавна (ColdOpenSignalService). false (default) — DORMANT: размещение приманки, 🎯-маркер и arrival-находка — все 3 хука no-op, карта/спавн/ход byte-identical. true — новичку (level ≤ contextual_hints.max_level) при спавне кладётся достижимая приманка в паре клеток, отмечается 🎯 на текстовой карте, Роби шлёт радио-нарратив; дойдя на клетку, новичок авто-забирает находку (bait_gold + bait_resource). ОТДЕЛЬНЫЙ от мастер-флага 1a — свой Tier-3 + активация.',
                'effect_text'        => 'StartCommand (placeBaitForNewChar) + TextMapService (🎯-маркер) + MoveCharacterToDirectionAction (tryReachBait) гейтят поведение по этому флагу. Хранилище приманки — biome_world_object_map (active→cleared) + seed-anchor world_objects OnbSignalCache.',
                'above_effect_text'  => 'true — новичок получает направленный in-media-res крючок (сигнал → 🎯 → находка), попутно закрывает OnbStep Move «пройди 3 клетки»; бьёт в bounce (56% L1 не двигаются).',
                'below_effect_text'  => 'false — крючка нет, новичок видит только пассивную полярную звезду 1a (текущее поведение); первый-ход lucky-find остаётся.',
            ],
            [
                'setting_key'        => 'onboarding.cold_open_v2.bait_gold',
                'value_type'         => 'int',
                'value_int'          => 200,
                'default_value_text' => '200',
                'recommended_min'    => 0,
                'recommended_max'    => 500,
                'hard_min'           => 0,
                'hard_max'           => 5000,
                'rationale_text'     => 'Золото в находке-приманке cold-open (выдаётся один раз, на клетке-цели). 200 — заметный, но не ломающий ранний бонус (сопоставим с lucky-find 300, но приманка ещё даёт ресурс). Действует только при signal_hook=ON и level ≤ cap.',
                'effect_text'        => 'ColdOpenSignalService::tryReachBait → increaseGold при заборе приманки.',
                'above_effect_text'  => 'Выше (>500) — ранняя экономика новичка раздувается, золото-синки теряют смысл на старте.',
                'below_effect_text'  => '0 — золото в приманке не выдаётся (только ресурс); находка ощущается беднее.',
            ],
            [
                'setting_key'        => 'onboarding.cold_open_v2.bait_resource_id',
                'value_type'         => 'int',
                'value_int'          => 2,
                'default_value_text' => '2',
                'recommended_min'    => 1,
                'recommended_max'    => 100,
                'hard_min'           => 1,
                'hard_max'           => 100000,
                'rationale_text'     => 'resources.id ресурса в находке-приманке. 2 = Древесина — базовый материал, полезен для следующего шага цепочки (Craft). Меняй на иной id, если хочешь другую находку (17=Вода, 8=Кора, 19=Галька).',
                'effect_text'        => 'ColdOpenSignalService::tryReachBait → addOrIncreaseResource(charId, bait_resource_id, bait_resource_qty).',
                'above_effect_text'  => 'Иной id → иной ресурс в находке (это не «больше/меньше», а выбор предмета).',
                'below_effect_text'  => 'Иной id → иной ресурс; несуществующий id просто ничего не добавит (qty уйдёт в no-op).',
            ],
            [
                'setting_key'        => 'onboarding.cold_open_v2.bait_resource_qty',
                'value_type'         => 'int',
                'value_int'          => 5,
                'default_value_text' => '5',
                'recommended_min'    => 0,
                'recommended_max'    => 20,
                'hard_min'           => 0,
                'hard_max'           => 1000,
                'rationale_text'     => 'Количество ресурса bait_resource_id в находке-приманке. 5 — ощутимый стак на старте (хватает на первый крафт), не инфляционный.',
                'effect_text'        => 'ColdOpenSignalService::tryReachBait → addOrIncreaseResource количество.',
                'above_effect_text'  => 'Выше (>20) — ранний инвентарь переполняется, обесценивается добыча/крафт ресурса.',
                'below_effect_text'  => '0 — ресурс в приманке не выдаётся (только золото).',
            ],
            [
                'setting_key'        => 'onboarding.cold_open_v2.bait_distance',
                'value_type'         => 'int',
                'value_int'          => 3,
                'default_value_text' => '3',
                'recommended_min'    => 2,
                'recommended_max'    => 5,
                'hard_min'           => 1,
                'hard_max'           => 6,
                'rationale_text'     => 'На сколько клеток от спавна кладётся приманка. 3 — в пределах видимой 12×12 карты (±6), достаточно для «путешествия» к 🎯, попутно закрывает OnbStep Move «3 клетки». ≤6, иначе 🎯 выйдет за видимую область.',
                'effect_text'        => 'ColdOpenSignalService::placeBaitForNewChar — оффсет клетки-цели от спавна.',
                'above_effect_text'  => 'Выше (>6) — 🎯 за пределами видимой карты, новичок не увидит метку (крючок не работает).',
                'below_effect_text'  => '1 — приманка вплотную, «путешествия» нет, OnbStep Move не закрывается попутно.',
            ],
        ];

        $defaults = [
            'category'        => 'world',
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
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'onboarding.cold_open_v2.signal_hook',
            'onboarding.cold_open_v2.bait_gold',
            'onboarding.cold_open_v2.bait_resource_id',
            'onboarding.cold_open_v2.bait_resource_qty',
            'onboarding.cold_open_v2.bait_distance',
        ])->delete();
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S4 (ROADMAP-RETENTION-10, ADR-139) — прогрессивное раскрытие построек (спина-слайс 2).
 *
 * Суб-killswitch для lock-кнопок уровневых построек в списке строительства. Audit: BuildListAction
 * показывал L1-новичку все 15 зданий (вкл. Арсенал L15) без lock-state → гейт только внутри preview
 * (UX-Discoverability нарушение + перегруз). ON → ниже-уровневые здания рендерятся как «🔒 … (с lvl X)»
 * + alert с prerequisite (зеркало Фракции L10). false (default) → DORMANT, byte-identical (список как
 * раньше). ОТДЕЛЬНЫЙ от мастер-флага (1a) и start_greeting (1b): свой Tier-3 + активация. game_settings
 * = KEEP (WipeManifest не трогаем). Idempotent. Категория world.
 */
class S4ColdOpenBuildLocksGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'onboarding.cold_open_v2.build_locks',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Суб-killswitch S4-2: lock-кнопки уровневых построек в списке строительства (BuildLockService/BuildListAction). false (default) — DORMANT: список показывает все 15 зданий кнопками как раньше (byte-identical), гейт уровня срабатывает только внутри preview. true — постройки выше уровня игрока (Лаб/Спортзал L5, стена L8, Робо/ограда L10, Телепорт/вышка L12, Арсенал L15) рендерятся как «🔒 … (с lvl X)» + alert с prerequisite (UX-Discoverability: вход виден, клик объясняет). ОТДЕЛЬНЫЙ от мастер-флага — свой Tier-3 + активация.',
                'effect_text'        => 'BuildListAction::handle гейтит lock-рендер по этому флагу через BuildLockService::isLocked. Источник уровней — Config\\Buildings (тот же, что preview-гейт). read-only.',
                'above_effect_text'  => 'true — новичок видит чёткую лестницу построек (что доступно сейчас vs что откроется и когда) вместо 15 кнопок-обманок; снижает перегруз и «уровень слишком низкий»-тупик.',
                'below_effect_text'  => 'false — список плоский: новичок жмёт Арсенал L15, получает «уровень низкий» без подсказки пути (текущее поведение).',
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
            'onboarding.cold_open_v2.build_locks',
        ])->delete();
    }
}

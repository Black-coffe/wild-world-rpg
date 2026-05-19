<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S16 (v0.51.198) — seed GameSettings keys для Tier 3 Professional Workbench
 * (ADR-026, ROADMAP-CRAFT §7 Фаза 4).
 *
 * 2 admin-tunable параметра:
 *   - `tier3.workbench.character_level_required` (int, default=20):
 *       gate-level персонажа для крафта T3 верстака.
 *   - `tier3.workbench.craft_duration_hours` (int, default=8):
 *       длительность задачи craftProfessionalWorkbench.
 *
 * Wire-up: GenericCraftActionStart читает оба ключа через
 * `recipe.required_level_setting_key` и `recipe.duration_override_setting_key`
 * (новые поля recipe schema, ADR-026).
 *
 * Constitutional rule (CLAUDE.md §🎛️, ADR-024): rationale_text /
 * effect_text / above_effect_text / below_effect_text NOT NULL.
 *
 * Idempotent: пропускает существующие setting_key.
 */
class S16SeedProfessionalWorkbenchGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'tier3.workbench.character_level_required',
                'category'           => 'craft',
                'value_type'         => 'int',
                'value_int'          => 20,
                'default_value_text' => '20',
                'rationale_text'     => 'L20 gate перед T3 верстаком — защищает прогрессию от skip\'а через карты/события и оправдывает Фазу 3 building progression (S11-S15: L17-L19 апгрейды). User решением 2026-05-17 поднято с L16 (изначально в ROADMAP) до L20 — жёстче gate, T3 = настоящий endgame-entry для top-12% DAU. Без gate T3 = балансная брешь: чары L15 запрыгивают сразу на T3, devaluing T2.',
                'effect_text'        => 'Stat check в GenericCraftActionStart::handle (line ~199, через $statChecks[\'level\']). Если character.level < N → рекрут отвергается с message «Недостаточно уровня. Нужно N, есть X.». Recipe \'ProfessionalWorkbench\' читает значение через recipe.required_level_setting_key (ADR-026 wire-up).',
                'above_effect_text'  => 'При 25 — gate жёстче (top-4% DAU only); T3 контент станет underused в первые 4-6 недель, пока чары догоняют. Фермит-grind на T2 удлиняется.',
                'below_effect_text'  => 'При 15 — L15 чары (после Workshop L1 build) запрыгивают сразу на T3, обходя L17-L19 progression Фазы 3. Workbench Standard остаётся ценным только 2 уровня (L13-L15).',
                'recommended_min'    => '18',
                'recommended_max'    => '22',
                'hard_min'           => '10',
                'hard_max'           => '50',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'tier3.workbench.craft_duration_hours',
                'category'           => 'craft',
                'value_type'         => 'int',
                'value_int'          => 8,
                'default_value_text' => '8',
                'rationale_text'     => '8 часов — Standard-tier времени крафта (как WorkbenchOne = 2h, robots = ~30min, T3 = 8h pattern «дороже = дольше»). Делает крафт верстака разовым «overnight commit» (запустил вечером — забрал утром). Игроки не блокируют craft-slot на сутки, но и не получают мгновенный rush к T3 рецептам.',
                'effect_text'        => 'Длительность задачи craftProfessionalWorkbench (override tasks.min_duration/max_duration). Recipe читает через recipe.duration_override_setting_key, конвертирует в минуты (N*60), использует как min=max в GenericCraftActionStart::calculateCraftingDuration.',
                'above_effect_text'  => 'При 16h — двойной overnight, начинают появляться отмены задач (cancel rate выше). Для casual игроков выглядит punitive.',
                'below_effect_text'  => 'При 4h — теряет «overnight commit» характер, превращается в «вечерний быстрый крафт». Меньше anticipation, меньше «событийности» — для hero-объекта (открывает T3 mode) downgrade UX.',
                'recommended_min'    => '6',
                'recommended_max'    => '12',
                'hard_min'           => '1',
                'hard_max'           => '48',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue; // idempotent
            }
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'tier3.workbench.character_level_required',
                'tier3.workbench.craft_duration_hours',
            ])
            ->delete();
    }
}

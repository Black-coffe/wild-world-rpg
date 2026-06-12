<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * E17 Ф2 (ADR-117) — admin-tunable персональные мини-события в Походе для L25+ (ADR-024). category=world.
 *
 * world.march_events.enabled (bool, 0)            — мастер-рубильник мини-событий в Походе.
 * world.march_events.min_level (int, 25)          — минимальный уровень игрока (гейт L25 лестницы ADR-113).
 * world.march_events.chance_per_cell (float, 0.0) — шанс мини-события на пройденную клетку. Idempotent.
 *
 * Тройной гейт (enabled И level≥min И rng<chance), default chance 0 → dormant byte-identical.
 */
class Adr117MarchMiniEventSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $keys = [
            [
                'setting_key'        => 'world.march_events.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch персональных мини-событий в Походе для L25+ (E17 Ф2, ADR-117, реюз encounter-инфры ADR-089). true — иногда поход прерывается мини-событием (схрон/остов машины/тайник) с выбором «Осмотреть» (модест generic-находка) / «Пройти мимо»; false — dormant. Контент-горизонт для ветеранов (лестница L25-100). Ship dormant, активация после Tier-3.',
                'effect_text'        => 'MarchingTaskHandler::tryRandomMiniEvent: per-cell гейт (enabled И level≥min_level И rng<chance_per_cell) → pauseMarch(mini_event) → marchMini осмотр выдаёт generic-ресурсы.',
                'above_effect_text'  => 'true (вкл): поход ветерана живее — иногда находки в пути; не золото/не поясное сырьё → не инфляция.',
                'below_effect_text'  => 'false (выкл, default): мини-событий в пути нет (поход не затронут, 0 регрессии).',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'world.march_events.min_level',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 25,
                'default_value_text' => '25',
                'rationale_text'     => 'Минимальный уровень игрока для мини-событий в Походе (гейт L25 лестницы целей ADR-113 — контент для подросшего ядра, разведывающего средний пояс/север). Ниже — поход без мини-событий (новичкам и так есть чем заняться, не перегружать).',
                'effect_text'        => 'MarchMiniEventService::eligible: мини-события доступны только при char.level ≥ min_level.',
                'above_effect_text'  => 'выше (напр. 40): мини-события только для глубокого эндгейма.',
                'below_effect_text'  => 'ниже (напр. 10): доступны и менее опытным — но премиса «контент для ветеранов» размывается.',
                'recommended_min'    => '15',
                'recommended_max'    => '50',
                'hard_min'           => '1',
                'hard_max'           => '200',
            ],
            [
                'setting_key'        => 'world.march_events.chance_per_cell',
                'category'           => 'world',
                'value_type'         => 'float',
                'value_float'        => 0.0,
                'default_value_text' => '0.0',
                'rationale_text'     => 'Шанс мини-события на каждую пройденную в Походе клетку (0..1). 0 = выключено (dormant). Параллелен npc.march_encounter_chance (NPC-встречи) — оба прерывают поход; держать суммарно умеренно, чтобы поход не дёргался постоянно. Рекомендуемый старт при активации ~0.05.',
                'effect_text'        => 'MarchMiniEventService::roll: per-cell mt_rand < chance (только после eligible). При успехе поход паузится мини-событием.',
                'above_effect_text'  => 'выше 0.15: мини-события слишком часты → поход постоянно прерывается, раздражает (как с NPC-встречами).',
                'below_effect_text'  => '0 (default): мини-событий нет (0 регрессии похода).',
                'recommended_min'    => '0.0',
                'recommended_max'    => '0.12',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
        ];

        foreach ($keys as $row) {
            if (! empty($this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray())) {
                continue;
            }
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'world.march_events.enabled',
            'world.march_events.min_level',
            'world.march_events.chance_per_cell',
        ])->delete();
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-095 Фаза 1a — «Строительство»: лимит баз по уровню (admin-tunable). GameSettings
 * `buildings.bases.*`.
 *
 *  - buildings.bases.levels_per_base — сколько уровней открывают +1 базу.
 *  - buildings.bases.hard_cap        — жёсткий потолок числа баз на персонажа.
 *
 * Формула в BaseLimitService: max(1, min(hard_cap, floor(level / levels_per_base))).
 * Idempotent. Rich rationale (ADR-024). Не создаёт таблиц/player-колонок → WipeManifest
 * не трогаем (game_settings уже KEEP).
 */
class Adr095SeedBaseLimitGameSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [];

        $rows[] = $this->row('buildings.bases.levels_per_base', 'buildings', 'int', 50, '50', '25', '100', '1', '500',
            'Сколько уровней персонажа открывают одну дополнительную базу. 50 — анкер ТЗ: 2-я база на L100, 3-я на L150, … 19-я на L950+. Стартовая база бесплатна (с L1).',
            'BaseLimitService::maxBasesForLevel = max(1, min(hard_cap, floor(level / это))). Гейтит «Окопаться» и создание базы.',
            'Выше (100+): базы открываются медленнее → почти у всех 1 база, мульти-бэйс контент мёртв для большинства.',
            'Ниже (25 и менее): базы открываются быстро → низкоуровневые игроки копят много баз (нагрузка на налог/карту, амплифицирует first-base-баг до Фазы 1b).');

        $rows[] = $this->row('buildings.bases.hard_cap', 'buildings', 'int', 19, '19', '2', '19', '1', '50',
            'Жёсткий потолок числа баз на персонажа независимо от уровня. 19 — анкер ТЗ «19 баз на всю карту на максималке (L999)».',
            'BaseLimitService::hardCap. maxBasesForLevel никогда не превысит это; nextBaseLevel вернёт null при достижении.',
            'Выше (20+): на карте может быть очень много баз одного игрока → перегрузка карты, налоговая экономика, дисбаланс.',
            'Ниже (2-3): мульти-бэйс почти отключён, идея «много баз» не работает; держи ≥ текущего числа баз у топ-игроков, иначе у них «лишние» базы.');

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge([
                'value_int' => null, 'value_float' => null, 'value_bool' => null, 'value_string' => null,
                'recommended_min' => null, 'recommended_max' => null, 'hard_min' => null, 'hard_max' => null,
                'created_at' => $now, 'updated_at' => $now,
            ], $row));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function row(string $key, string $cat, string $type, ?int $int, string $defaultText, ?string $recMin, ?string $recMax, ?string $hardMin, ?string $hardMax, string $rationale, string $effect, string $above, string $below): array
    {
        return [
            'setting_key'        => $key,
            'category'           => $cat,
            'value_type'         => $type,
            'value_int'          => $int,
            'default_value_text' => $defaultText,
            'rationale_text'     => $rationale,
            'effect_text'        => $effect,
            'above_effect_text'  => $above,
            'below_effect_text'  => $below,
            'recommended_min'    => $recMin,
            'recommended_max'    => $recMax,
            'hard_min'           => $hardMin,
            'hard_max'           => $hardMax,
        ];
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', ['buildings.bases.levels_per_base', 'buildings.bases.hard_cap'])
            ->delete();
    }
}

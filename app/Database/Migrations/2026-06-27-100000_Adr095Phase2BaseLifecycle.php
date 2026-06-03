<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-095 Фаза 2 (DORMANT) — «Строительство»: срок жизни базы (base-TTL) + налог-каскад
 * до уничтожения базы. Всё под killswitch'ами (по умолчанию OFF) → поведение byte-identical,
 * пока владелец не активирует после валидации (решение Gate-1: tunable + dormant + мягкие дефолты).
 *
 * Столбцы:
 *  - claimed_cells.last_visited_at (DATETIME) — последний визит игрока на базу; сброс TTL.
 *    Бэкфилл = claimed_at (как будто посетили при создании).
 *  - characters.tax_unpaid_streak (INT) — подряд дней неуплаты налога (для каскада).
 *
 * GameSettings `buildings.lifecycle.*` (category=buildings), rich rationale (ADR-024).
 *
 * Не создаёт ТАБЛИЦ (только ALTER) → WipeManifest не трогаем (claimed_cells=PLAYER_DATA,
 * characters=CHARACTER_RESET уже классифицированы; coverage-тест сканит createTable, не ALTER).
 */
class Adr095Phase2BaseLifecycle extends Migration
{
    public function up(): void
    {
        // 1) claimed_cells.last_visited_at
        if (! $this->db->fieldExists('last_visited_at', 'claimed_cells')) {
            $this->forge->addColumn('claimed_cells', [
                'last_visited_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'claimed_at'],
            ]);
            // Бэкфилл: считаем, что база «посещена» в момент создания.
            $this->db->query('UPDATE claimed_cells SET last_visited_at = claimed_at WHERE last_visited_at IS NULL');
        }

        // 2) characters.tax_unpaid_streak
        if (! $this->db->fieldExists('tax_unpaid_streak', 'characters')) {
            $this->forge->addColumn('characters', [
                'tax_unpaid_streak' => ['type' => 'INT', 'null' => false, 'default' => 0],
            ]);
        }

        // 3) GameSettings buildings.lifecycle.* (dormant defaults)
        $now  = date('Y-m-d H:i:s');
        $rows = [];

        $rows[] = $this->row('buildings.lifecycle.ttl_enabled', 'buildings', 'bool', null, null, 0, 'false', null, null, '0', '1',
            'Killswitch срока жизни базы (base-TTL). false — базы вечны (как сейчас). true — база живёт ttl-срок с последнего визита, потом исчезает со всеми постройками. DORMANT по умолчанию: включать только после валидации баланса (churn-риск для казуалов/возвращенцев).',
            'BaseLifecycleHandler (крон) сносит базы, где now > last_visited_at + ttlDaysFor(level). Базовый экран показывает остаток дней.',
            'true (вкл): экономика «приходи или потеряешь базу» — давление, но риск потери баз у редко заходящих.',
            'false (выкл): база не исчезает по времени — текущее безопасное поведение.');

        $rows[] = $this->row('buildings.lifecycle.ttl_days_per_level', 'buildings', 'int', 1, null, null, '1', '1', '5', '0', '30',
            'Сколько дней жизни базы даёт один уровень персонажа (ТЗ: «база стоит level дней»). 1 → L50 = 50 дней. Действует вместе с ttl_min_days (мягкий пол).',
            'BaseLifecycleService::ttlDaysFor = max(ttl_min_days, level × это). Сброс отсчёта при визите на базу.',
            'Выше: базы живут очень долго → TTL почти не давит.',
            'Ниже (0): только ttl_min_days определяет срок (уровень не влияет).');

        $rows[] = $this->row('buildings.lifecycle.ttl_min_days', 'buildings', 'int', 30, null, null, '30', '14', '60', '1', '365',
            'Мягкий ПОЛ срока жизни базы (дней) независимо от уровня. 30 — низкоуровневый/возвращенец не теряет базу за считанные дни (анти-churn). ТЗ-строгий вариант = 0 (тогда чистый level-в-днях).',
            'BaseLifecycleService::ttlDaysFor = max(это, level × ttl_days_per_level).',
            'Выше: даже у топов база живёт минимум столько → TTL мягче.',
            'Ниже (→0): строгий «level дней», казуалы теряют базы быстро (churn-риск).');

        $rows[] = $this->row('buildings.lifecycle.tax_cascade_enabled', 'buildings', 'bool', null, null, 0, 'false', null, null, '0', '1',
            'Killswitch налог-каскада до уничтожения базы. false — при неуплате удаляется ПОСТРОЙКА (текущее поведение TaxCollectionHandler). true — после grace-дней неуплаты подряд уничтожается самая маленькая база (наименее застроенная), и так до оплаты/конца баз. DORMANT по умолчанию.',
            'TaxCollectionHandler: при cascade ON ведёт characters.tax_unpaid_streak; streak ≥ grace → снос наименьшей базы вместо удаления постройки.',
            'true (вкл): жёсткое золото-давление, базы исчезают при банкротстве.',
            'false (выкл): максимум теряешь одну постройку за раз (безопаснее).');

        $rows[] = $this->row('buildings.lifecycle.tax_cascade_grace_days', 'buildings', 'int', 3, null, null, '3', '2', '7', '1', '30',
            'Сколько дней неуплаты налога подряд до уничтожения наименьшей базы (ТЗ: «через трое суток»). 3 — три предупреждения. После сноса базы streak сбрасывается (новые grace-дни на следующую).',
            'TaxCollectionHandler: tax_unpaid_streak ≥ это И cascade ON → снос наименьшей базы, streak→0.',
            'Выше: больше предупреждений, мягче.',
            'Ниже (1): база сносится почти сразу при первой неуплате — жёстко.');

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
    private function row(string $key, string $cat, string $type, ?int $int, ?float $float, ?int $bool, string $defaultText, ?string $recMin, ?string $recMax, ?string $hardMin, ?string $hardMax, string $rationale, string $effect, string $above, string $below): array
    {
        return [
            'setting_key' => $key, 'category' => $cat, 'value_type' => $type,
            'value_int' => $int, 'value_float' => $float, 'value_bool' => $bool,
            'default_value_text' => $defaultText, 'rationale_text' => $rationale, 'effect_text' => $effect,
            'above_effect_text' => $above, 'below_effect_text' => $below,
            'recommended_min' => $recMin, 'recommended_max' => $recMax, 'hard_min' => $hardMin, 'hard_max' => $hardMax,
        ];
    }

    public function down(): void
    {
        if ($this->db->fieldExists('last_visited_at', 'claimed_cells')) {
            $this->forge->dropColumn('claimed_cells', 'last_visited_at');
        }
        if ($this->db->fieldExists('tax_unpaid_streak', 'characters')) {
            $this->forge->dropColumn('characters', 'tax_unpaid_streak');
        }
        $this->db->table('game_settings')->whereIn('setting_key', [
            'buildings.lifecycle.ttl_enabled', 'buildings.lifecycle.ttl_days_per_level',
            'buildings.lifecycle.ttl_min_days', 'buildings.lifecycle.tax_cascade_enabled',
            'buildings.lifecycle.tax_cascade_grace_days',
        ])->delete();
    }
}

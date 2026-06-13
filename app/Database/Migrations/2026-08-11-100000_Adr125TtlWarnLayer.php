<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-125 (E26) — «мягкий режим» base-TTL: слой предупреждений ПЕРЕД сносом + поэтапная активация.
 *
 * ADR-095 Ф2 строил base-TTL как обрыв: флип `ttl_enabled` ON → ближайший крон сносит все
 * просроченные базы сразу, уведомление только постфактум. E26 требует «сначала только
 * предупреждения, через период — сам снос». Этот слой — ОТДЕЛЬНЫЙ killswitch `warn_enabled`:
 * включаем сначала предупреждения (фаза A), `ttl_enabled` — позже (фаза B), каждую фазу можно
 * откатить независимо.
 *
 * Столбцы:
 *  - claimed_cells.last_warned_at (DATETIME) — когда базе в последний раз слали TTL-предупреждение
 *    (троттлинг эскалации). Визит сбрасывает в NULL (BaseService::touchVisit) → новый простой
 *    предупреждает заново. Без бэкфилла (NULL = ещё не предупреждали).
 *
 * GameSettings `buildings.lifecycle.*` (category=buildings), rich rationale (ADR-024):
 *  - warn_enabled (bool, OFF) — killswitch фазы предупреждений.
 *  - warn_days (int, 7) — окно: предупреждать когда до сноса ≤ N дней.
 *  - warn_cooldown_days (int, 3) — минимум дней между предупреждениями одной базе.
 *  + коррекция дефолта ttl_min_days 30→60 (решение владельца E26: 2-мес grace казуалам;
 *    срез прода бимодальный — 30→60 почти не меняет реклейм мёртвых баз, но защищает живых).
 *
 * НЕ создаёт ТАБЛИЦ (только ALTER) → WipeManifest не трогаем (claimed_cells=PLAYER_DATA уже
 * классифицирована; coverage-тест сканит createTable, не ALTER).
 */
class Adr125TtlWarnLayer extends Migration
{
    public function up(): void
    {
        // 1) claimed_cells.last_warned_at
        if (! $this->db->fieldExists('last_warned_at', 'claimed_cells')) {
            $this->forge->addColumn('claimed_cells', [
                'last_warned_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'last_visited_at'],
            ]);
        }

        // 2) GameSettings warn-слой (dormant defaults)
        $now  = date('Y-m-d H:i:s');
        $rows = [];

        $rows[] = $this->row('buildings.lifecycle.warn_enabled', 'buildings', 'bool', null, null, 0, 'false', null, null, '0', '1',
            'Killswitch фазы предупреждений base-TTL (отдельно от самого сноса ttl_enabled). false — предупреждения не шлются. true — крон шлёт эскалирующие предупреждения базам, до сноса которых осталось ≤ warn_days дней (CTA «зайди на базу — таймер сбросится»), НО сам снос происходит только при ttl_enabled ON. DORMANT по умолчанию: «мягкий режим» E26 — включать warn_enabled первым, ttl_enabled позже.',
            'BaseLifecycleHandler (крон 04:00): при warn_enabled ON шлёт предупреждение базе в окне warn_days, не чаще раза в warn_cooldown_days; ставит claimed_cells.last_warned_at. Базовый экран показывает обратный отсчёт уже в warn-фазе.',
            'true (вкл): игроки получают предупреждения о простое баз — мягкая подготовка к сносу.',
            'false (выкл): предупреждения не шлются (как до E26).');

        $rows[] = $this->row('buildings.lifecycle.warn_days', 'buildings', 'int', 7, null, null, '7', '3', '14', '1', '30',
            'За сколько дней до истечения срока жизни базы начинать слать предупреждения. 7 — неделя на реакцию. Действует только при warn_enabled ON.',
            'BaseLifecycleService::shouldWarn — предупреждать, если до сноса осталось ≤ это (включая уже просроченные базы).',
            'Выше: предупреждаем сильно заранее (больше шума, но больше времени на реакцию).',
            'Ниже: предупреждаем впритык к сносу (меньше шума, меньше времени среагировать).');

        $rows[] = $this->row('buildings.lifecycle.warn_cooldown_days', 'buildings', 'int', 3, null, null, '3', '2', '7', '1', '14',
            'Минимум дней между предупреждениями одной базе (анти-спам, эскалация). 3 — при окне 7 дней база получит ~2-3 предупреждения (≈на 7-й, 4-й, 1-й день).',
            'BaseLifecycleService::shouldWarn: пропускает базу, если с прошлого last_warned_at прошло меньше этого числа дней.',
            'Выше: реже напоминаем (меньше уведомлений).',
            'Ниже (1): напоминаем каждый день (навязчиво для редко заходящих).');

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

        // 3) Коррекция дефолта ttl_min_days 30→60 (решение владельца E26). Только если значение
        //    всё ещё равно старому дефолту 30 (не клобберим admin-тюнинг). Default-correction
        //    через seed-migration — санкционированный путь (constitutional admin-tunable rule).
        $this->db->table('game_settings')
            ->where('setting_key', 'buildings.lifecycle.ttl_min_days')
            ->where('value_int', 30)
            ->update([
                'value_int'          => 60,
                'default_value_text' => '60',
                'rationale_text'     => 'Мягкий ПОЛ срока жизни базы (дней) независимо от уровня. 60 — возвращенец-казуал не теряет базу ~2 месяца (анти-churn, философия ADR-035/090). Срез прода бимодальный: 30→60 почти не меняет реклейм мёртвых баз, но даёт живым полный 2-мес запас. ТЗ-строгий вариант = 0 (тогда чистый level-в-днях).',
                'updated_at'         => $now,
            ]);
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
        if ($this->db->fieldExists('last_warned_at', 'claimed_cells')) {
            $this->forge->dropColumn('claimed_cells', 'last_warned_at');
        }
        $this->db->table('game_settings')->whereIn('setting_key', [
            'buildings.lifecycle.warn_enabled',
            'buildings.lifecycle.warn_days',
            'buildings.lifecycle.warn_cooldown_days',
        ])->delete();
        // ttl_min_days откатываем к 30 только если оставался нашим E26-дефолтом 60.
        $this->db->table('game_settings')
            ->where('setting_key', 'buildings.lifecycle.ttl_min_days')
            ->where('value_int', 60)
            ->update(['value_int' => 30, 'default_value_text' => '30']);
    }
}

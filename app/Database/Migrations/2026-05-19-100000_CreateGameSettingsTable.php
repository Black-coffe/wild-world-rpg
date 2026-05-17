<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S5 (v0.51.187) — `GameSettings` live-tunable balance framework foundation.
 *
 * Constitutional requirement (CLAUDE.md §🎛️): каждый balance/setting/rebalance
 * параметр выносится в админку с **rich rationale** (rationale_text /
 * effect_text / above_effect_text / below_effect_text) + soft bounds
 * (recommended_min/max) + hard bounds (hard_min/max) + Reset-to-default.
 *
 * Schema:
 * - `setting_key` — `category.subcategory.name` (snake_case_dot_notation), UNIQUE
 * - `category` — craft|buildings|resources|combat|world|endgame|experimental
 * - `value_type` — int|float|bool|string + соответствующие value_* колонки
 * - `default_value_text` — текст исходного значения для Reset button
 * - `rationale_text` / `effect_text` / `above_effect_text` / `below_effect_text`
 *   — invariant: без этих 4 полей запись не создаётся (CHECK NOT NULL)
 * - `recommended_min/max` — soft (admin UI окрасит warning)
 * - `hard_min/max` — strict (сохранение запрещено вне)
 * - `updated_by` — email/name админа из Shield auth (audit trail)
 *
 * Seed: 3 repair keys (foundation для будущего S5b repair UI).
 * Анти-паттерн: hardcoded balance numbers в коде без записи в этой таблице.
 *
 * ADR-024 описывает: foundation, fallback cascade (DB → Config\GameBalance →
 * hardcoded), cache invalidation, audit-log integration.
 */
class CreateGameSettingsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'setting_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
                'comment'    => 'category.subcategory.name — snake_case_dot_notation',
            ],
            'category' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => false,
                'comment'    => 'craft|buildings|resources|combat|world|endgame|experimental',
            ],
            'value_type' => [
                'type'       => 'ENUM',
                'constraint' => ['int', 'float', 'bool', 'string'],
                'null'       => false,
            ],
            'value_int' => [
                'type'     => 'INT',
                'null'     => true,
            ],
            'value_float' => [
                'type'       => 'DECIMAL',
                'constraint' => '12,4',
                'null'       => true,
            ],
            'value_bool' => [
                'type'     => 'TINYINT',
                'constraint' => 1,
                'null'     => true,
            ],
            'value_string' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'default_value_text' => [
                'type' => 'TEXT',
                'null' => false,
                'comment' => 'Reset button читает это поле',
            ],
            'rationale_text' => [
                'type' => 'TEXT',
                'null' => false,
                'comment' => 'ПОЧЕМУ сейчас именно это значение (constitutional)',
            ],
            'effect_text' => [
                'type' => 'TEXT',
                'null' => false,
                'comment' => 'НА ЧТО влияет (механики/формулы) (constitutional)',
            ],
            'above_effect_text' => [
                'type' => 'TEXT',
                'null' => false,
                'comment' => 'ЧТО будет если выше recommended (constitutional)',
            ],
            'below_effect_text' => [
                'type' => 'TEXT',
                'null' => false,
                'comment' => 'ЧТО будет если ниже recommended (constitutional)',
            ],
            'recommended_min' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'comment'    => 'Soft — admin UI окрасит warning жёлтым',
            ],
            'recommended_max' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'hard_min' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'comment'    => 'Strict — сохранение запрещено вне диапазона',
            ],
            'hard_max' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'updated_by' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
                'comment'    => 'email/name админа из Shield auth',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('setting_key');
        $this->forge->addKey('category');
        $this->forge->createTable('game_settings', true);

        // Seed initial repair keys (foundation для S5b repair UI).
        $now = date('Y-m-d H:i:s');
        $this->db->table('game_settings')->insert([
            'setting_key'        => 'repair.cost_fraction',
            'category'           => 'craft',
            'value_type'         => 'float',
            'value_float'        => 0.50,
            'default_value_text' => '0.50',
            'rationale_text'     => 'Половина — компромисс между «ремонт почти бесплатный» (П2 win, П8 sink loss) и «ремонт дешевле полного крафта в 2 раза, но всё ещё ощутимый sink» (баланс economy + dolce vita для casuals).',
            'effect_text'        => 'Множитель ресурсов для action `repair` — итоговая стоимость = ceil(original_required_resources × значение). Не влияет на durability/время ремонта.',
            'above_effect_text'  => 'При 0.70 ремонт = 70% от полного крафта — sink сохраняется почти полностью, П8 трейдеры довольны, но П2/П5 теряют ощущение «ремонт вместо нового крафта». При 0.90+ — repair становится бессмысленным.',
            'below_effect_text'  => 'При 0.30 ремонт почти бесплатный — П1 хардкорщики жалуются на тривиализацию decay system, П8 теряют sink, инфляция золота возрастает. При 0.10 — economy ломается, ремонт превращается в обнуление durability бесплатно.',
            'recommended_min'    => '0.30',
            'recommended_max'    => '0.70',
            'hard_min'           => '0.10',
            'hard_max'           => '0.90',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
        $this->db->table('game_settings')->insert([
            'setting_key'        => 'repair.task_duration_minutes',
            'category'           => 'craft',
            'value_type'         => 'int',
            'value_int'          => 15,
            'default_value_text' => '15',
            'rationale_text'     => '15 минут — короче минимального крафтового задания (≥30 мин у большинства), чтобы repair был быстрым выбором против re-craft. Достаточно времени чтобы игрок успел сделать ещё что-то параллельно.',
            'effect_text'        => 'Длительность task у action `repair` в минутах. Используется при INSERT character_tasks (end_time = start_time + N min).',
            'above_effect_text'  => 'При 30+ минутах ремонт становится сопоставим с крафтом — repair теряет преимущество в скорости. П2 (offline-toleranт) ОК, но П1/П5 (active) недовольны.',
            'below_effect_text'  => 'При 5 минутах repair becomes spam-able — игрок может бесконечно ремонтировать инструмент в gather-цикле без downtime. Decay теряет временной gating.',
            'recommended_min'    => '10',
            'recommended_max'    => '30',
            'hard_min'           => '1',
            'hard_max'           => '180',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
        $this->db->table('game_settings')->insert([
            'setting_key'        => 'repair.restore_durability_to_percent',
            'category'           => 'craft',
            'value_type'         => 'int',
            'value_int'          => 100,
            'default_value_text' => '100',
            'rationale_text'     => '100% (полное восстановление до template-default) — простой ментальный контракт «починил = как новый». 80% — «ремонт изнашивает», но это уже advanced механика, не для финала Фазы 1.',
            'effect_text'        => 'Процент от crafted_items.durability_count (template default), до которого восстанавливается durability у инструмента в `repair` task completion. 100 = полное восстановление.',
            'above_effect_text'  => 'Выше 100 невозможно (hard_max=100) — инструмент не может быть прочнее нового.',
            'below_effect_text'  => 'При 80% — каждый repair даёт меньше прочности → ускоренный износ → больше repair cycles → больше resource sink. П1 хардкорщики ОК, П2/П5 недовольны (наказание за repair). Сложная механика, без UX-фичи «прогресс до полной негодности».',
            'recommended_min'    => '80',
            'recommended_max'    => '100',
            'hard_min'           => '1',
            'hard_max'           => '100',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('game_settings', true);
    }
}

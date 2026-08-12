<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Суточные когорты новичков — снимок воронки, переживающий чистку `player_action_log`.
 *
 * Повод (аудит 2026-08-12, `mmorpg-vault/reference/2026-08-12-first-taps-audit.md`):
 * firehose живёт 30 дней (`logging.player_actions.retention_days`), а первая добыча новичка
 * нигде больше следа не оставляет. Из-за этого два замера подряд дали неверные ответы: слайс
 * «второй шаг» сравнивался со стёртым базлайном, а сравнение периодов показало несуществующий
 * рост 16% → 85% (у старой когорты действия были просто удалены). Пока историю не фиксируем
 * снимком, любой A/B онбординга неизмерим по построению.
 *
 * `logs_complete` — был ли 24-часовой горизонт когорты внутри окна хранения логов на момент
 * расчёта. Строка с `logs_complete = 1` НИКОГДА не перезаписывается пересчётом на урезанных
 * логах ({@see \App\Services\Analytics\OnboardingCohortService}).
 *
 * WipeManifest (CLAUDE.md §🔥): таблица классифицирована как **KEEP** — это админ-аналитика
 * без привязки к персонажу (нет `character_id`), и после вайпа она нужна именно затем, чтобы
 * сравнить воронку до и после.
 */
class CreateOnboardingCohortDaily extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'cohort_date'   => ['type' => 'DATE', 'null' => false, 'comment' => 'Сутки регистрации'],
            'regs'          => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'beyond_start'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'comment' => '>=2 действий за 24ч'],
            'moved'         => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'comment' => 'explored_cells за 24ч'],
            'gathered'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'comment' => 'добыча за 24ч (firehose)'],
            'crafted'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'comment' => 'crafted_items_log за 24ч'],
            'back_d1'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'back_d7'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'logs_complete' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0,
                                'comment' => '1 = считалось на полных логах; такие строки не перезаписываются'],
            'computed_at'   => ['type' => 'DATETIME', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('cohort_date');
        $this->forge->createTable('onboarding_cohort_daily', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('onboarding_cohort_daily', true);
    }
}

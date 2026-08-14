<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-168 — колонка `origin` в firehose: с какого ЭКРАНА нажата кнопка.
 *
 * Зачем. `action_name` отвечает «что нажали», но не «откуда»: кнопку добычи рисуют шесть разных
 * экранов, и все шлют одинаковое `callback_data='gather'`. Из-за этого замер слайса «второй шаг»
 * 2026-08-12 не смог отделить входы с компаса от входов из меню действий и был вынужден мерить
 * когортами, которым при притоке ≈1.7 регистрации в сутки нужны месяцы до значимости.
 *
 * Почему отдельная колонка, а не разбор `raw_input`. Историю замеров нельзя ломать: `action_name`
 * и `raw_input` обязаны остаться сравнимыми со строками до ADR-168, иначе любой прежний SQL
 * начнёт врать. Новое измерение живёт в своём поле.
 *
 * Индекс `(origin, created_at)` — под целевой запрос замера «сколько входов с каждого экрана
 * за окно». WipeManifest: таблица уже классифицирована как PLAYER_DATA, новая колонка связи
 * с игроком не добавляет — переклассификация не нужна.
 *
 * Идемпотентна: колонка/индекс добавляются, только если их ещё нет.
 */
class Adr168PlayerActionLogAddOrigin extends Migration
{
    private const TABLE = 'player_action_log';

    public function up(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }

        if (! $this->db->fieldExists('origin', self::TABLE)) {
            $this->forge->addColumn(self::TABLE, [
                'origin' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 16,
                    'null'       => true,
                    'after'      => 'error_text',
                    'comment'    => 'ADR-168: экран-источник нажатия (cmp/hub/fw/mar/bld/crf)',
                ],
            ]);
        }

        if (! $this->indexExists('idx_pal_origin_created')) {
            $this->db->query(
                'ALTER TABLE `' . self::TABLE . '` ADD INDEX `idx_pal_origin_created` (`origin`, `created_at`)'
            );
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }

        if ($this->indexExists('idx_pal_origin_created')) {
            $this->db->query('ALTER TABLE `' . self::TABLE . '` DROP INDEX `idx_pal_origin_created`');
        }

        if ($this->db->fieldExists('origin', self::TABLE)) {
            $this->forge->dropColumn(self::TABLE, 'origin');
        }
    }

    private function indexExists(string $name): bool
    {
        foreach ($this->db->getIndexData(self::TABLE) as $index) {
            if ($index->name === $name) {
                return true;
            }
        }

        return false;
    }
}

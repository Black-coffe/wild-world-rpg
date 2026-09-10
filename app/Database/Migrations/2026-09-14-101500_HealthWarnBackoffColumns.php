<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * health-warning-backoff, story-01 (ADR см. brief.md `docs/specs/health-warning-backoff/`).
 *
 * Состояние затухания предупреждений о низком здоровье — сегодня `LowHealthWarningHandler`
 * (story-02, НЕ трогать) шлёт одно и то же сообщение на фиксированном интервале, не глядя
 * на реакцию игрока. Новые колонки дают ему на что опереться:
 *
 * - `low_health_warn_streak` — сколько предупреждений подряд осталось без реакции
 *   (растёт → множит интервал; реакция игрока сбрасывает в 0).
 * - `low_health_last_band` — нижняя граница полосы здоровья последнего предупреждения
 *   (провал в СЛЕДУЮЩУЮ полосу вниз — повод предупредить сразу, минуя затухание).
 * - `low_health_warns_today` / `low_health_warns_day` — суточный потолок предупреждений
 *   вне критической полосы, чтобы затухание нельзя было обойти скачками здоровья.
 *
 * `characters` уже классифицирована в `Config\WipeManifest` как `CHARACTER_RESET` —
 * новые колонки это прогресс персонажа, сбрасываются вместе с ним, манифест не трогаем.
 * Идемпотентно (fieldExists), рядом с существующей `low_health_notified_at`.
 * Именование зафиксировано в brief.md «Договорённости по именам» — story-02 пишет код под них.
 *
 * Префикс сдвинут на 101500 (было 100000) по решению владельца — совпадал с
 * `2026-09-14-100000_InventoryOverviewGameSettings.php` из параллельной работы; порядок
 * применения не зависел от совпадения (разные таблицы, зависимостей нет), но
 * `migrate:status` с двумя записями на одном времени читался двусмысленно.
 */
class HealthWarnBackoffColumns extends Migration
{
    public function up(): void
    {
        $cols = [];

        if (! $this->db->fieldExists('low_health_warn_streak', 'characters')) {
            $cols['low_health_warn_streak'] = [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
                'default'    => 0,
                'after'      => 'low_health_notified_at',
                'comment'    => 'health-warning-backoff — подряд проигнорированных предупреждений',
            ];
        }
        if (! $this->db->fieldExists('low_health_last_band', 'characters')) {
            $cols['low_health_last_band'] = [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'null'       => true,
                'after'      => 'low_health_warn_streak',
                'comment'    => 'health-warning-backoff — нижняя граница полосы последнего предупреждения',
            ];
        }
        if (! $this->db->fieldExists('low_health_warns_today', 'characters')) {
            $cols['low_health_warns_today'] = [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
                'default'    => 0,
                'after'      => 'low_health_last_band',
                'comment'    => 'health-warning-backoff — счётчик предупреждений за сутки',
            ];
        }
        if (! $this->db->fieldExists('low_health_warns_day', 'characters')) {
            $cols['low_health_warns_day'] = [
                'type'    => 'DATE',
                'null'    => true,
                'after'   => 'low_health_warns_today',
                'comment' => 'health-warning-backoff — за какие сутки считается low_health_warns_today',
            ];
        }

        if ($cols !== []) {
            $this->forge->addColumn('characters', $cols);
        }
    }

    public function down(): void
    {
        foreach (['low_health_warn_streak', 'low_health_last_band', 'low_health_warns_today', 'low_health_warns_day'] as $col) {
            if ($this->db->fieldExists($col, 'characters')) {
                $this->forge->dropColumn('characters', $col);
            }
        }
    }
}

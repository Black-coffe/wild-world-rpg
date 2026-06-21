<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Атрибуция интейка (SEO-WEB, находка 2026-06-21): источник первой регистрации.
 *
 * Захватывается из payload deep-link `/start <src_*>` ОДИН раз при создании пользователя
 * (first-touch). Раньше бот молча выбрасывал payload → вся SEO-стратегия с `?start=src_site_<тег>`
 * не давала атрибуции (нельзя сказать, какая статья/конвейер гонит регистрации). NULL = органика/
 * прямой вход / до-захватные пользователи.
 *
 * WipeManifest: `telegram_users` уже IDENTITY_RESET; колонка НЕ в `reset`-списке (только
 * message-указатели чистятся) → историческая атрибуция переживает вайп. ALTER (не createTable)
 * → coverage-тест не затрагивается.
 */
class AddAcquisitionSourceToTelegramUsers extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('acquisition_source', 'telegram_users')) {
            $this->forge->addColumn('telegram_users', [
                'acquisition_source' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 191,
                    'null'       => true,
                    'default'    => null,
                    'comment'    => 'Источник первой регистрации из payload /start (src_site_*, src_habr_* и т.п.). NULL = органика/прямой/до-захвата. First-touch, ставится один раз при создании.',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('acquisition_source', 'telegram_users')) {
            $this->forge->dropColumn('telegram_users', 'acquisition_source');
        }
    }
}

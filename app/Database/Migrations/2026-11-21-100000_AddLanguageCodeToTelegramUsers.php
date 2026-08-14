<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Язык интерфейса игрока (`from.language_code` из каждого Telegram-апдейта).
 *
 * Повод (аудит «молчунов» 2026-08-14). Около 22% регистраций нажимают `/start` и больше
 * ничего — при этом у них ВСЁ отработало: персонаж создан, паёк выдан, доставка `ok`, а
 * следом их четырежды догоняли (Day2Ping, ComebackPing, подсказки, нуджи) без единого
 * возврата. Среди них видны заведомо не-русскоязычные аккаунты (`cuan_gak`/Budi,
 * `Olaade87`/Ola, `lei`). Игра существует только на русском, поэтому гипотеза «человек
 * физически не может прочитать экран» объясняла бы молчание лучше любой правки копии —
 * но проверить её было нечем: Telegram присылает `language_code` в каждом апдейте, а мы
 * его выбрасывали.
 *
 * Колонка ничего не меняет в поведении — это измерение. Оно отвечает на вопрос, который
 * решает, что делать дальше: чинить онбординг, добавлять язык или просто перестать считать
 * этих людей в знаменателе активации.
 *
 * WipeManifest: `telegram_users` уже классифицирована как IDENTITY_RESET, а он обнуляет
 * ТОЛЬКО перечисленные колонки-указатели. Локаль — часть идентичности (как имена) и вайп
 * переживает; переклассификация не нужна.
 *
 * Идемпотентна.
 */
class AddLanguageCodeToTelegramUsers extends Migration
{
    private const TABLE = 'telegram_users';

    public function up(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }

        if (! $this->db->fieldExists('language_code', self::TABLE)) {
            $this->forge->addColumn(self::TABLE, [
                'language_code' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 16,
                    'null'       => true,
                    'after'      => 'last_name',
                    'comment'    => 'IETF-тег языка клиента Telegram (ru, en, id, …); NULL — апдейт без поля',
                ],
            ]);
        }

        if (! $this->indexExists('idx_tu_language_code')) {
            $this->db->query(
                'ALTER TABLE `' . self::TABLE . '` ADD INDEX `idx_tu_language_code` (`language_code`)'
            );
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }

        if ($this->indexExists('idx_tu_language_code')) {
            $this->db->query('ALTER TABLE `' . self::TABLE . '` DROP INDEX `idx_tu_language_code`');
        }

        if ($this->db->fieldExists('language_code', self::TABLE)) {
            $this->forge->dropColumn(self::TABLE, 'language_code');
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

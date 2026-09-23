<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * web-accounts-p0-01 (ADR-188) — персонаж принадлежит аккаунту.
 *
 *   1. UNIQUE telegram_users.telegram_id и UNIQUE characters.telegram_user_id (закрывает гонку
 *      двойного /start). Дубли НЕ чистятся молча: миграция падает со списком id.
 *   2. characters.account_id INT UNSIGNED NULL, FK → accounts ON DELETE SET NULL.
 *   3. Backfill: каждому персонажу с Telegram — один аккаунт (acquisition_source='telegram') и одна
 *      identity (provider='telegram', subject = telegram_users.telegram_id). Идемпотентно: персонаж
 *      с account_id пропускается, существующая telegram-identity переиспользуется.
 *
 * Проверено 2026-09-23: на проде и testbot 0 дублей по обоим ключам.
 */
class LinkCharactersToAccounts extends Migration
{
    private const TG_UNIQUE   = 'telegram_users_telegram_id_unique';
    private const CHAR_UNIQUE = 'characters_telegram_user_id_unique';
    private const ACCOUNT_KEY = 'characters_account_id_index';
    private const ACCOUNT_FK  = 'characters_account_id_foreign';

    public function up(): void
    {
        $this->assertNoDuplicates();

        if (! $this->indexExists('telegram_users', self::TG_UNIQUE)) {
            $this->db->query('ALTER TABLE telegram_users ADD UNIQUE KEY ' . self::TG_UNIQUE . ' (telegram_id)');
        }
        if (! $this->indexExists('characters', self::CHAR_UNIQUE)) {
            $this->db->query('ALTER TABLE characters ADD UNIQUE KEY ' . self::CHAR_UNIQUE . ' (telegram_user_id)');
        }

        if (! $this->db->fieldExists('account_id', 'characters')) {
            $this->db->query('ALTER TABLE characters
                ADD COLUMN account_id INT UNSIGNED NULL DEFAULT NULL,
                ADD KEY ' . self::ACCOUNT_KEY . ' (account_id),
                ADD CONSTRAINT ' . self::ACCOUNT_FK . ' FOREIGN KEY (account_id)
                    REFERENCES accounts(id) ON DELETE SET NULL ON UPDATE CASCADE');
        }

        $this->backfill();
    }

    public function down(): void
    {
        $this->db->resetDataCache();
        if ($this->db->fieldExists('account_id', 'characters')) {
            $this->db->query('ALTER TABLE characters DROP FOREIGN KEY ' . self::ACCOUNT_FK);
            $this->db->query('ALTER TABLE characters DROP KEY ' . self::ACCOUNT_KEY . ', DROP COLUMN account_id');
        }
        if ($this->indexExists('characters', self::CHAR_UNIQUE)) {
            // MySQL снёс неявный индекс FK telegram_user_id, когда появился UNIQUE, — без
            // замены UNIQUE не отдаётся («needed in a foreign key constraint»).
            $other = $this->db->query(
                "SELECT 1 FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = 'characters'
                    AND column_name = 'telegram_user_id' AND seq_in_index = 1 AND index_name <> ? LIMIT 1",
                [self::CHAR_UNIQUE]
            )->getResultArray();
            $addPlain = $other === [] ? 'ADD KEY characters_telegram_user_id_foreign (telegram_user_id), ' : '';
            $this->db->query('ALTER TABLE characters ' . $addPlain . 'DROP KEY ' . self::CHAR_UNIQUE);
        }
        if ($this->indexExists('telegram_users', self::TG_UNIQUE)) {
            $this->db->query('ALTER TABLE telegram_users DROP KEY ' . self::TG_UNIQUE);
        }
        // Строки accounts/account_identities, созданные backfill'ом, уходят вместе с таблицами
        // в down() миграции 2026-12-10-100001_CreateAccountsTables.
    }

    /**
     * Backfill: один аккаунт + одна telegram-identity на каждого персонажа с Telegram.
     * Публичный, чтобы тест мог прогнать его повторно и проверить идемпотентность.
     */
    public function backfill(): void
    {
        $rows = $this->db->query(
            'SELECT c.id AS character_id, tu.telegram_id
               FROM characters c
               JOIN telegram_users tu ON tu.id = c.telegram_user_id
              WHERE c.account_id IS NULL
              ORDER BY c.id'
        )->getResultArray();

        $now = date('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $characterId = (int) $row['character_id'];
            $subject     = (string) $row['telegram_id'];

            $this->db->transStart();

            $identity = $this->db->table('account_identities')
                ->select('account_id')
                ->where('provider', 'telegram')
                ->where('subject', $subject)
                ->get()->getRowArray();

            if (! empty($identity)) {
                $accountId = (int) $identity['account_id'];
            } else {
                $this->db->table('accounts')->insert([
                    'acquisition_source' => 'telegram',
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]);
                $accountId = (int) $this->db->insertID();
                $this->db->table('account_identities')->insert([
                    'account_id' => $accountId,
                    'provider'   => 'telegram',
                    'subject'    => $subject,
                    'created_at' => $now,
                ]);
            }

            $this->db->table('characters')
                ->where('id', $characterId)
                ->where('account_id', null)
                ->update(['account_id' => $accountId]);

            $this->db->transComplete();
            if ($this->db->transStatus() === false) {
                throw new RuntimeException("LinkCharactersToAccounts: backfill failed for character {$characterId}");
            }
        }
    }

    private function assertNoDuplicates(): void
    {
        $tg = $this->db->query(
            'SELECT telegram_id, GROUP_CONCAT(id ORDER BY id) AS ids
               FROM telegram_users GROUP BY telegram_id HAVING COUNT(*) > 1'
        )->getResultArray();
        $ch = $this->db->query(
            'SELECT telegram_user_id, GROUP_CONCAT(id ORDER BY id) AS ids
               FROM characters WHERE telegram_user_id IS NOT NULL
              GROUP BY telegram_user_id HAVING COUNT(*) > 1'
        )->getResultArray();

        if ($tg === [] && $ch === []) {
            return;
        }

        $parts = [];
        foreach ($tg as $r) {
            $parts[] = "telegram_users.telegram_id={$r['telegram_id']} → telegram_users.id [{$r['ids']}]";
        }
        foreach ($ch as $r) {
            $parts[] = "characters.telegram_user_id={$r['telegram_user_id']} → characters.id [{$r['ids']}]";
        }

        throw new RuntimeException(
            'LinkCharactersToAccounts: UNIQUE cannot be added, duplicates exist (resolve by hand, nothing was changed): '
            . implode('; ', $parts)
        );
    }

    private function indexExists(string $table, string $index): bool
    {
        $rows = $this->db->query(
            'SELECT 1 FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $index]
        )->getResultArray();

        return $rows !== [];
    }
}

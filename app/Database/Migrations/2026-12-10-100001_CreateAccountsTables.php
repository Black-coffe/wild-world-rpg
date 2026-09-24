<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * web-accounts-p0-01 (ADR-188) — корень игрока вне Telegram.
 *
 *   - accounts            — сам аккаунт (владеет персонажем и способами входа).
 *   - account_identities  — способы входа: email / google / yandex / telegram;
 *                           UNIQUE(provider, subject) — один способ принадлежит одному аккаунту.
 *   - account_tokens      — remember-me и сброс пароля (selector + sha256 validator).
 *   - account_link_codes  — одноразовые коды из бота для входа на сайт (sha256 кода).
 *
 * WipeManifest: accounts/account_identities = IDENTITY_RESET, tokens/link_codes = TRANSIENT.
 */
class CreateAccountsTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'acquisition_source' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'created_at'         => ['type' => 'DATETIME', 'null' => true],
            'updated_at'         => ['type' => 'DATETIME', 'null' => true],
            'last_login_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('accounts', true);

        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'account_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'provider'     => ['type' => 'ENUM', 'constraint' => ['email', 'google', 'yandex', 'telegram'], 'null' => false],
            'subject'      => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'secret_hash'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'email'        => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'last_used_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['provider', 'subject'], 'account_identities_provider_subject_unique');
        $this->forge->addKey('account_id');
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('account_identities', true);

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'account_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'purpose'        => ['type' => 'ENUM', 'constraint' => ['remember', 'password_reset'], 'null' => false],
            'selector'       => ['type' => 'CHAR', 'constraint' => 24, 'null' => false],
            'validator_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => false],
            'expires_at'     => ['type' => 'DATETIME', 'null' => false],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'last_used_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('selector');
        $this->forge->addKey('account_id');
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('account_tokens', true);

        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'character_id' => ['type' => 'INT', 'constraint' => 5, 'unsigned' => true, 'null' => false],
            'code_hash'    => ['type' => 'CHAR', 'constraint' => 64, 'null' => false],
            'expires_at'   => ['type' => 'DATETIME', 'null' => false],
            'used_at'      => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('code_hash');
        $this->forge->addKey('character_id');
        $this->forge->addForeignKey('character_id', 'characters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('account_link_codes', true);
    }

    public function down(): void
    {
        // Обратный FK-порядок: сначала зависимые от accounts/characters.
        $this->forge->dropTable('account_link_codes', true);
        $this->forge->dropTable('account_tokens', true);
        $this->forge->dropTable('account_identities', true);
        $this->forge->dropTable('accounts', true);
    }
}

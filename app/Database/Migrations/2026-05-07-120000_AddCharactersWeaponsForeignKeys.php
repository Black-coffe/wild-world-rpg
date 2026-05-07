<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * v0.51.46 — додає FK constraints до characters_weapons (Performance-DB §14).
 *
 * Original migration `2025-02-11_CreateCharactersWeaponsTable.php` залишив FK
 * закоментованими ("Опционально"). Додаємо тепер:
 *  - character_id → characters.id (CASCADE on update/delete)
 *  - weapon_id → weapons.id (CASCADE on update/delete)
 *
 * Verified prod data integrity 2026-05-07: 0 orphan rows для обох FK
 * (16 total rows у таблиці). Safe для додавання без cleanup.
 *
 * CASCADE on delete — якщо character видалено (CharacterReset admin),
 * характер_weapons rows автоматично clean up. Це закриває потенційний
 * orphan-leak vector після characters cleanup.
 */
class AddCharactersWeaponsForeignKeys extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();

        $db->query('ALTER TABLE characters_weapons
            ADD CONSTRAINT fk_cw_character
            FOREIGN KEY (character_id) REFERENCES characters(id)
            ON DELETE CASCADE ON UPDATE CASCADE');

        $db->query('ALTER TABLE characters_weapons
            ADD CONSTRAINT fk_cw_weapon
            FOREIGN KEY (weapon_id) REFERENCES weapons(id)
            ON DELETE CASCADE ON UPDATE CASCADE');
    }

    public function down()
    {
        $db = \Config\Database::connect();

        $db->query('ALTER TABLE characters_weapons DROP FOREIGN KEY fk_cw_character');
        $db->query('ALTER TABLE characters_weapons DROP FOREIGN KEY fk_cw_weapon');
    }
}

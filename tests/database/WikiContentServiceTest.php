<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\WikiContentService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-050 — live-wiki проекции. Главный инвариант: броня НЕ светит PvP-only поля
 * ([[reference_outfit_resistances_pvp_only]]); фракции резолвят картинку.
 *
 * @internal
 */
final class WikiContentServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');

        $db->query('DROP TABLE IF EXISTS outfits');
        $db->query('CREATE TABLE outfits (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            name_en VARCHAR(150) NOT NULL,
            description TEXT NULL,
            armor_type VARCHAR(50) NULL,
            slot VARCHAR(50) NULL,
            armor_value FLOAT NOT NULL DEFAULT 0,
            physical_resistance FLOAT NOT NULL DEFAULT 0,
            fire_resistance FLOAT NOT NULL DEFAULT 0,
            speed_modifier FLOAT NOT NULL DEFAULT 0,
            special_bonus VARCHAR(150) NULL,
            penalty VARCHAR(150) NULL,
            required_level INT NOT NULL DEFAULT 1,
            rarity VARCHAR(50) NULL,
            price INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->query('DROP TABLE IF EXISTS factions');
        $db->query('CREATE TABLE factions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT NULL,
            type ENUM("PVP","PVE") NOT NULL DEFAULT "PVE",
            unique_ability TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS outfits');
        $db->query('DROP TABLE IF EXISTS factions');
        parent::tearDown();
    }

    public function testOutfitsDoNotExposePvpOnlyFields(): void
    {
        Database::connect('tests')->query(
            "INSERT INTO outfits (name, name_en, armor_value, physical_resistance, fire_resistance, speed_modifier, special_bonus, penalty, required_level, rarity, price)
             VALUES ('Бункерная броня', 'BunkerArmor', 40, 50, 25, -10, 'secret', 'slow', 12, 'Legendary', 5000)"
        );

        $rows = (new WikiContentService())->outfits();

        $this->assertCount(1, $rows);
        $row = $rows[0];

        // Player-facing — присутствуют
        $this->assertSame('Бункерная броня', $row['name']);
        $this->assertSame(40.0, $row['armor_value']);
        $this->assertSame(12, $row['required_level']);

        // 🔴 PvP-only / dead — НЕ должны просочиться
        foreach (['physical_resistance', 'fire_resistance', 'poison_resistance', 'speed_modifier', 'stealth_modifier', 'special_bonus', 'penalty'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row, "Поле {$forbidden} не должно попадать в wiki (PvP-only/dead)");
        }
    }

    public function testFactionsProjectionAndImageResolution(): void
    {
        Database::connect('tests')->query(
            "INSERT INTO factions (name, description, type, unique_ability)
             VALUES ('Партизаны', 'Лесные диверсанты', 'PVP', 'Скрытность в лесу')"
        );

        $rows = (new WikiContentService())->factions();

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('Партизаны', $row['name']);
        $this->assertSame('Скрытность в лесу', $row['unique_ability']);
        // Картинка резолвится из ImageRegistry (faction/partisans, status=generated).
        $this->assertSame('uploads/telegram/faction/partisans.jpg', $row['image']);
    }

    public function testSectionUnknownKeyReturnsNull(): void
    {
        $this->assertNull((new WikiContentService())->section('does-not-exist'));
    }
}

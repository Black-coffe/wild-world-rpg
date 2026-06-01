<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\WipeManifest;

/**
 * 🔴 АНТИ-ДРИФТ ГЕЙТ механики вайпа (ADR-087).
 *
 * Жёсткий CI-гейт «ни одна таблица не упущена»: сканирует ВСЕ файлы миграций
 * (`createTable('X')`) и требует, чтобы КАЖДАЯ создаваемая таблица присутствовала
 * в {@see WipeManifest}. Новая миграция `CreateFooTable` без записи в манифесте →
 * этот тест красный → deploy заблокирован (composer test — baseline gate).
 *
 * DB-независим (чистый разбор исходников миграций) — работает в любой CI-полосе.
 *
 * @internal
 */
final class WipeManifestCoverageTest extends CIUnitTestCase
{
    private const VALID_STRATEGIES = [
        WipeManifest::KEEP,
        WipeManifest::PLAYER_DATA,
        WipeManifest::TRANSIENT,
        WipeManifest::CHARACTER_RESET,
        WipeManifest::IDENTITY_RESET,
        WipeManifest::SEED_RESET,
    ];

    /**
     * Имена идентичности персонажа, которые НИКОГДА не должны сбрасываться
     * (канон «имена и айдишки игроков остаются»).
     */
    private const PROTECTED_CHARACTER_COLUMNS = ['id', 'telegram_user_id', 'name', 'created_at'];

    /**
     * Главный гейт: каждая таблица, создаваемая миграцией, обязана быть в манифесте.
     */
    public function testEveryCreatedTableIsClassified(): void
    {
        $created  = $this->tablesCreatedByMigrations();
        $manifest = (new WipeManifest())->tables;

        $this->assertNotEmpty($created, 'Не найдено ни одного createTable() — путь к миграциям сломан?');

        $missing = [];
        foreach ($created as $table) {
            if (! array_key_exists($table, $manifest)) {
                $missing[] = $table;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "🔴 Таблицы создаются миграцией, но НЕ классифицированы в Config\\WipeManifest: "
            . implode(', ', $missing)
            . ". Добавь каждую в \$tables (strategy KEEP/PLAYER_DATA/TRANSIENT/CHARACTER_RESET/"
            . "IDENTITY_RESET/SEED_RESET) — иначе при вайпе она будет упущена. См. ADR-087."
        );
    }

    public function testEveryManifestEntryHasValidStrategy(): void
    {
        foreach ((new WipeManifest())->tables as $table => $d) {
            $this->assertArrayHasKey('strategy', $d, "Таблица {$table}: нет strategy");
            $this->assertContains(
                $d['strategy'],
                self::VALID_STRATEGIES,
                "Таблица {$table}: неизвестная strategy '{$d['strategy']}'"
            );
            $this->assertArrayHasKey('note', $d, "Таблица {$table}: нет note (нужно для preview-таблицы админки)");
        }
    }

    public function testPlayerDataEntriesHaveLink(): void
    {
        foreach ((new WipeManifest())->tables as $table => $d) {
            if ($d['strategy'] !== WipeManifest::PLAYER_DATA) {
                continue;
            }
            $this->assertArrayHasKey('link', $d, "PLAYER_DATA {$table}: нет link-колонки (нужна для single-char сброса)");
            $this->assertArrayHasKey('by', $d, "PLAYER_DATA {$table}: нет by (character|telegram)");
            $this->assertContains($d['by'], ['character', 'telegram'], "PLAYER_DATA {$table}: by должно быть character|telegram");
        }
    }

    public function testSeedAndIdentityResetHaveResetValues(): void
    {
        foreach ((new WipeManifest())->tables as $table => $d) {
            if ($d['strategy'] !== WipeManifest::SEED_RESET && $d['strategy'] !== WipeManifest::IDENTITY_RESET) {
                continue;
            }
            $this->assertArrayHasKey('reset', $d, "{$d['strategy']} {$table}: нет reset-значений");
            $this->assertNotEmpty($d['reset'], "{$d['strategy']} {$table}: reset пуст");
        }
    }

    public function testExactlyOneCharacterResetTable(): void
    {
        $charReset = array_filter(
            (new WipeManifest())->tables,
            static fn (array $d): bool => $d['strategy'] === WipeManifest::CHARACTER_RESET
        );
        $this->assertSame(['characters'], array_keys($charReset), 'CHARACTER_RESET должен быть ровно у characters');
    }

    public function testCharacterResetNeverTouchesIdentityColumns(): void
    {
        $values = (new WipeManifest())->characterResetValues;
        foreach (self::PROTECTED_CHARACTER_COLUMNS as $col) {
            $this->assertArrayNotHasKey(
                $col,
                $values,
                "🔴 characterResetValues НЕ должен трогать идентичность '{$col}' (канон: имена/id остаются)"
            );
        }
    }

    public function testCriticalTablesAreKept(): void
    {
        $manifest = (new WipeManifest())->tables;
        // Канон + здравый смысл: эти таблицы НИКОГДА не должны вайпиться.
        $mustKeep = [
            'map', 'images', 'biomes', 'world_objects', 'npcs',
            'game_settings', 'migrations', 'users', 'admin_audit_log',
            'character_names', 'crafted_items', 'weapons', 'outfits',
            'site_posts', 'achievements',
        ];
        foreach ($mustKeep as $table) {
            $this->assertArrayHasKey($table, $manifest, "Критичная таблица {$table} отсутствует в манифесте");
            $this->assertSame(
                WipeManifest::KEEP,
                $manifest[$table]['strategy'],
                "🔴 Критичная таблица {$table} ДОЛЖНА быть KEEP, а не '{$manifest[$table]['strategy']}'"
            );
        }
    }

    /**
     * Разбор всех файлов миграций: имена таблиц из `createTable('X')`.
     *
     * @return list<string>
     */
    private function tablesCreatedByMigrations(): array
    {
        $dir   = APPPATH . 'Database/Migrations';
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false) {
            return [];
        }

        $tables = [];
        foreach ($files as $file) {
            $src = file_get_contents($file);
            if ($src === false) {
                continue;
            }
            if (preg_match_all('/createTable\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $src, $m) !== false) {
                foreach ($m[1] as $name) {
                    $tables[$name] = true;
                }
            }
        }

        return array_keys($tables);
    }
}

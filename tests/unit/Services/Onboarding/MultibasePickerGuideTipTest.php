<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Database\Migrations\SeedMultibasePickerTip;
use App\Services\Onboarding\GuideCatalog;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * multibase-picker-07 — раздел «base» справочника + идемпотентный совет дня про
 * несколько баз и Вышку связи (GUIDE-COVERAGE / TIPS-COVERAGE, CLAUDE.md).
 *
 * @internal
 */
final class MultibasePickerGuideTipTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    public function testBaseSectionExplainsMultibasePickerAndTower(): void
    {
        $section = GuideCatalog::find('base');
        $this->assertNotNull($section, 'Раздел «База» обязан быть в /guide.');
        $body = $section['body'];

        foreach (['Вышк', 'связи', 'Ангар', 'Мастерская робототехники'] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $body,
                "Раздел «База» не упоминает «{$needle}» (несколько баз / Вышка связи)."
            );
        }

        // Без чисел баланса: единственные допустимые цифры — уже существующие шаги 1️⃣/2️⃣/3️⃣.
        $digits = [];
        preg_match_all('/\d+/', $body, $digits);
        foreach ($digits[0] as $digit) {
            $this->assertContains(
                (int) $digit,
                [1, 2, 3],
                "Раздел «База» не должен называть числа баланса (найдено «{$digit}»)."
            );
        }
    }

    public function testSeedMigrationInsertsIdempotentRobiTip(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS game_tips');
        $db->query(
            'CREATE TABLE game_tips (id INT AUTO_INCREMENT PRIMARY KEY, title_ru VARCHAR(255) NULL, '
            . 'title_en VARCHAR(255) NULL, tip_type VARCHAR(32) NULL, content TEXT NULL, '
            . 'created_at DATETIME NULL, updated_at DATETIME NULL) CHARACTER SET utf8mb4'
        );

        require_once APPPATH . 'Database/Migrations/2026-12-07-110000_SeedMultibasePickerTip.php';
        $migration = new SeedMultibasePickerTip();

        $migration->up();
        $migration->up(); // повторный запуск — не должен создать дубль

        $rows = $db->table('game_tips')->where('title_en', 'MultibasePicker')->get()->getResultArray();
        $this->assertCount(1, $rows, 'Повторный запуск seed-миграции не должен создавать дубль.');

        $tip = $rows[0];
        $this->assertSame('общие', $tip['tip_type'], 'Категория совета обязана быть «общие».');
        $this->assertStringContainsString('Вышка связи', $tip['content']);
        $this->assertSame(0, substr_count($tip['content'], '*') % 2, 'Markdown-эскейп: чётное число «*».');
        $this->assertDoesNotMatchRegularExpression('/\d/u', $tip['content'], 'Совет не должен называть числа баланса.');

        $migration->down();
        $left = $db->table('game_tips')->where('title_en', 'MultibasePicker')->get()->getResultArray();
        $this->assertCount(0, $left, 'down() обязан удалить совет по title_en.');

        $db->query('DROP TABLE IF EXISTS game_tips');
    }
}

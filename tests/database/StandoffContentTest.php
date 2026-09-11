<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\Adr186SeedStandoffTip;
use App\Database\Migrations\CreateGameTipsTable;
use App\Database\Migrations\TipsAExtendCategories;
use App\Services\Onboarding\GuideCatalog;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-186 (pvp-detection-clarity-11) — GUIDE-coverage (раздел «standoff») и
 * TIPS-coverage (`BaseStandoffAlert`, категория `бой`).
 *
 * `game_tips` поднимается прогоном настоящих миграций (`CreateGameTipsTable` +
 * `TipsAExtendCategories`), а не ручным `CREATE TABLE` — прод несёт `tip_type` как
 * NOT NULL ENUM из 14 значений, и ручная схема легко разъедется по этому же классу
 * ошибок, что и `community_messages` (память `feedback_test_schema_must_come_from_migration`).
 *
 * Изолированная тестовая БД задаётся снаружи через
 * `env "database.tests.database=<своя>"` — общий локальный `wildworld_tests`
 * не трогается (известная поломка `map.id`, story не про карту).
 *
 * @internal
 */
final class StandoffContentTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private bool $createdTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        // uncached lookup: `tableExists()` по умолчанию читает `listTables()` из кэша
        // соединения, а соединение переживает между тестами — DROP предыдущего теста
        // остаётся невидим кэшу, и следующий тест решает, что таблица уже есть, хотя
        // физически её больше нет (находка этой story, не готовый рецепт из памяти).
        if (! $db->tableExists('game_tips', false)) {
            $this->requireMigrationClasses();
            $forge = Database::forge('tests');
            $forgeArg = $forge instanceof Forge ? $forge : null;
            (new CreateGameTipsTable($forgeArg))->up();
            (new TipsAExtendCategories($forgeArg))->up();
            $this->createdTable = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdTable) {
            $db = Database::connect('tests');
            $db->query('DROP TABLE IF EXISTS game_tips');
        }

        parent::tearDown();
    }

    private function requireMigrationClasses(): void
    {
        if (! class_exists(CreateGameTipsTable::class, false)) {
            require_once APPPATH . 'Database/Migrations/2024-04-22-133908_CreateGameTipsTable.php';
        }
        if (! class_exists(TipsAExtendCategories::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-05-22-300000_TipsAExtendCategories.php';
        }
        if (! class_exists(Adr186SeedStandoffTip::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-09-12-000000_Adr186SeedStandoffTip.php';
        }
    }

    // ── GUIDE-coverage ──────────────────────────────────────────────────────

    public function testStandoffSectionExistsAndIsKeyedValidly(): void
    {
        $section = GuideCatalog::find('standoff');
        $this->assertNotNull($section, 'Раздел «Нападение на базу» (standoff) обязан быть в /guide.');
        $this->assertMatchesRegularExpression('/^[a-z]+$/', $section['key'], 'Ключ раздела — только [a-z], без "_" и цифр.');
        $this->assertSame('end', $section['group'], 'Нападение на базу — эндгейм-механика полевого PvP.');
    }

    public function testStandoffSectionExplainsThreeMovesAndFiniteWindow(): void
    {
        $section = GuideCatalog::find('standoff');
        $this->assertNotNull($section);
        $text = $section['title'] . $section['body'];

        // Три хода защитника — их подписи из реального сообщения тревоги (StandoffNotifier).
        foreach (['Укрыться', 'Убежать', 'Ударить первым'] as $needle) {
            $this->assertStringContainsString($needle, $text, "Раздел «Нападение на базу» не называет ход «{$needle}».");
        }

        // Экран ожидания атакующего — те же подписи, что реально уходят игроку.
        foreach (['Проверить', 'Уйти'] as $needle) {
            $this->assertStringContainsString($needle, $text, "Раздел «Нападение на базу» не называет кнопку «{$needle}» экрана ожидания.");
        }

        // Тревога — разовое право на реакцию, а не постоянный щит; окно конечно.
        $this->assertStringContainsStringIgnoringCase('разовое', $text, 'Раздел обязан сказать, что тревога — разовое право, а не постоянный щит.');
        $hasFiniteWindowMarker = false;
        foreach (['время выходит', 'время выйдет', 'конечна'] as $marker) {
            if (mb_stripos($text, $marker) !== false) {
                $hasFiniteWindowMarker = true;
                break;
            }
        }
        $this->assertTrue($hasFiniteWindowMarker, 'Раздел обязан сказать, что окно конечно и после него бой состоится.');
    }

    public function testStandoffSectionHasNoBalanceNumbers(): void
    {
        $section = GuideCatalog::find('standoff');
        $this->assertNotNull($section);

        // Числа баланса (длительность окна, кулдаун, проценты) живут в GameSettings — их не дублируем.
        $this->assertDoesNotMatchRegularExpression('/\d/u', $section['body'], 'Раздел «Нападение на базу» не должен называть числа баланса.');
    }

    public function testStandoffSectionMarkdownIsBalanced(): void
    {
        $section = GuideCatalog::find('standoff');
        $this->assertNotNull($section);
        $text = $section['title'] . $section['body'];

        $this->assertSame(0, substr_count($text, '*') % 2, 'Несбалансированные «*» в разделе «Нападение на базу».');
    }

    // ── TIPS-coverage ───────────────────────────────────────────────────────

    public function testTipSeedIsIdempotent(): void
    {
        $migration = new Adr186SeedStandoffTip();
        $migration->up();
        $migration->up(); // повторный прогон — не должно быть второй строки

        $db   = Database::connect('tests');
        $rows = $db->table('game_tips')->where('title_en', 'BaseStandoffAlert')->get()->getResultArray();

        $this->assertCount(1, $rows, 'Повторный прогон миграции не должен плодить дубли.');
    }

    public function testTipHasValidCategoryAndBalancedMarkdown(): void
    {
        $migration = new Adr186SeedStandoffTip();
        $migration->up();

        $allowed = ['биомы', 'ресурсы', 'крафт', 'персонаж', 'события', 'NPC', 'общие', 'земледелие',
            'еда', 'квесты', 'фракции', 'бой', 'эндгейм', 'настройки'];

        $db  = Database::connect('tests');
        $row = $db->table('game_tips')->where('title_en', 'BaseStandoffAlert')->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('бой', $row['tip_type']);
        $this->assertContains($row['tip_type'], $allowed);
        $this->assertSame(0, substr_count($row['content'], '*') % 2, 'markdown * должны быть парными');
    }

    public function testTipMentionsThreeMovesWithoutBalanceNumbers(): void
    {
        $migration = new Adr186SeedStandoffTip();
        $migration->up();

        $db  = Database::connect('tests');
        $row = $db->table('game_tips')->where('title_en', 'BaseStandoffAlert')->get()->getRowArray();
        $this->assertNotNull($row);

        foreach (['Укрыться', 'Убежать', 'Ударить первым'] as $needle) {
            $this->assertStringContainsString($needle, $row['content'], "Совет не называет ход «{$needle}».");
        }

        $this->assertDoesNotMatchRegularExpression('/\d/u', $row['content'], 'Совет не должен называть числа баланса.');
    }
}

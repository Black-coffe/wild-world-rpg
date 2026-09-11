<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\Adr164SeedFieldPvpTip;
use App\Database\Migrations\Adr186FixFieldPvpTip;
use App\Database\Migrations\CreateGameTipsTable;
use App\Database\Migrations\TipsAExtendCategories;
use App\Services\Onboarding\GuideCatalog;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * pvp-detection-clarity-05 — четыре текста про PvP перестают врать.
 *
 * Честность формулировки тестом не проверяется — это выносят Редколлегия и Tier-3 (brief.md
 * стенограмма). Здесь только то, что машина реально может проверить: раздел `settings` больше
 * не несёт запрещённую формулировку «тумблер = полевой PvP», и миграция `Adr186FixFieldPvpTip`
 * идемпотентно переписывает `content` совета `FieldPvpAndArena` ровно по одной строке, без
 * дублей при повторном прогоне.
 *
 * Схема (`game_tips`) строится прогоном настоящих классов миграций
 * (`Adr164SeedFieldPvpTip` — реальный prod-сид, старую миграцию тест не трогает, только
 * запускает её `up()` как фикстуру) — только если таблицы ещё нет; CI гоняет набор на пустой
 * базе, локальный стенд её обычно уже несёт персистентно.
 *
 * @internal
 */
final class PvpTextsHonestyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private \CodeIgniter\Database\BaseConnection $conn;

    private bool $createdGameTips = false;
    private bool $seededOriginalTip = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');
        $this->conn->resetDataCache();

        if (! $this->conn->tableExists('game_tips')) {
            $this->requireMigration('CreateGameTipsTable', '2024-04-22-133908_CreateGameTipsTable.php');
            $forge = Database::forge('tests');
            (new CreateGameTipsTable($forge instanceof Forge ? $forge : null))->up();
            $this->conn->resetDataCache();

            $this->requireMigration('TipsAExtendCategories', '2026-05-22-300000_TipsAExtendCategories.php');
            $forge = Database::forge('tests');
            (new TipsAExtendCategories($forge instanceof Forge ? $forge : null))->up();
            $this->conn->resetDataCache();

            $this->createdGameTips = true;
        }

        $existing = $this->conn->table('game_tips')->where('title_en', 'FieldPvpAndArena')->get()->getRowArray();
        if (empty($existing)) {
            $this->requireMigration('Adr164SeedFieldPvpTip', '2026-11-07-110000_Adr164SeedFieldPvpTip.php');
            $forge = Database::forge('tests');
            (new Adr164SeedFieldPvpTip($forge instanceof Forge ? $forge : null))->up();
            $this->seededOriginalTip = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->seededOriginalTip) {
            $this->conn->table('game_tips')->where('title_en', 'FieldPvpAndArena')->delete();
        }

        if ($this->createdGameTips) {
            $this->requireMigration('TipsAExtendCategories', '2026-05-22-300000_TipsAExtendCategories.php');
            $forge = Database::forge('tests');
            (new TipsAExtendCategories($forge instanceof Forge ? $forge : null))->down();

            $this->requireMigration('CreateGameTipsTable', '2024-04-22-133908_CreateGameTipsTable.php');
            $forge = Database::forge('tests');
            (new CreateGameTipsTable($forge instanceof Forge ? $forge : null))->down();
        }

        parent::tearDown();
    }

    private function requireMigration(string $shortClass, string $file): void
    {
        $class = 'App\\Database\\Migrations\\' . $shortClass;
        if (! class_exists($class, false)) {
            require_once APPPATH . 'Database/Migrations/' . $file;
        }
    }

    // ── GuideCatalog: раздел settings больше не врёт ────────────────────────

    public function testSettingsSectionDoesNotClaimToggleGatesFieldPvp(): void
    {
        $sections = GuideCatalog::sections();
        $settings = null;
        foreach ($sections as $section) {
            if ($section['key'] === 'settings') {
                $settings = $section;
                break;
            }
        }

        $this->assertNotNull($settings, 'Раздел settings обязан существовать в каталоге.');
        $this->assertStringNotContainsString(
            'пускать ли тебя на арену/в полевой PvP',
            $settings['body'],
            'Раздел settings всё ещё утверждает, что тумблер пускает/не пускает в полевой PvP.'
        );
        $this->assertStringContainsString(
            'Полевой бой на карте он не выключает',
            $settings['body'],
            'Раздел settings обязан прямо сказать, что тумблер не выключает полевой бой.'
        );
    }

    public function testArenaSectionMentionsLockBeforeAttack(): void
    {
        $sections = GuideCatalog::sections();
        $arena = null;
        foreach ($sections as $section) {
            if ($section['key'] === 'arena') {
                $arena = $section;
                break;
            }
        }

        $this->assertNotNull($arena, 'Раздел arena обязан существовать в каталоге.');
        $this->assertStringContainsString(
            'замок с причиной',
            $arena['body'],
            'Раздел arena обязан объяснять, что часть соседей закрыта для атаки замком, видимым до тапа.'
        );
        $this->assertStringContainsString(
            'Полевой бой тумблером не отключается',
            $arena['body'],
            'Раздел arena обязан оставаться честным про то, что тумблер полевой бой не отключает.'
        );
    }

    // ── Миграция: content переписывается идемпотентно ───────────────────────

    public function testMigrationRewritesTipContentAndDropsToggleClaim(): void
    {
        $before = $this->conn->table('game_tips')->where('title_en', 'FieldPvpAndArena')->get()->getRowArray();
        $this->assertIsArray($before);
        $this->assertStringContainsString('держи тумблер', $before['content']);

        $this->requireMigration('Adr186FixFieldPvpTip', '2026-09-11-230000_Adr186FixFieldPvpTip.php');
        $forge = Database::forge('tests');
        (new Adr186FixFieldPvpTip($forge instanceof Forge ? $forge : null))->up();

        $rows = $this->conn->table('game_tips')->where('title_en', 'FieldPvpAndArena')->get()->getResultArray();
        $this->assertCount(1, $rows, 'Миграция обязана оставить ровно одну строку по title_en.');

        $after = $rows[0];
        $this->assertStringNotContainsString(
            'Не хочешь драк — держи тумблер',
            $after['content'],
            'Ложная фраза про тумблер как способ не драться обязана уйти.'
        );
        $this->assertStringContainsString(
            'столкнёшься с другим выжившим на карте',
            $after['content'],
            'Честный абзац про столкновение на карте без согласия обязан остаться.'
        );
        $this->assertSame('бой', $after['tip_type'], 'Категория совета не должна меняться.');
        $this->assertSame(0, substr_count($after['content'], '*') % 2, 'Markdown «*» в новом content обязан быть парным.');
    }

    public function testMigrationIsIdempotentOnRepeatedRun(): void
    {
        $this->requireMigration('Adr186FixFieldPvpTip', '2026-09-11-230000_Adr186FixFieldPvpTip.php');
        $forge = Database::forge('tests');
        (new Adr186FixFieldPvpTip($forge instanceof Forge ? $forge : null))->up();
        $firstRun = $this->conn->table('game_tips')->where('title_en', 'FieldPvpAndArena')->get()->getResultArray();
        $this->assertCount(1, $firstRun);
        $contentAfterFirst = $firstRun[0]['content'];

        // Повторный прогон не плодит дублей и не меняет содержимое ещё раз.
        $forge = Database::forge('tests');
        (new Adr186FixFieldPvpTip($forge instanceof Forge ? $forge : null))->up();
        $secondRun = $this->conn->table('game_tips')->where('title_en', 'FieldPvpAndArena')->get()->getResultArray();

        $this->assertCount(1, $secondRun, 'Повторный прогон миграции не должен плодить строки.');
        $this->assertSame($contentAfterFirst, $secondRun[0]['content']);
    }
}

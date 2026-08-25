<?php

declare(strict_types=1);

namespace Tests\Database\Community;

use App\Database\Migrations\Adr176CreateCommunityAnswersTable;
use App\Database\Migrations\CreateGameTipsTable;
use App\Database\Migrations\CreateSitePostsTable;
use App\Models\CommunityAnswerModel;
use App\Models\GameTipsModel;
use App\Models\SitePostModel;
use App\Services\Community\CommunityGuard;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionMethod;

/**
 * Story community-chat-bot-64 — ADR-177 §2/§3 сузили белый корпус
 * `CommunityGuard::defaultCorpus()` до `GuideCatalog::sections()` + `game_tips`;
 * `site_posts` исключены намеренно (жанр без обязательства актуальности),
 * `community_answers` не входят никогда (инвариант анти-храповика — корпус не
 * питается собственным выходом бота).
 *
 * Доказывается исполнением на настоящей схеме, поднятой прогоном реальных
 * миграций через Forge (не ручным `CREATE TABLE` —
 * feedback_test_schema_must_come_from_migration: самодельная схема уже расходилась
 * с продом по четырём пунктам и семь тестов были зелёными зря), и чтением
 * private-метода `defaultCorpus()` через reflection (не сканом исходника —
 * feedback_source_scan_tests_are_not_coverage: `grep`, не нашедший `SitePostModel`,
 * останется зелёным и если метод сборки корпуса сломан целиком).
 *
 * BLOCK-инцидент, который породил эту story: под PHPUnit гвард видел 32
 * фрагмента из боевых 133, потому что в тестовой БД не было `game_tips` —
 * поэтому здесь таблицы сеются, а не подразумеваются.
 *
 * @internal
 */
final class CommunityGuardCorpusTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private bool $createdGameTips  = false;
    private bool $createdSitePosts = false;
    private bool $createdAnswers   = false;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');

        if (! $db->tableExists('game_tips')) {
            $this->requireMigration(CreateGameTipsTable::class, '2024-04-22-133908_CreateGameTipsTable.php');
            (new CreateGameTipsTable($this->forge()))->up();
            $this->createdGameTips = true;
        }

        if (! $db->tableExists('site_posts')) {
            $this->requireMigration(CreateSitePostsTable::class, '2026-05-25-180100_CreateSitePostsTable.php');
            (new CreateSitePostsTable($this->forge()))->up();
            $this->createdSitePosts = true;
        }

        if (! $db->tableExists('community_answers')) {
            $this->requireMigration(Adr176CreateCommunityAnswersTable::class, '2026-08-25-100100_Adr176CreateCommunityAnswersTable.php');
            (new Adr176CreateCommunityAnswersTable($this->forge()))->up();
            $this->createdAnswers = true;
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');

        if ($this->createdGameTips) {
            (new CreateGameTipsTable($this->forge()))->down();
        } else {
            $db->table('game_tips')->truncate();
        }

        if ($this->createdSitePosts) {
            (new CreateSitePostsTable($this->forge()))->down();
        } else {
            $db->table('site_posts')->truncate();
        }

        if ($this->createdAnswers) {
            (new Adr176CreateCommunityAnswersTable($this->forge()))->down();
        } else {
            $db->table('community_answers')->truncate();
        }

        parent::tearDown();
    }

    private function forge(): ?Forge
    {
        $forge = Database::forge('tests');

        return $forge instanceof Forge ? $forge : null;
    }

    private function requireMigration(string $class, string $file): void
    {
        if (! class_exists($class, false)) {
            require_once APPPATH . 'Database/Migrations/' . $file;
        }
    }

    /**
     * `defaultCorpus()` — private-метод, вызываемый только из конструктора при
     * `corpus: null`. Reflection здесь — единственный способ прогнать РЕАЛЬНОЕ
     * тело сборки на реальной БД, не копируя его логику в тест (копия доказывала
     * бы копию, а не код).
     *
     * @return list<array{source: string, text: string}>
     */
    private function defaultCorpus(): array
    {
        $guard  = new CommunityGuard([]);
        $method = new ReflectionMethod(CommunityGuard::class, 'defaultCorpus');
        $method->setAccessible(true);

        /** @var list<array{source: string, text: string}> $corpus */
        $corpus = $method->invoke($guard);

        return $corpus;
    }

    private function corpusText(): string
    {
        return implode("\n", array_column($this->defaultCorpus(), 'text'));
    }

    /**
     * Acceptance: сеет один `game_tips`, один `site_posts`
     * (`canon_reviewed=1`, `status='published'`) и один одобренный
     * `community_answers` — в собранном корпусе есть текст совета, нет текста
     * статьи, нет текста одобренного ответа.
     *
     * Красная проверка: если в `defaultCorpus()` вернуть цикл по `SitePostModel`
     * (как было до ADR-177), маркер статьи попадёт в `corpusText()` и вторая
     * ассерция этого теста упадёт — она не бесполезна на «уже и так пусто».
     */
    public function testCorpusIncludesTipsExcludesSitePostsAndApprovedAnswers(): void
    {
        (new GameTipsModel())->insert([
            'title_ru' => 'Тестовый совет story 64',
            'title_en' => 'Story64Tip',
            'tip_type' => 'общие',
            'content'  => 'УникальныйМаркерСоветаStory64',
        ]);

        (new SitePostModel())->insert([
            'slug'           => 'story-64-corpus-post',
            'title'          => 'Тестовая статья story 64',
            'content_html'   => 'УникальныйМаркерСтатьиStory64',
            'status'         => 'published',
            'canon_reviewed' => 1,
        ]);

        (new CommunityAnswerModel())->insert([
            'client_key'       => 'story64-approved-answer',
            'question_pattern' => 'где найти воду',
            'answer_text'      => 'УникальныйМаркерОтветаStory64',
            'requires_setting' => null,
            'source_ref'       => 'guide:move',
            'status'           => 'approved',
            'approved_at'      => date('Y-m-d H:i:s'),
            'approved_by'      => 'owner',
        ]);

        $text = $this->corpusText();

        $this->assertStringContainsString(
            'УникальныйМаркерСоветаStory64',
            $text,
            'game_tips обязан входить в дефолтный корпус (ADR-177 §3)'
        );
        $this->assertStringNotContainsString(
            'УникальныйМаркерСтатьиStory64',
            $text,
            'ADR-177 §3: site_posts исключены из корпуса намеренно — жанр без обязательства актуальности'
        );
        $this->assertStringNotContainsString(
            'УникальныйМаркерОтветаStory64',
            $text,
            'инвариант анти-храповика ADR-177 §3: одобренный community_answers не должен питать корпус собственным выходом'
        );
    }

    public function testGuideCatalogSectionsArePresentInCorpus(): void
    {
        $sources = array_column($this->defaultCorpus(), 'source');
        $guideSources = array_filter($sources, static fn (string $s): bool => str_starts_with($s, 'guide:'));

        $this->assertNotEmpty($guideSources, 'GuideCatalog::sections() обязан попадать в дефолтный корпус — ADR-177 §3');
    }
}

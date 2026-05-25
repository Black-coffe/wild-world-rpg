<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\SitePostCategoryModel;
use App\Models\SitePostModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-050 — модели CMS публичного сайта: валидация постов, уникальность slug,
 * лента опубликованного, M:N синхронизация категорий.
 *
 * Паттерн репо: $migrate=false + ручное создание минимальных таблиц в setUp
 * (cf. CharacterRepositoryAdjustGoldTest) — полная миграционная цепочка не нужна.
 *
 * @internal
 */
final class SiteCmsModelsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');

        $db->query('DROP TABLE IF EXISTS site_posts');
        $db->query('CREATE TABLE site_posts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wp_post_id INT UNSIGNED NULL,
            slug VARCHAR(200) NOT NULL,
            title VARCHAR(255) NOT NULL,
            excerpt TEXT NULL,
            content_html MEDIUMTEXT NOT NULL,
            featured_image VARCHAR(255) NULL,
            meta_description VARCHAR(320) NULL,
            status ENUM("draft","published") NOT NULL DEFAULT "draft",
            published_at DATETIME NULL,
            canon_reviewed TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->query('DROP TABLE IF EXISTS site_post_categories');
        $db->query('CREATE TABLE site_post_categories (
            post_id INT UNSIGNED NOT NULL,
            category_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (post_id, category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS site_posts');
        $db->query('DROP TABLE IF EXISTS site_post_categories');
        parent::tearDown();
    }

    public function testInsertsValidPostWithCyrillicAndEmoji(): void
    {
        $id = (new SitePostModel())->insert([
            'slug'         => 'test-post',
            'title'        => 'Арсенал ⛏️',
            'content_html' => '<p>Тело статьи</p>',
            'status'       => 'published',
            'published_at' => '2025-02-08 12:00:00',
        ]);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        $row = (new SitePostModel())->find($id);
        $this->assertSame('Арсенал ⛏️', $row['title']);
    }

    public function testRejectsDuplicateSlug(): void
    {
        (new SitePostModel())->insert([
            'slug' => 'dup', 'title' => 'A', 'content_html' => '<p>a</p>', 'status' => 'draft',
        ]);

        $second = new SitePostModel();
        $ok     = $second->insert([
            'slug' => 'dup', 'title' => 'B', 'content_html' => '<p>b</p>', 'status' => 'draft',
        ]);

        $this->assertFalse($ok);
        $this->assertArrayHasKey('slug', $second->errors());
    }

    public function testRejectsMissingContent(): void
    {
        $model = new SitePostModel();
        $ok    = $model->insert(['slug' => 'no-body', 'title' => 'X', 'status' => 'draft']);

        $this->assertFalse($ok);
        $this->assertArrayHasKey('content_html', $model->errors());
    }

    public function testRejectsInvalidStatus(): void
    {
        $model = new SitePostModel();
        $ok    = $model->insert([
            'slug' => 'bad-status', 'title' => 'X', 'content_html' => '<p>x</p>', 'status' => 'archived',
        ]);

        $this->assertFalse($ok);
        $this->assertArrayHasKey('status', $model->errors());
    }

    public function testPublishedFeedReturnsNewestFirstAndHidesDrafts(): void
    {
        (new SitePostModel())->insert([
            'slug' => 'old', 'title' => 'Old', 'content_html' => '<p>o</p>',
            'status' => 'published', 'published_at' => '2024-01-01 00:00:00',
        ]);
        (new SitePostModel())->insert([
            'slug' => 'new', 'title' => 'New', 'content_html' => '<p>n</p>',
            'status' => 'published', 'published_at' => '2025-01-01 00:00:00',
        ]);
        (new SitePostModel())->insert([
            'slug' => 'hidden', 'title' => 'Draft', 'content_html' => '<p>d</p>', 'status' => 'draft',
        ]);

        $feed = (new SitePostModel())->publishedFeed();

        $this->assertCount(2, $feed);
        $this->assertSame('new', $feed[0]['slug']);
        $this->assertSame('old', $feed[1]['slug']);
    }

    public function testSyncForPostCreatesAndReplacesCategories(): void
    {
        (new SitePostCategoryModel())->syncForPost(10, [1, 2, 3]);
        $this->assertSame([1, 2, 3], (new SitePostCategoryModel())->categoryIdsForPost(10));

        // повторная синхронизация заменяет набор
        (new SitePostCategoryModel())->syncForPost(10, [2, 4]);
        $this->assertSame([2, 4], (new SitePostCategoryModel())->categoryIdsForPost(10));

        // дубли схлопываются (композитный PK)
        (new SitePostCategoryModel())->syncForPost(11, [5, 5, 5]);
        $this->assertSame([5], (new SitePostCategoryModel())->categoryIdsForPost(11));
    }
}

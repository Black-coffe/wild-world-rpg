<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Front;
use App\Models\SitePostModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-052 — admin-only превью черновика (Front::preview) для UI/UX-гейта редколлегии.
 *
 * Тестируем НОВУЮ логику через устойчивый seam (без HTTP/фильтров — в репо нет
 * feature-тестов с admin-auth): 404-путь preview (чистая логика, без view-рендера)
 * + контракт данных, на котором держится гейт — draft виден preview'ю (find), но
 * скрыт из публичной ленты (publishedFeed). Сам рендер использует общий
 * Front::renderPost, покрытый каждой опубликованной статьёй.
 *
 * Паттерн репо: $migrate=false + ручное создание таблицы в setUp (cf. SiteCmsModelsTest).
 *
 * @internal
 */
final class SitePostPreviewTest extends CIUnitTestCase
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
    }

    protected function tearDown(): void
    {
        Database::connect('tests')->query('DROP TABLE IF EXISTS site_posts');
        parent::tearDown();
    }

    public function testPreviewThrowsNotFoundForMissingPost(): void
    {
        $this->expectException(PageNotFoundException::class);
        (new Front())->preview(999999);
    }

    public function testDraftIsFindableForPreviewButHiddenFromPublicFeed(): void
    {
        $id = (new SitePostModel())->insert([
            'slug'         => 'draft-under-review',
            'title'        => 'Черновик на ревью',
            'content_html' => '<p>Тело черновика</p>',
            'status'       => 'draft',
            'published_at' => '2026-05-31 20:00:00',
        ]);
        $this->assertIsInt($id);

        // Preview ОБЯЗАН видеть черновик (рендерит его по id, статус-агностично).
        $row = (new SitePostModel())->find($id);
        $this->assertIsArray($row);
        $this->assertSame('draft', $row['status']);

        // Публичная лента ОБЯЗАНА скрывать черновик — превью единственный способ его увидеть.
        $feed = (new SitePostModel())->publishedFeed();
        $slugs = array_map(static fn ($p): string => is_string($p['slug'] ?? null) ? $p['slug'] : '', $feed);
        $this->assertNotContains('draft-under-review', $slugs);
    }
}

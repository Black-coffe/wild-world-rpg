<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\SiteRedirectModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-052 — таблица 301/302-редиректов (SEO-преемственность при переезде с WP).
 *
 * @internal
 */
final class SiteRedirectModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS site_redirects');
        $db->query('CREATE TABLE site_redirects (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            from_path VARCHAR(255) NOT NULL,
            to_path VARCHAR(255) NOT NULL,
            code SMALLINT NOT NULL DEFAULT 301,
            hits INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY from_path (from_path)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    protected function tearDown(): void
    {
        Database::connect('tests')->query('DROP TABLE IF EXISTS site_redirects');
        parent::tearDown();
    }

    public function testMatchPathReturnsRedirectIncludingCyrillic(): void
    {
        $model = new SiteRedirectModel();
        $model->insert(['from_path' => '/блог', 'to_path' => '/devblog', 'code' => 301]);

        $row = $model->matchPath('/блог');
        $this->assertNotNull($row);
        $this->assertSame('/devblog', $row['to_path']);
        $this->assertSame(301, $row['code']);
    }

    public function testMatchPathReturnsNullForUnknown(): void
    {
        $this->assertNull((new SiteRedirectModel())->matchPath('/does-not-exist'));
    }

    public function testRecordHitIncrements(): void
    {
        $model = new SiteRedirectModel();
        $id    = (int) $model->insert(['from_path' => '/контакты', 'to_path' => '/contacts', 'code' => 301]);

        $model->recordHit($id);
        $model->recordHit($id);

        $row = (new SiteRedirectModel())->find($id);
        $this->assertSame(2, (int) $row['hits']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use App\Database\Migrations\SeedTransportTip;
use App\Database\Migrations\UpdateMarchSpeedTipTransportAware;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * transport-13 — совет о транспорте (idempotent) и правка живого MarchSpeed (правда про
 * зависимость темпа от машины, не про «темп постоянный»). Изолированная схема `game_tips`,
 * как у DailyTipBroadcastHandlerTest — миграции запускаются напрямую через ->up().
 *
 * @internal
 */
final class TransportTipsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private \CodeIgniter\Database\BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();

        require_once APPPATH . 'Database/Migrations/2026-11-30-100000_SeedTransportTip.php';
        require_once APPPATH . 'Database/Migrations/2026-11-30-110000_UpdateMarchSpeedTipTransportAware.php';

        $this->conn = Database::connect('tests');
        $this->conn->query('DROP TABLE IF EXISTS game_tips');
        $this->conn->query('
            CREATE TABLE game_tips (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title_ru VARCHAR(255) NULL,
                title_en VARCHAR(255) NULL,
                tip_type VARCHAR(32) NULL,
                content TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        // Совет MarchSpeed уже живёт на проде до релиза — сеем его старым (лживым) текстом,
        // чтобы UPDATE-миграция было над чем работать.
        $this->conn->table('game_tips')->insert([
            'title_ru'   => '🚜 Скорость Похода',
            'title_en'   => 'MarchSpeed',
            'tip_type'   => 'общие',
            'content'    => '🚜 *Почему Поход идёт с одной скоростью?* Отряд движется '
                . 'ровным шагом и довольно быстро, и темп *постоянный* — он не ускоряется от '
                . 'высокого здоровья или выносливости. ❤️ и 💤 — это *топливо* в пути.',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->conn->query('DROP TABLE IF EXISTS game_tips');
    }

    public function testTransportTipSeedIsIdempotent(): void
    {
        $migration = new SeedTransportTip();
        $migration->up();
        $migration->up(); // повторный прогон — не должно быть второй строки

        $rows = $this->conn->table('game_tips')->where('title_en', 'VehicleIntro')->get()->getResultArray();

        $this->assertCount(1, $rows);
        $this->assertSame('общие', $rows[0]['tip_type']);
    }

    public function testTransportTipHasValidCategoryAndBalancedMarkdown(): void
    {
        $migration = new SeedTransportTip();
        $migration->up();

        $allowed = ['биомы', 'ресурсы', 'крафт', 'персонаж', 'события', 'NPC', 'общие', 'земледелие',
            'еда', 'квесты', 'фракции', 'бой', 'эндгейм', 'настройки'];

        $row = $this->conn->table('game_tips')->where('title_en', 'VehicleIntro')->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertContains($row['tip_type'], $allowed);
        $this->assertSame(0, substr_count($row['content'], '*') % 2, 'markdown * должны быть парными');

        // Совет не должен утверждать «темп постоянный» — это ровно та ложь, из-за которой
        // обновляется MarchSpeed.
        $this->assertStringNotContainsString('темп *постоянный*', $row['content']);
    }

    public function testMarchSpeedTipUpdatedNotDuplicated(): void
    {
        $migration = new UpdateMarchSpeedTipTransportAware();
        $migration->up();
        $migration->up(); // повторный прогон — UPDATE, не INSERT

        $rows = $this->conn->table('game_tips')->where('title_en', 'MarchSpeed')->get()->getResultArray();

        $this->assertCount(1, $rows, 'MarchSpeed обязан остаться одной строкой после двойного прогона');
    }

    public function testMarchSpeedTipNoLongerClaimsConstantPace(): void
    {
        $migration = new UpdateMarchSpeedTipTransportAware();
        $migration->up();

        $row = $this->conn->table('game_tips')->where('title_en', 'MarchSpeed')->get()->getRowArray();

        $this->assertNotNull($row);
        // Красный тест, если совет снова утверждает «темп одинаков у всех» / «постоянный».
        $this->assertStringNotContainsString('темп *постоянный*', $row['content']);
        $this->assertStringNotContainsString('с одной скоростью', $row['content']);

        // Верный смысл про топливо, а не двигатель, обязан остаться.
        $this->assertStringContainsString('топливо', $row['content']);
        $this->assertStringContainsString('двигатель', $row['content']);

        // Правда про транспорт как источник темпа обязана появиться.
        $this->assertStringContainsString('транспорт', mb_strtolower($row['content']));
        $this->assertSame(0, substr_count($row['content'], '*') % 2, 'markdown * должны быть парными');
    }
}

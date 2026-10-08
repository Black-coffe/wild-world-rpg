<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\CreateSiteCategoriesTable;
use App\Database\Migrations\CreateSiteRedirectsTable;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

/**
 * w2-n7-combat-02 (поправка владельца 2026-10-08) — публичная страница боя `/battles/view/{id}` отдаёт только PvP,
 * как и публичный список `/battles`. Дуэли и PvE видят лишь их участники (журнал боёв): чужой тип по номеру — тот же
 * 404, что и несуществующий бой. Класс — docs/defects/record-exposed-beyond-participants.md.
 *
 * `site/battles/view` рендерит общий layout (читает `site_categories`), 404 сайта сверяет путь с `site_redirects`:
 * обе таблицы строятся настоящими миграциями, если их нет, и сносятся только тогда.
 *
 * @internal
 */
final class BattlesPublicViewTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private BaseConnection $conn;

    private bool $createdSiteCategories = false;

    private bool $createdSiteRedirects = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->conn->query('DROP TABLE IF EXISTS battle_logs');
        $this->conn->query(
            'CREATE TABLE battle_logs (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, battle_type VARCHAR(8) NOT NULL,'
            . ' player1_id INT NULL, player2_id INT NULL, winner_id INT NULL, created_at DATETIME NOT NULL,'
            . ' finished_at DATETIME NOT NULL, log_data LONGTEXT NOT NULL)'
        );
        $this->conn->resetDataCache();
        $this->createdSiteCategories = ! $this->conn->tableExists('site_categories');
        if ($this->createdSiteCategories) {
            require_once APPPATH . 'Database/Migrations/2026-05-25-180000_CreateSiteCategoriesTable.php';
            $forge = Database::forge();
            (new CreateSiteCategoriesTable($forge instanceof Forge ? $forge : null))->up();
        }
        // 404 сайта (`Errors::show404`) сверяет путь с таблицей редиректов.
        $this->createdSiteRedirects = ! $this->conn->tableExists('site_redirects');
        if ($this->createdSiteRedirects) {
            require_once APPPATH . 'Database/Migrations/2026-05-25-180400_CreateSiteRedirectsTable.php';
            $forge = Database::forge();
            (new CreateSiteRedirectsTable($forge instanceof Forge ? $forge : null))->up();
        }
    }

    protected function tearDown(): void
    {
        $this->conn->query('DROP TABLE IF EXISTS battle_logs');
        if ($this->createdSiteCategories) {
            $this->conn->query('DROP TABLE IF EXISTS site_categories');
        }
        if ($this->createdSiteRedirects) {
            $this->conn->query('DROP TABLE IF EXISTS site_redirects');
        }
        $this->conn->resetDataCache();
        parent::tearDown();
    }

    public function testPvpBattleIsPublic(): void
    {
        $id  = $this->insert('PVP', 'Ворон', 'Сыч');
        $res = $this->get('battles/view/' . $id);

        $res->assertStatus(200);
        $body = (string) $res->response()->getBody();
        $this->assertStringContainsString('Ворон', $body);
        $this->assertStringContainsString('Сыч', $body);
        $this->assertStringNotContainsString('клетка 777', $body, 'гостю чужие координаты не показываются');
    }

    public function testDuelAndPveAreNotPublicAndLookLikeMissing(): void
    {
        foreach (['DUEL', 'PVE'] as $type) {
            $id = $this->insert($type, 'Ворон', 'Сыч');
            $this->assertNotFound($id, $type);
        }
        $this->assertNotFound(999_999, 'нет такого боя');
    }

    private function assertNotFound(int $id, string $label): void
    {
        try {
            $res = $this->get('battles/view/' . $id);
            $this->assertSame(404, $res->response()->getStatusCode(), $label);
            $this->assertStringNotContainsString('Ворон', (string) $res->response()->getBody(), "{$label}: имена не утекают");
        } catch (PageNotFoundException $e) {
            $this->assertSame("Бой #{$id} не найден.", $e->getMessage(), $label);
        }
    }

    private function insert(string $type, string $a, string $d): int
    {
        $side = static fn (int $id, string $name): array => [
            'id' => $id, 'name' => $name, 'level' => 5, 'strength' => 1, 'agility' => 1, 'intellect' => 1,
            'health_before' => 100, 'health_after' => 0, 'coords' => ['cell' => 777, 'x' => 7, 'y' => 7],
        ];
        $log = [
            'version'    => 2,
            'biome'      => 'Лес',
            'characters' => ['attacker' => $side(1, $a), 'defender' => $side(2, $d)],
            'rounds'     => [['attacker' => $a, 'defender' => $d, 'finalDamage' => 10, 'defenderHealthAfter' => 0]],
            'outcome'    => ['type' => 'normal', 'winnerId' => 1, 'loserId' => 2],
        ];
        $now = date('Y-m-d H:i:s');
        $this->conn->table('battle_logs')->insert([
            'battle_type' => $type, 'player1_id' => 1, 'player2_id' => 2, 'winner_id' => 1,
            'created_at'  => $now, 'finished_at' => $now, 'log_data' => json_encode($log, JSON_UNESCAPED_UNICODE),
        ]);

        return (int) $this->conn->insertID();
    }
}

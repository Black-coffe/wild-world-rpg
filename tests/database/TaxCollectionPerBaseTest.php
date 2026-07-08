<?php

namespace Tests\Database;

use App\Models\CharacterModel;
use App\Services\Bases\BaseLifecycleService;
use App\TaskHandlers\TaxCollectionHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionMethod;

/**
 * E23 / ADR-122 — per-base сбор налога за здания.
 *
 * Проверяет, что:
 *  - одно-базовый игрок ведёт себя byte-identical со старым агрегатом (оплата / неоплата);
 *  - мультибэйс: золото — единый кошелёк, дешёвые базы платятся первыми;
 *  - недофинансированная база получает FAILURE per-base, остальные остаются SUCCESS
 *    (производство гейтится статусом построчно → не морозится глобально);
 *  - 2-й FAILURE подряд сносит новейшую постройку ТОЛЬКО недоплаченной базы.
 *
 * @internal
 */
final class TaxCollectionPerBaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        foreach (['character_buildings', 'characters'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }

        $db->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                gold INT NULL DEFAULT 0,
                tax_unpaid_streak INT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE character_buildings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                building_id INT NULL,
                map_cell_id INT NULL,
                amount INT NULL DEFAULT 1,
                level INT NULL DEFAULT 1,
                building_type VARCHAR(32) NULL,
                tax INT NULL,
                last_tax_collected DATETIME NULL,
                tax_collection_status VARCHAR(16) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['character_buildings', 'characters'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    private function seedChar(int $id, int $gold): void
    {
        Database::connect('tests')->table('characters')->insert([
            'id' => $id, 'gold' => $gold, 'tax_unpaid_streak' => 0,
        ]);
    }

    /** @return int building id */
    private function seedBuilding(int $charId, int $cell, int $tax, string $status = 'SUCCESS', ?string $createdAt = null): int
    {
        $db = Database::connect('tests');
        $db->table('character_buildings')->insert([
            'character_id'          => $charId,
            'building_id'           => 1,
            'map_cell_id'           => $cell,
            'tax'                   => $tax,
            'tax_collection_status' => $status,
            'created_at'            => $createdAt ?? date('Y-m-d H:i:s'),
        ]);
        return (int) $db->insertID();
    }

    /**
     * @return array{0:int,1:int,2:list<array{cell:int,name:string,tax:int,paid:int,status:string}>}
     *   [новое золото, собрано за здания, разбивка по базам]
     */
    private function runPerBase(int $charId, int $gold, bool $cascadeOn = false): array
    {
        $handler = new class extends TaxCollectionHandler {
            // Заглушаем Telegram-уведомления (CI без валидного API-ключа) — ловим вызовы.
            public int $notifyCount = 0;
            /** @param array<string,mixed>|\App\Entities\CharacterEntity $character */
            protected function sendTelegramNotification(array|\App\Entities\CharacterEntity $character, string $message): void
            {
                $this->notifyCount++;
            }
            protected function notifyCharacterById(int $characterId, string $message): void
            {
                $this->notifyCount++;
            }
        };

        $m = new ReflectionMethod(TaxCollectionHandler::class, 'collectBuildingTaxPerBase');
        $m->setAccessible(true);

        /** @var array{0:int,1:int,2:list<array{cell:int,name:string,tax:int,paid:int,status:string}>} $res */
        $res = $m->invoke(
            $handler,
            $charId,
            $gold,
            new CharacterModel(),
            $cascadeOn,
            new BaseLifecycleService(),
            date('Y-m-d H:i:s')
        );
        return $res;
    }

    /** @return array<string,mixed>|null */
    private function buildingById(int $id): ?array
    {
        $q = Database::connect('tests')->table('character_buildings')->where('id', $id)->get();
        $row = $q === false ? null : $q->getRowArray();
        return is_array($row) ? $row : null;
    }

    private function statusOf(int $cell): string
    {
        $q = Database::connect('tests')->table('character_buildings')->where('map_cell_id', $cell)->get();
        $row = $q === false ? null : $q->getRowArray();
        return (string) ($row['tax_collection_status'] ?? '');
    }

    private function goldOf(int $charId): int
    {
        $q = Database::connect('tests')->table('characters')->where('id', $charId)->get();
        $row = $q === false ? null : $q->getRowArray();
        return (int) ($row['gold'] ?? -1);
    }

    private function buildingCount(int $charId, int $cell): int
    {
        return (int) Database::connect('tests')->table('character_buildings')
            ->where('character_id', $charId)->where('map_cell_id', $cell)->countAllResults();
    }

    public function testSingleBaseAffordableByteIdentical(): void
    {
        $this->seedChar(1, 10000);
        $this->seedBuilding(1, 100, 4000);
        $this->seedBuilding(1, 100, 3000);

        [$gold, $collected] = $this->runPerBase(1, 10000);

        $this->assertSame(3000, $gold);
        $this->assertSame(7000, $collected);
        $this->assertSame('SUCCESS', $this->statusOf(100));
        $this->assertSame(3000, $this->goldOf(1));
    }

    public function testSingleBaseUnaffordableByteIdentical(): void
    {
        // Старый агрегат: собирает всё, что есть, золото→0, FAILURE.
        $this->seedChar(2, 5000);
        $this->seedBuilding(2, 100, 7000);

        [$gold, $collected] = $this->runPerBase(2, 5000);

        $this->assertSame(0, $gold);
        $this->assertSame(5000, $collected);
        $this->assertSame('FAILURE', $this->statusOf(100));
    }

    public function testMultiBaseBothAffordable(): void
    {
        $this->seedChar(3, 10000);
        $this->seedBuilding(3, 100, 3000);
        $this->seedBuilding(3, 200, 4000);

        [$gold, $collected] = $this->runPerBase(3, 10000);

        $this->assertSame(3000, $gold);
        $this->assertSame(7000, $collected);
        $this->assertSame('SUCCESS', $this->statusOf(100));
        $this->assertSame('SUCCESS', $this->statusOf(200));
    }

    public function testMultiBasePartialGoldCheapSurvives(): void
    {
        // КЛЮЧЕВОЙ кейс фикса: золота на обе базы не хватает. Дешёвая (cell100/1200)
        // платится первой → SUCCESS (производство НЕ заморожено); дорогая (cell200/7000)
        // → FAILURE. Раньше обе уходили в FAILURE (агрегат).
        $this->seedChar(4, 5000);
        $this->seedBuilding(4, 100, 1200);
        $this->seedBuilding(4, 200, 7000);

        [$gold, $collected] = $this->runPerBase(4, 5000);

        $this->assertSame(0, $gold);
        $this->assertSame(5000, $collected); // 1200 (база A) + 3800 (частично база B)
        $this->assertSame('SUCCESS', $this->statusOf(100));
        $this->assertSame('FAILURE', $this->statusOf(200));
    }

    public function testBreakdownReturnedForSummary(): void
    {
        // E23/ADR-122 Ф2: метод возвращает per-base разбивку для сводки. Дешёвая база
        // (cell100) первой → SUCCESS с полной оплатой; дорогая (cell200) → FAILURE с частичной.
        $this->seedChar(6, 5000);
        $this->seedBuilding(6, 100, 1200);
        $this->seedBuilding(6, 200, 7000);

        $res       = $this->runPerBase(6, 5000);
        $breakdown = $res[2];

        $this->assertCount(2, $breakdown);

        $byCell = [];
        foreach ($breakdown as $b) {
            $byCell[$b['cell']] = $b;
        }

        $this->assertSame('SUCCESS', $byCell[100]['status']);
        $this->assertSame(1200, $byCell[100]['tax']);
        $this->assertSame(1200, $byCell[100]['paid']);

        $this->assertSame('FAILURE', $byCell[200]['status']);
        $this->assertSame(7000, $byCell[200]['tax']);
        $this->assertSame(3800, $byCell[200]['paid']); // 5000 − 1200 = частичная оплата

        // Имя базы присутствует (без claimed_cells для тест-чара → дефолт 'База').
        $this->assertArrayHasKey('name', $byCell[100]);
        $this->assertNotSame('', $byCell[100]['name']);
    }

    public function testSecondFailureDeletesOnlyFailingBaseBuilding(): void
    {
        // База B уже была FAILURE в прошлый прогон + 2 постройки. Недоплата → сносит
        // новейшую постройку ТОЛЬКО базы B; база A не тронута.
        $this->seedChar(5, 0);
        $aId = $this->seedBuilding(5, 100, 0, 'SUCCESS');                    // база A, бесплатна
        $this->seedBuilding(5, 200, 5000, 'FAILURE', '2026-06-01 00:00:00'); // база B, старая
        $bNew = $this->seedBuilding(5, 200, 5000, 'FAILURE', '2026-06-10 00:00:00'); // база B, новейшая

        $this->runPerBase(5, 0, false);

        // База A цела (1 постройка), база B потеряла новейшую (была 2 → 1).
        $this->assertSame(1, $this->buildingCount(5, 100));
        $this->assertSame(1, $this->buildingCount(5, 200));
        $this->assertNull($this->buildingById($bNew));
        $this->assertNotNull($this->buildingById($aId));
    }
}

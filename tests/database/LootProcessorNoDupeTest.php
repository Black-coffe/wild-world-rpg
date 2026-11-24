<?php

namespace Tests\Database;

use App\Services\Player\Death\LootProcessor;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * s1-atomicity-10 — добыча после смерти не удваивает ресурс.
 *
 * `applyLosses()` списывает у проигравшего `min(lossAmount, текущий остаток)` и возвращает
 * фактически списанный список; победитель получает `floor(списано × factor)` из этого списка,
 * а не из плана потерь, посчитанного до списания. Тест повторяет связку DeathService:
 * apply → transfer списком, который вернул apply.
 *
 * @internal
 */
final class LootProcessorNoDupeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const RED = 'победитель получил больше, чем списано у проигравшего';

    private LootProcessor $proc;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS characters');
        $db->query('DROP TABLE IF EXISTS character_resources');
        $db->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                gold DECIMAL(15,2) NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $db->query('
            CREATE TABLE character_resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_characters INT NOT NULL,
                id_resources INT NOT NULL,
                quantity INT NOT NULL DEFAULT 0,
                custom_data TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uq_cr_char_resource (id_characters, id_resources)
            )
        ');

        $this->proc = new LootProcessor();
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS characters');
        $db->query('DROP TABLE IF EXISTS character_resources');
        parent::tearDown();
    }

    public function testShortStockDeductsWhatIsLeftAndWinnerGetsOnlyThat(): void
    {
        [$loserId, $winnerId] = $this->twoCharacters();
        $charResId = $this->giveResource($loserId, 5, 3);

        $plan = [['charResId' => $charResId, 'resourceId' => 5, 'lossAmount' => 10]];
        $deducted = $this->applyAndTransfer($loserId, $winnerId, $plan, 1.0);

        $this->assertSame(0, $this->qty($loserId, 5));
        $this->assertSame(3, $this->qty($winnerId, 5), self::RED);
        $this->assertSame([['charResId' => $charResId, 'resourceId' => 5, 'lossAmount' => 3]], $deducted);
    }

    public function testShortStockWithHalfFactor(): void
    {
        [$loserId, $winnerId] = $this->twoCharacters();
        $charResId = $this->giveResource($loserId, 5, 3);

        $plan = [['charResId' => $charResId, 'resourceId' => 5, 'lossAmount' => 10]];
        $this->applyAndTransfer($loserId, $winnerId, $plan, 0.5);

        $this->assertSame(0, $this->qty($loserId, 5));
        $this->assertSame(1, $this->qty($winnerId, 5), self::RED);
    }

    public function testStockZeroedBetweenComputeAndApplyGivesWinnerNothing(): void
    {
        [$loserId, $winnerId] = $this->twoCharacters();
        $charResId = $this->giveResource($loserId, 5, 10);
        $otherId   = $this->giveResource($loserId, 7, 10);

        $plan = $this->proc->computeResourceLoss(
            Database::connect('tests')->query('SELECT id, id_resources, quantity FROM character_resources WHERE id_characters = ?', [$loserId])->getResultArray(),
            0.5
        );

        // Параллельная трата: обе строки опустели между расчётом и списанием (одна — до 0, другая удалена).
        Database::connect('tests')->query('UPDATE character_resources SET quantity = 0 WHERE id = ?', [$charResId]);
        Database::connect('tests')->query('DELETE FROM character_resources WHERE id = ?', [$otherId]);

        $deducted = $this->applyAndTransfer($loserId, $winnerId, $plan, 1.0);

        $this->assertSame(0, $this->qty($winnerId, 5), self::RED);
        $this->assertSame(0, $this->qty($winnerId, 7), self::RED);
        $this->assertSame([], $deducted);
    }

    public function testEnoughStockBehavesAsBefore(): void
    {
        [$loserId, $winnerId] = $this->twoCharacters();
        $charResId = $this->giveResource($loserId, 5, 100);

        $plan = [['charResId' => $charResId, 'resourceId' => 5, 'lossAmount' => 30]];
        $deducted = $this->applyAndTransfer($loserId, $winnerId, $plan, 0.5);

        $this->assertSame(70, $this->qty($loserId, 5));
        $this->assertSame(15, $this->qty($winnerId, 5));
        $this->assertSame($plan, $deducted);
    }

    /**
     * Связка DeathService: победителю идёт список, который вернул applyLosses().
     * Проверяет инвариант: у победителя не больше, чем списано у проигравшего × factor.
     *
     * @param list<array{charResId:int,resourceId:int,lossAmount:int}> $plan
     * @return mixed что вернул applyLosses()
     */
    private function applyAndTransfer(int $loserId, int $winnerId, array $plan, float $factor): mixed
    {
        $before = [];
        foreach ($plan as $lr) {
            $before[$lr['resourceId']] = $this->qty($loserId, $lr['resourceId']);
        }

        $deducted = $this->proc->applyLosses($loserId, $plan, 0);
        $toWinner = is_array($deducted) ? $deducted : $plan;
        $this->proc->transferResourcesToWinner($winnerId, $toWinner, $factor);

        foreach ($before as $resourceId => $qtyBefore) {
            $taken = $qtyBefore - $this->qty($loserId, $resourceId);
            $this->assertLessThanOrEqual(
                (int) floor($taken * $factor),
                $this->qty($winnerId, $resourceId),
                self::RED
            );
        }

        return $deducted;
    }

    /** @return array{0:int,1:int} */
    private function twoCharacters(): array
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (gold) VALUES (0)');
        $loserId = (int) $db->insertID();
        $db->query('INSERT INTO characters (gold) VALUES (0)');

        return [$loserId, (int) $db->insertID()];
    }

    private function giveResource(int $characterId, int $resourceId, int $qty): int
    {
        $db = Database::connect('tests');
        $db->query(
            'INSERT INTO character_resources (id_characters, id_resources, quantity) VALUES (?, ?, ?)',
            [$characterId, $resourceId, $qty]
        );

        return (int) $db->insertID();
    }

    private function qty(int $characterId, int $resourceId): int
    {
        $row = Database::connect('tests')->query(
            'SELECT quantity FROM character_resources WHERE id_characters = ? AND id_resources = ?',
            [$characterId, $resourceId]
        )->getRowArray();

        return $row === null ? 0 : (int) $row['quantity'];
    }
}

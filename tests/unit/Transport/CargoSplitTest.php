<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use App\Services\Player\Gather\GatherResultPersister;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * transport-08 (ADR-171/174, план `docs/specs/transport-system/plan.md` → Contracts) —
 * карго-сплит в `GatherResultPersister`.
 *
 * Изолированная схема (как `VehicleActivationServiceTest`) — своя `characters` /
 * `character_resources` / `base_storage` в `wildworld_tests`. Профиль машины,
 * «есть ли база» и отправка уведомления — seam'ы (`FakeGatherResultPersister`),
 * подменённые test-double: реальный write-path (character_resources/base_storage)
 * не тронут — поведенческий тест проверяет ИТОГОВЫЕ остатки, а не факт вызова
 * сплиттера.
 *
 * @internal
 */
final class CargoSplitTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['characters', 'character_resources', 'base_storage'];

    private \CodeIgniter\Database\BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');

        foreach (self::TABLES as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }

        $this->conn->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_user_id INT NULL,
                level INT NOT NULL DEFAULT 1,
                experience DECIMAL(10,2) NOT NULL DEFAULT 0,
                health DECIMAL(10,2) NOT NULL DEFAULT 0,
                tired DECIMAL(10,2) NOT NULL DEFAULT 0,
                gold DECIMAL(10,2) NOT NULL DEFAULT 0,
                strength DECIMAL(10,2) NOT NULL DEFAULT 0,
                agility DECIMAL(10,2) NOT NULL DEFAULT 0,
                intellect DECIMAL(10,2) NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE character_resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_characters INT NOT NULL,
                id_resources INT NOT NULL,
                id_telegram_users INT NULL,
                quantity INT NOT NULL DEFAULT 0,
                custom_data TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->conn->query('
            CREATE TABLE base_storage (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                resource_id INT NOT NULL,
                quantity INT NOT NULL DEFAULT 0,
                arrived_from_cell INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
    }

    private function seedCharacter(): int
    {
        $this->conn->table('characters')->insert(['telegram_user_id' => 25]);

        return (int) $this->conn->insertID();
    }

    private function backpackQty(int $charId, int $resourceId): int
    {
        $row = $this->conn->table('character_resources')
            ->where('id_characters', $charId)
            ->where('id_resources', $resourceId)
            ->get()->getRowArray();

        return $row === null ? 0 : (int) $row['quantity'];
    }

    private function storageQty(int $charId, int $resourceId): int
    {
        $row = $this->conn->table('base_storage')
            ->where('character_id', $charId)
            ->where('resource_id', $resourceId)
            ->get()->getRowArray();

        return $row === null ? 0 : (int) $row['quantity'];
    }

    // ── Инвариант сохранения массы ─────────────────────────────────────────

    public function testMassConservedForEveryShareIncludingZeroAndThird(): void
    {
        foreach ([0.0, 0.33] as $share) {
            $charId = $this->seedCharacter();
            $svc    = new FakeGatherResultPersister();
            $svc->cargoShare  = $share;
            $svc->hasBaseFlag = true;

            $found = [['resource_id' => 1, 'amount' => 17, 'type' => 'raw']];
            $svc->persist($found, ['id' => $charId, 'telegram_user_id' => 25, 'level' => 1, 'experience' => 0], []);

            $backpack = $this->backpackQty($charId, 1);
            $storage  = $this->storageQty($charId, 1);

            $this->assertSame(17, $backpack + $storage, "share={$share}: дельта рюкзака + дельта склада должна равняться добытому");
        }
    }

    public function testMassConservedAcrossMultipleResourcesAtOnce(): void
    {
        $charId = $this->seedCharacter();
        $svc    = new FakeGatherResultPersister();
        $svc->cargoShare  = 0.33;
        $svc->hasBaseFlag = true;

        $found = [
            ['resource_id' => 1, 'amount' => 5, 'type' => 'raw'],
            ['resource_id' => 2, 'amount' => 12, 'type' => 'raw'],
            ['resource_id' => 3, 'amount' => 1, 'type' => 'raw'],
        ];
        $svc->persist($found, ['id' => $charId, 'telegram_user_id' => 25, 'level' => 1, 'experience' => 0], []);

        foreach ($found as $res) {
            $rid = $res['resource_id'];
            $sum = $this->backpackQty($charId, $rid) + $this->storageQty($charId, $rid);
            $this->assertSame($res['amount'], $sum, "resource {$rid}: сохранение массы");
        }
    }

    // ── Нет базы → 100% в рюкзак + честный текст ────────────────────────────

    public function testNoBaseSendsEverythingToBackpackWithHonestNote(): void
    {
        $charId = $this->seedCharacter();
        $svc    = new FakeGatherResultPersister();
        $svc->cargoShare  = 0.33;
        $svc->hasBaseFlag = false;

        $found = [['resource_id' => 1, 'amount' => 10, 'type' => 'raw']];
        $svc->persist($found, ['id' => $charId, 'telegram_user_id' => 25, 'level' => 1, 'experience' => 0], []);

        $this->assertSame(10, $this->backpackQty($charId, 1));
        $this->assertSame(0, $this->storageQty($charId, 1));

        $this->assertCount(1, $svc->sent);
        $this->assertStringContainsString('груз некуда везти', mb_strtolower($svc->sent[0][1]));
    }

    // ── Килсвитч off / нет транспорта / машина без груза → байт-идентично ───

    public function testNoCargoShareIsByteIdenticalNoStorageNoNote(): void
    {
        $charId = $this->seedCharacter();
        $svc    = new FakeGatherResultPersister();
        $svc->cargoShare  = 0.0; // killswitch off / нет транспорта / mtb|drone_auto
        $svc->hasBaseFlag = true;

        $found = [['resource_id' => 1, 'amount' => 9, 'type' => 'raw']];
        $svc->persist($found, ['id' => $charId, 'telegram_user_id' => 25, 'level' => 1, 'experience' => 0], []);

        $this->assertSame(9, $this->backpackQty($charId, 1), 'весь объём в рюкзаке — как сегодня');
        $this->assertSame(0, $this->storageQty($charId, 1), 'склад не тронут');
        $this->assertSame([], $svc->sent, 'без килсвитча/машины уведомления быть не должно');
    }

    // ── floor(qty×share): честный ноль на маленьком стеке + порог срабатывания ──

    public function testFloorGivesHonestZeroOnSingleUnitStack(): void
    {
        $charId = $this->seedCharacter();
        $svc    = new FakeGatherResultPersister();
        $svc->cargoShare  = 0.33; // самая щедрая доля из трёх грузовых машин
        $svc->hasBaseFlag = true;

        $found = [['resource_id' => 1, 'amount' => 1, 'type' => 'raw']];
        $svc->persist($found, ['id' => $charId, 'telegram_user_id' => 25, 'level' => 1, 'experience' => 0], []);

        $this->assertSame(1, $this->backpackQty($charId, 1));
        $this->assertSame(0, $this->storageQty($charId, 1), 'на стеке в одну единицу floor(1×0.33)=0 — честный ноль');
        $this->assertSame([], $svc->sent, 'нечего перевозить — уведомления о грузе не было');
    }

    /**
     * Порог срабатывания (минимальный qty, при котором на склад уезжает хотя бы 1) для
     * каждой из трёх грузовых машин (значения `world.vehicle.<key>.cargo_share` из
     * plan.md → ## Contracts): cart=0.20 → 5, snowmobile=0.25 → 4, draft_cart=0.33 → 4.
     *
     * @dataProvider vehicleShareThresholds
     */
    public function testCargoThresholdPerVehicle(float $share, int $threshold): void
    {
        // На qty=threshold-1 склад пуст (честный ноль)…
        $charId1 = $this->seedCharacter();
        $svc1    = new FakeGatherResultPersister();
        $svc1->cargoShare  = $share;
        $svc1->hasBaseFlag = true;
        $svc1->persist([['resource_id' => 1, 'amount' => $threshold - 1, 'type' => 'raw']], ['id' => $charId1, 'telegram_user_id' => 25, 'level' => 1, 'experience' => 0], []);
        $this->assertSame(0, $this->storageQty($charId1, 1), "share={$share}: qty=" . ($threshold - 1) . ' ещё не должен долетать до склада');

        // …а на qty=threshold уже хотя бы 1 единица уезжает на склад.
        $charId2 = $this->seedCharacter();
        $svc2    = new FakeGatherResultPersister();
        $svc2->cargoShare  = $share;
        $svc2->hasBaseFlag = true;
        $svc2->persist([['resource_id' => 1, 'amount' => $threshold, 'type' => 'raw']], ['id' => $charId2, 'telegram_user_id' => 25, 'level' => 1, 'experience' => 0], []);
        $this->assertGreaterThanOrEqual(1, $this->storageQty($charId2, 1), "share={$share}: qty={$threshold} обязан отправить хотя бы 1 на склад");
    }

    /** @return array<string, array{0: float, 1: int}> */
    public static function vehicleShareThresholds(): array
    {
        return [
            'cart (0.20)'       => [0.20, 5],
            'snowmobile (0.25)' => [0.25, 4],
            'draft_cart (0.33)' => [0.33, 4],
        ];
    }

    // ── Текст называет, сколько и чего уехало на склад ──────────────────────

    public function testCargoNoteNamesResourceAndAmount(): void
    {
        $charId = $this->seedCharacter();
        $svc    = new FakeGatherResultPersister();
        $svc->cargoShare  = 0.33;
        $svc->hasBaseFlag = true;

        $svc->persist([['resource_id' => 1, 'amount' => 10, 'type' => 'raw']], ['id' => $charId, 'telegram_user_id' => 25, 'level' => 1, 'experience' => 0], []);

        $this->assertSame(7, $this->backpackQty($charId, 1));
        $this->assertSame(3, $this->storageQty($charId, 1));

        $this->assertCount(1, $svc->sent);
        $text = $svc->sent[0][1];
        $this->assertStringContainsString('res1', $text, 'название ресурса');
        $this->assertStringContainsString('3', $text, 'количество, уехавшее на склад');
        $this->assertStringContainsString('склад', mb_strtolower($text));
    }
}

/**
 * Test-double: подменяет карго-профиль, наличие базы, поиск чата, отправку и резолв
 * имён ресурсов. Реальный write-path (character_resources/base_storage) НЕ тронут —
 * тест проверяет фактические остатки в БД, а не факт вызова сплиттера.
 *
 * @internal
 */
final class FakeGatherResultPersister extends GatherResultPersister
{
    public float $cargoShare = 0.0;
    public bool $hasBaseFlag = true;

    /** @var list<array{0: int, 1: string}> */
    public array $sent = [];

    protected function resolveVehicleProfile(int $charId): array
    {
        return [
            'key'                 => $this->cargoShare > 0.0 ? 'test_vehicle' : null,
            'cells_per_tick'      => 3,
            'tired_factor'        => 1.0,
            'max_steps_per_order' => 60,
            'cargo_share'         => $this->cargoShare,
            'wear_per_cell'       => 0,
        ];
    }

    protected function hasBase(int $charId): bool
    {
        return $this->hasBaseFlag;
    }

    protected function chatIdFor(array|\App\Entities\CharacterEntity $character): ?int
    {
        return 6995661239;
    }

    protected function send(int $chatId, string $text): void
    {
        $this->sent[] = [$chatId, $text];
    }

    /** @param list<int> $ids */
    protected function resourceNames(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = "res{$id}";
        }

        return $out;
    }
}

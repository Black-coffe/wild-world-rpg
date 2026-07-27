<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\Trade\ResourceTradeService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Класс lost-update (последний незакрытый близнец серии 2026-07-13): торговля сырьём
 * ОБЯЗАНА проверять результат мутации золота, а не начислять/списывать ценность
 * безусловно.
 *
 * До фикса `buyResource` судила о платёжеспособности по снапшоту `$character`,
 * прочитанному в начале запроса, а `decreaseGold()` вызывала без проверки результата.
 * Сервис перепроверяет достаточность от СВЕЖЕГО золота под row-lock'ом и в гонке
 * возвращает false — но ресурс начислялся всё равно. Итог: при параллельной трате
 * (быстрые тапы, webhook-retry Телеграма, фоновый worker) покупка становилась
 * бесплатной и печатала ценность из воздуха.
 *
 * В отличие от source-scan'а родственных тестов (UsePharmacyAtomicUpdateTest,
 * PveRewardAtomicUpdateTest), здесь гонка воспроизводится ДЕТЕРМИНИРОВАННО и без
 * конкурентных воркеров: «устаревший снапшот» — это ровно то, что передаётся
 * первым аргументом, поэтому расхождение снапшот↔БД задаётся руками.
 *
 * @internal
 */
final class ResourceTradeGoldRaceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['characters', 'character_resources', 'resources', 'resources_bank'];

    private const RESOURCE_ID = 7;

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL, gold DECIMAL(14,2) DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE character_resources (id INT AUTO_INCREMENT PRIMARY KEY, id_characters INT, id_resources INT, quantity INT DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE resources (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64), name_en VARCHAR(64) NULL, buy_price DECIMAL(10,2) DEFAULT 0, sell_price DECIMAL(10,2) DEFAULT 0, is_tradeable TINYINT DEFAULT 1, rarity INT DEFAULT 1, icon_text VARCHAR(16) NULL)');
        $db->query('CREATE TABLE resources_bank (id INT AUTO_INCREMENT PRIMARY KEY, resource_id INT, current_quantity INT DEFAULT 0, resources_purchased INT DEFAULT 0, resources_sold INT DEFAULT 0, last_update DATETIME NULL)');

        $db->table('resources')->insert([
            'id'           => self::RESOURCE_ID,
            'name'         => 'Ржавый лом',
            'name_en'      => 'rusty_scrap',
            'buy_price'    => 10.0,
            'sell_price'   => 4.0,
            'is_tradeable' => 1,
            'rarity'       => 1,
            'icon_text'    => '🔧',
        ]);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    private function seedCharacter(int $id, float $gold): void
    {
        Database::connect('tests')->table('characters')->insert([
            'id'   => $id,
            'name' => 'Тестовый',
            'gold' => $gold,
        ]);
    }

    private function goldOf(int $id): float
    {
        $row = Database::connect('tests')->table('characters')->select('gold')->where('id', $id)->get()->getRowArray();

        return $row === null ? -1.0 : (float) $row['gold'];
    }

    private function ownedQty(int $charId): int
    {
        $row = Database::connect('tests')->table('character_resources')
            ->select('quantity')->where('id_characters', $charId)->where('id_resources', self::RESOURCE_ID)
            ->get()->getRowArray();

        return $row === null ? 0 : (int) $row['quantity'];
    }

    private function bankPurchased(): int
    {
        $row = Database::connect('tests')->table('resources_bank')
            ->select('resources_purchased')->where('resource_id', self::RESOURCE_ID)->get()->getRowArray();

        return $row === null ? 0 : (int) $row['resources_purchased'];
    }

    /**
     * ЯДРО РЕГРЕССИИ: снапшот утверждает, что золото есть, в БД его уже нет.
     * Покупка обязана отклониться и НЕ выдать ресурс.
     */
    public function testBuyRejectedWhenGoldAlreadySpentConcurrently(): void
    {
        $this->seedCharacter(1, 0.0);

        // $character — снапшот из начала запроса: «1000💰». В БД к этому моменту 0.
        $result = (new ResourceTradeService())->buyResource(['id' => 1, 'gold' => 1000], self::RESOURCE_ID, 5);

        $this->assertFalse($result['success'], 'покупка при пустой кассе обязана провалиться');
        $this->assertStringContainsString('Не удалось списать', $result['message']);
        $this->assertSame(0, $this->ownedQty(1), 'ресурс НЕ должен начисляться, если золото не списалось');
        $this->assertSame(0.0, $this->goldOf(1), 'золото не должно уходить в минус');
        $this->assertSame(0, $this->bankPurchased(), 'банк не должен учитывать несостоявшуюся покупку');
    }

    /**
     * Частичная недостача: снапшот богат, в БД денег меньше цены — тоже отказ
     * (mutator внутри decreaseGold ничего не пишет).
     */
    public function testBuyRejectedWhenFreshGoldIsBelowPrice(): void
    {
        $this->seedCharacter(2, 30.0);

        $result = (new ResourceTradeService())->buyResource(['id' => 2, 'gold' => 1000], self::RESOURCE_ID, 5); // 50💰

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->ownedQty(2));
        $this->assertSame(30.0, $this->goldOf(2), 'частичная оплата недопустима — списания быть не должно');
    }

    /** Счастливый путь не сломан: золото списано, ресурс начислен, банк учёл покупку. */
    public function testBuyDebitsGoldAndCreditsResource(): void
    {
        $this->seedCharacter(3, 1000.0);

        $result = (new ResourceTradeService())->buyResource(['id' => 3, 'gold' => 1000], self::RESOURCE_ID, 5);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(50, $result['cost'] ?? 0);
        $this->assertSame(5, $this->ownedQty(3));
        $this->assertSame(950.0, $this->goldOf(3));
        $this->assertSame(5, $this->bankPurchased());
    }

    /** Предчек по снапшоту остаётся первым барьером — сообщение про нехватку. */
    public function testBuyRejectedBySnapshotPrecheck(): void
    {
        $this->seedCharacter(4, 1000.0);

        $result = (new ResourceTradeService())->buyResource(['id' => 4, 'gold' => 10], self::RESOURCE_ID, 5);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('недостаточно золота', $result['message']);
        $this->assertSame(1000.0, $this->goldOf(4), 'предчек не должен трогать кассу');
    }

    /**
     * Зеркальный близнец: если начисление золота не удалось (персонажа нет),
     * ресурс ОБЯЗАН остаться у игрока.
     */
    public function testSellKeepsResourceWhenGoldCreditFails(): void
    {
        // Строка инвентаря есть, а персонажа в `characters` — нет (increaseGold → false).
        Database::connect('tests')->table('character_resources')->insert([
            'id_characters' => 999,
            'id_resources'  => self::RESOURCE_ID,
            'quantity'      => 12,
        ]);

        $result = (new ResourceTradeService())->sellResource(['id' => 999, 'gold' => 0], self::RESOURCE_ID, 3);

        $this->assertFalse($result['success']);
        $this->assertSame(12, $this->ownedQty(999), 'ресурс не должен исчезать, когда золото не начислено');
    }

    /** Счастливый путь продажи не сломан. */
    public function testSellCreditsGoldAndDeductsResource(): void
    {
        $this->seedCharacter(5, 100.0);
        Database::connect('tests')->table('character_resources')->insert([
            'id_characters' => 5,
            'id_resources'  => self::RESOURCE_ID,
            'quantity'      => 12,
        ]);

        $result = (new ResourceTradeService())->sellResource(['id' => 5, 'gold' => 100], self::RESOURCE_ID, 3);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(9, $this->ownedQty(5));
        $this->assertSame(112.0, $this->goldOf(5), '3 × sell_price 4 = 12💰 сверху');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Database;

use App\TaskHandlers\HealthRegenerationHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Слайс «Видимая лестница L1→L10» — проводка события level-up в кроне.
 *
 * Уровень производный и пересчитывается здесь каждую минуту; уведомление врезано ровно в ту
 * ветку, где пересчёт УЖЕ менял цифру молча. Unit-тесты покрывают текст и гейты нотификатора,
 * а этот тест страхует САМУ ПРОВОДКУ: что крон действительно зовёт seam при росте уровня и
 * НЕ зовёт при падении/без изменения. Без такой страховки фича легко оказывается латентно
 * мёртвой (класс BUILT-BUT-DEAD: код есть, вызова нет).
 *
 * @internal
 */
final class HealthRegenerationLevelUpTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS characters');
        $db->query('DROP TABLE IF EXISTS map');
        $db->query('
            CREATE TABLE characters (
                id INT PRIMARY KEY,
                telegram_user_id INT NULL,
                name VARCHAR(100) NULL,
                level INT NOT NULL DEFAULT 1,
                experience DECIMAL(7,2) NOT NULL DEFAULT 0.01,
                strength DECIMAL(7,2) NOT NULL DEFAULT 0.01,
                agility DECIMAL(7,2) NOT NULL DEFAULT 0.01,
                intellect DECIMAL(5,2) NOT NULL DEFAULT 0.01,
                health DECIMAL(5,2) NOT NULL DEFAULT 100.00,
                tired DECIMAL(5,2) NULL DEFAULT 100.00,
                cell_number INT NULL,
                biome_id INT UNSIGNED NULL,
                updated_at DATETIME NULL
            )
        ');
        // verifyCharactersBiome() джойнит карту — пустой таблицы достаточно.
        $db->query('CREATE TABLE map (id INT PRIMARY KEY, cell_number INT NULL, biome_id INT UNSIGNED NULL)');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS characters');
        $db->query('DROP TABLE IF EXISTS map');
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function seedCharacter(int $id, float $exp, int $level, array $extra = []): void
    {
        Database::connect('tests')->table('characters')->insert(array_merge([
            'id'               => $id,
            'telegram_user_id' => $id * 10,
            'name'             => 'Char' . $id,
            'level'            => $level,
            'experience'       => $exp,
            'strength'         => 0.0,
            'agility'          => 0.0,
            'intellect'        => 0.0,
            'health'           => 100.0,
            'tired'            => 100.0,
        ], $extra));
    }

    public function testLevelUpIsRecomputedAndReported(): void
    {
        // Сумма 8.4 → floor(8.4/4) = 2: персонаж «дорос», но в БД ещё записан 1.
        $this->seedCharacter(1, 8.4, 1);

        $handler = new SpyHealthRegenerationHandler();
        $handler->process();

        $row = Database::connect('tests')->table('characters')->where('id', 1)->get()->getRowArray();
        $this->assertSame(2, (int) $row['level'], 'крон обязан записать пересчитанный уровень');

        $this->assertCount(1, $handler->notified, 'о повышении уровня обязан уйти сигнал');
        $this->assertSame([1, 1, 2], $handler->notified[0], '[charId, oldLevel, newLevel]');
    }

    public function testLevelDropIsRecomputedButSilent(): void
    {
        // Потеря статов (смерть) опустила сумму до 4.2 → уровень 1, в БД записан 3.
        $this->seedCharacter(2, 4.2, 3);

        $handler = new SpyHealthRegenerationHandler();
        $handler->process();

        $row = Database::connect('tests')->table('characters')->where('id', 2)->get()->getRowArray();
        $this->assertSame(1, (int) $row['level']);
        $this->assertSame([], $handler->notified, 'падение уровня добивать сообщением не за чем');
    }

    public function testUnchangedLevelWritesNothingAndSaysNothing(): void
    {
        $this->seedCharacter(3, 8.4, 2);

        $handler = new SpyHealthRegenerationHandler();
        $handler->process();

        $this->assertSame([], $handler->notified);
    }

    public function testNotificationFailureDoesNotBreakRegeneration(): void
    {
        // Отправка — побочный эффект горячего крона: её падение не имеет права остановить
        // ни пересчёт уровня, ни регенерацию остальных персонажей.
        $this->seedCharacter(4, 8.4, 1, ['health' => 50.0, 'tired' => 50.0]);

        $handler = new ThrowingHealthRegenerationHandler();
        $handler->process();

        $row = Database::connect('tests')->table('characters')->where('id', 4)->get()->getRowArray();
        $this->assertSame(2, (int) $row['level']);
        $this->assertGreaterThan(50.0, (float) $row['health'], 'регенерация обязана отработать');
    }
}

/**
 * Test-double: перехватывает seam уведомления вместо реальной отправки в Telegram.
 *
 * @internal
 */
final class SpyHealthRegenerationHandler extends HealthRegenerationHandler
{
    /** @var list<array{0: int, 1: int, 2: int}> */
    public array $notified = [];

    protected function notifyLevelUp(array|\App\Entities\CharacterEntity $character, int $oldLevel, int $newLevel): void
    {
        $idRaw = $character['id'] ?? 0;
        $this->notified[] = [is_numeric($idRaw) ? (int) $idRaw : 0, $oldLevel, $newLevel];
    }
}

/**
 * Test-double: нотификатор бросает — проверяем, что боевой try/catch крона это гасит
 * (подменяем ИМЕННО источник исключения, а не сам guard — иначе тест проверял бы себя).
 *
 * @internal
 */
final class ThrowingHealthRegenerationHandler extends HealthRegenerationHandler
{
    protected function levelUpNotifier(): \App\Services\Player\Progression\LevelUpNotifier
    {
        return new class extends \App\Services\Player\Progression\LevelUpNotifier {
            public function notifyLevelUp(array|\App\Entities\CharacterEntity $character, int $oldLevel, int $newLevel): void
            {
                throw new \RuntimeException('telegram down');
            }
        };
    }
}

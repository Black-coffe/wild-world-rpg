<?php

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\PVP\AttackPlayerAction;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionClass;
use ReflectionMethod;

/**
 * E24 (N6) — клавиатура «после боя» в AttackPlayerAction.
 *
 * Проверяет, что:
 *  - боец с ПРИОСТАНОВЛЕННЫМ походом (task 'Marching', status='paused') получает кнопку
 *    «▶️ Продолжить поход» (march_resume) — закрывает «нет resume после боя»;
 *  - боец без paused-похода получает обычный вход «🗺️ Поход» (march);
 *  - идущий поход (in_work) НЕ считается приостановленным.
 *
 * Чистая presentation-логика (без RNG) → fixture-fence не затрагивается.
 *
 * @internal
 */
final class AttackPlayerPostBattleKeyboardTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        foreach (['character_tasks', 'tasks'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('
            CREATE TABLE tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NULL
            )
        ');
        $db->query('
            CREATE TABLE character_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                task_id INT NULL,
                status VARCHAR(32) NULL
            )
        ');

        // Marching task def + сценарии: char1 paused, char2 in_work, char3 без задач.
        $db->table('tasks')->insert(['id' => 7, 'name' => 'Marching']);
        $db->table('tasks')->insert(['id' => 8, 'name' => 'Gathering']);
        $db->table('character_tasks')->insert(['character_id' => 1, 'task_id' => 7, 'status' => 'paused']);
        $db->table('character_tasks')->insert(['character_id' => 2, 'task_id' => 7, 'status' => 'in_work']);
        // char1 ещё и завершённая добыча — не должна влиять.
        $db->table('character_tasks')->insert(['character_id' => 1, 'task_id' => 8, 'status' => 'paused']);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['character_tasks', 'tasks'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    private function action(): AttackPlayerAction
    {
        // Обход CallbackQuery-зависимости конструктора (как fixture-fence тест).
        return (new ReflectionClass(AttackPlayerAction::class))->newInstanceWithoutConstructor();
    }

    private function hasPausedMarch(int $characterId): bool
    {
        $m = new ReflectionMethod(AttackPlayerAction::class, 'hasPausedMarch');
        $m->setAccessible(true);
        return (bool) $m->invoke($this->action(), $characterId);
    }

    /** @return array<string,mixed> */
    private function keyboard(int $characterId): array
    {
        $m = new ReflectionMethod(AttackPlayerAction::class, 'postBattleKeyboard');
        $m->setAccessible(true);
        /** @var array<string,mixed> $kb */
        $kb = $m->invoke($this->action(), $characterId);
        return $kb;
    }

    private function marchCallback(int $characterId): string
    {
        $kb = $this->keyboard($characterId);
        /** @var array<int,array<int,array<string,string>>> $rows */
        $rows = $kb['inline_keyboard'];
        return $rows[0][1]['callback_data'] ?? '';
    }

    public function testPausedMarchDetected(): void
    {
        $this->assertTrue($this->hasPausedMarch(1));
    }

    public function testInWorkMarchIsNotPaused(): void
    {
        $this->assertFalse($this->hasPausedMarch(2));
    }

    public function testNoTasksIsNotPaused(): void
    {
        $this->assertFalse($this->hasPausedMarch(3));
    }

    public function testKeyboardOffersResumeWhenPaused(): void
    {
        $this->assertSame('march_resume', $this->marchCallback(1));
    }

    public function testKeyboardOffersGenericMarchWhenNotPaused(): void
    {
        $this->assertSame('march', $this->marchCallback(2));
        $this->assertSame('march', $this->marchCallback(3));
    }

    public function testKeyboardAlwaysHasInventory(): void
    {
        $kb = $this->keyboard(1);
        /** @var array<int,array<int,array<string,string>>> $rows */
        $rows = $kb['inline_keyboard'];
        $this->assertSame('inventory', $rows[0][0]['callback_data'] ?? '');
    }
}

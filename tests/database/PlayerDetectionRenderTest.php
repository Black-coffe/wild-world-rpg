<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\CreateMapTable;
use App\Services\Player\PlayerDetectionService;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * pvp-detection-clarity-07 — экран «Обнаружение игроков»: потолок списка, свёрнутый
 * остаток, метка брошенного, жёсткая граница длины текста на 200 соседях, замок вместо
 * атаки. Прод-инцидент — 123 строки / до 369 кнопок в одном сообщении, оборвавшемся
 * на лимите Telegram (`recon-prod.md`).
 *
 * `renderDetectionMessage()` собирает текст+клавиатуру БЕЗ отправки (PHPUnit не рендерит
 * Telegram-сообщение — вид списка и работа замка доказываются Tier-3 на testbot'е,
 * см. `## Implementation notes` story). `PvPRestrictionService::checkPvPAllowed()` внутри
 * читает только `map` (по `cell_number`) и переданные массивы соседей — `characters`/
 * `telegram_users`/`game_settings` этому тесту не нужны (`.claude/rules/tests-db.md`:
 * схема строится прогоном настоящих классов миграций, только если таблицы ещё нет).
 *
 * @internal
 */
final class PlayerDetectionRenderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private \CodeIgniter\Database\BaseConnection $conn;
    private bool $createdMap = false;

    /** @var list<int> */
    private array $mapIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');
        $this->conn->resetDataCache();

        if (! $this->conn->tableExists('map')) {
            $this->requireMigration('CreateMapTable', '2024-03-18-105708_CreateMapTable.php');
            $forge = Database::forge('tests');
            (new CreateMapTable($forge instanceof Forge ? $forge : null))->up();
            $this->conn->resetDataCache();
            $this->createdMap = true;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->mapIds as $id) {
            $this->conn->table('map')->where('id', $id)->delete();
        }

        if ($this->createdMap) {
            $this->requireMigration('CreateMapTable', '2024-03-18-105708_CreateMapTable.php');
            $forge = Database::forge('tests');
            (new CreateMapTable($forge instanceof Forge ? $forge : null))->down();
        }

        parent::tearDown();
    }

    private function requireMigration(string $shortClass, string $file): void
    {
        $class = 'App\\Database\\Migrations\\' . $shortClass;
        if (! class_exists($class, false)) {
            require_once APPPATH . 'Database/Migrations/' . $file;
        }
    }

    private function uniqueCell(): int
    {
        return random_int(900_000_000, 999_999_999);
    }

    private function insertMapCell(int $cellNumber, int $coordinateY = 100): void
    {
        $this->conn->table('map')->insert([
            'cell_number'  => $cellNumber,
            'coordinate_x' => 0,
            'coordinate_y' => $coordinateY,
        ]);
        $this->mapIds[] = (int) $this->conn->insertID();
    }

    /**
     * @return array<string,mixed>
     */
    private function makeAttacker(int $cellNumber): array
    {
        return [
            'id'          => 1,
            'level'       => 50,
            'cell_number' => (string) $cellNumber,
            'created_at'  => date('Y-m-d H:i:s', strtotime('-400 days')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function makeNeighbor(
        int $id,
        int $cellNumber,
        int $distance,
        string $name,
        int $level = 50,
        ?string $lastActiveAt = null,
        ?string $createdAt = null
    ): array {
        return [
            'id'             => $id,
            'name'           => $name,
            'level'          => $level,
            'created_at'     => $createdAt ?? date('Y-m-d H:i:s', strtotime('-400 days')),
            'cell_number'    => (string) $cellNumber,
            'distance'       => $distance,
            'last_active_at' => $lastActiveAt,
        ];
    }

    /**
     * Плоский список кнопок клавиатуры (для поиска по callback_data в тестах).
     *
     * @param array{inline_keyboard: list<list<array<string,string>>>} $keyboard
     * @return list<array<string,string>>
     */
    private function flattenButtons(array $keyboard): array
    {
        $flat = [];
        foreach ($keyboard['inline_keyboard'] as $row) {
            foreach ($row as $btn) {
                $flat[] = $btn;
            }
        }
        return $flat;
    }

    public function testCapsListingAtMaxListedAndCollapsesRestIntoOneSummaryLine(): void
    {
        $attackerCell = $this->uniqueCell();
        $this->insertMapCell($attackerCell);
        $attacker = $this->makeAttacker($attackerCell);

        $neighbors = [];
        for ($i = 1; $i <= 200; $i++) {
            $cell = $this->uniqueCell();
            $this->insertMapCell($cell);
            $neighbors[] = $this->makeNeighbor(100 + $i, $cell, $i, "Сосед{$i}");
        }

        $service  = new PlayerDetectionService();
        $rendered = $service->renderDetectionMessage($attacker, $neighbors, 12, 14, true);

        $lineCount = substr_count($rendered['text'], 'Есть игрок');
        $this->assertSame(12, $lineCount, 'должно быть выведено ровно max_listed=12 строк');
        $this->assertStringContainsString('И ещё 188 поблизости', $rendered['text']);
    }

    public function testShowInactiveSummaryFalseHidesOverflowLine(): void
    {
        $attackerCell = $this->uniqueCell();
        $this->insertMapCell($attackerCell);
        $attacker = $this->makeAttacker($attackerCell);

        $neighbors = [];
        for ($i = 1; $i <= 20; $i++) {
            $cell = $this->uniqueCell();
            $this->insertMapCell($cell);
            $neighbors[] = $this->makeNeighbor(200 + $i, $cell, $i, "Игрок{$i}");
        }

        $service  = new PlayerDetectionService();
        $rendered = $service->renderDetectionMessage($attacker, $neighbors, 5, 14, false);

        $this->assertStringNotContainsString('И ещё', $rendered['text']);
        $this->assertSame(5, substr_count($rendered['text'], 'Есть игрок'));
    }

    public function testMarksAbandonedNeighborByActionLogGapAndSortsActiveFirst(): void
    {
        $attackerCell = $this->uniqueCell();
        $this->insertMapCell($attackerCell);
        $attacker = $this->makeAttacker($attackerCell);

        $activeCell    = $this->uniqueCell();
        $abandonedCell = $this->uniqueCell();
        $this->insertMapCell($activeCell);
        $this->insertMapCell($abandonedCell);

        // Брошенный дальше, но должен уступить место недавно активному, который дальше по
        // порядку в исходном массиве — доказывает, что сортировка ставит активность выше id/порядка.
        $abandoned = $this->makeNeighbor(
            301,
            $abandonedCell,
            1,
            'Заброшенный',
            50,
            date('Y-m-d H:i:s', strtotime('-30 days')) // старше inactive_days=14
        );
        $active = $this->makeNeighbor(
            302,
            $activeCell,
            5,
            'Активный',
            50,
            date('Y-m-d H:i:s', strtotime('-1 hour'))
        );

        $service  = new PlayerDetectionService();
        $rendered = $service->renderDetectionMessage($attacker, [$abandoned, $active], 12, 14, true);

        $lines        = explode("\n", $rendered['text']);
        $activeLines  = array_values(array_filter($lines, static fn (string $l) => str_contains($l, '<b>Активный</b>')));
        $lostLines    = array_values(array_filter($lines, static fn (string $l) => str_contains($l, '<b>Заброшенный</b>')));

        $this->assertCount(1, $activeLines);
        $this->assertCount(1, $lostLines);
        $this->assertStringNotContainsString('давно не в сети', $activeLines[0], 'недавно активный не должен нести метку брошенного');
        $this->assertStringContainsString('давно не в сети', $lostLines[0], 'строка брошенного обязана нести метку');

        $activeIndex = array_search($activeLines[0], $lines, true);
        $lostIndex   = array_search($lostLines[0], $lines, true);
        $this->assertLessThan($lostIndex, $activeIndex, 'недавно активный обязан идти раньше брошенного');
    }

    /**
     * Жёсткая граница длины: даже при высокой `max_listed` (500) и 200 соседях с длинными
     * именами итоговый текст обязан оставаться короче лимита Telegram (4096) — граница
     * срабатывает НЕЗАВИСИМО от значения ручки, не только когда она мала.
     */
    public function testHardTextLengthBoundaryOnTwoHundredNeighborsRegardlessOfMaxListedSetting(): void
    {
        $attackerCell = $this->uniqueCell();
        $this->insertMapCell($attackerCell);
        $attacker = $this->makeAttacker($attackerCell);

        $longNamePart = str_repeat('Оченьдлинноеимясоседа', 3); // ~63 символа
        $neighbors    = [];
        for ($i = 1; $i <= 200; $i++) {
            $cell = $this->uniqueCell();
            $this->insertMapCell($cell);
            $neighbors[] = $this->makeNeighbor(400 + $i, $cell, $i, "{$longNamePart}{$i}");
        }

        $service  = new PlayerDetectionService();
        $rendered = $service->renderDetectionMessage($attacker, $neighbors, 500, 14, true);

        $this->assertLessThan(4096, mb_strlen($rendered['text']), 'текст обязан быть короче лимита Telegram даже при max_listed=500 и 200 длинных именах');
        // Список неполный — хоть остаток и свёрнут, игрок обязан узнать об этом.
        $this->assertStringContainsString('поблизости', $rendered['text']);
    }

    public function testLockInsteadOfAttackWhenPvPRestrictionDeniesByLevel(): void
    {
        $attackerCell = $this->uniqueCell();
        $this->insertMapCell($attackerCell);
        $attacker = $this->makeAttacker($attackerCell);

        $lowLevelCell = $this->uniqueCell();
        $this->insertMapCell($lowLevelCell);
        // Уровень 1 ниже дефолтного/safety-net min_level=5 → checkPvPAllowed запретит.
        $lowLevelNeighbor = $this->makeNeighbor(501, $lowLevelCell, 1, 'Новичок', 1);

        $service  = new PlayerDetectionService();
        $rendered = $service->renderDetectionMessage($attacker, [$lowLevelNeighbor], 12, 14, true);

        $buttons     = $this->flattenButtons($rendered['keyboard']);
        $lockButton  = null;
        foreach ($buttons as $btn) {
            if (($btn['callback_data'] ?? '') === 'attackPlayer_501') {
                $lockButton = $btn;
            }
        }

        $this->assertNotNull($lockButton, 'кнопка входа для заблокированного соседа обязана присутствовать (кнопка не исчезает)');
        $this->assertStringStartsWith('🔒', $lockButton['text']);
        $this->assertStringContainsString('Уровень', $lockButton['text']);
        $this->assertStringNotContainsString('⚔️ Атаковать', $lockButton['text']);
    }

    public function testAllowedNeighborGetsAttackButtonNotLock(): void
    {
        $attackerCell = $this->uniqueCell();
        $this->insertMapCell($attackerCell);
        $attacker = $this->makeAttacker($attackerCell);

        $okCell = $this->uniqueCell();
        $this->insertMapCell($okCell);
        $okNeighbor = $this->makeNeighbor(601, $okCell, 1, 'РавныйПротивник', 50);

        $service  = new PlayerDetectionService();
        $rendered = $service->renderDetectionMessage($attacker, [$okNeighbor], 12, 14, true);

        $buttons = $this->flattenButtons($rendered['keyboard']);
        $found   = null;
        foreach ($buttons as $btn) {
            if (($btn['callback_data'] ?? '') === 'attackPlayer_601') {
                $found = $btn;
            }
        }

        $this->assertNotNull($found);
        $this->assertSame('⚔️ Атаковать', $found['text']);
    }

    /**
     * Ни одной одиночной кнопки в ряду (`.claude/rules/telegram-ux.md`) и «Бежать» — одна
     * на всё сообщение, а не на каждого соседа (было: по одной на каждого из 123).
     */
    public function testNoSingleButtonRowsAndRunAwayAppearsOnce(): void
    {
        $attackerCell = $this->uniqueCell();
        $this->insertMapCell($attackerCell);
        $attacker = $this->makeAttacker($attackerCell);

        $neighbors = [];
        for ($i = 1; $i <= 7; $i++) {
            $cell = $this->uniqueCell();
            $this->insertMapCell($cell);
            $neighbors[] = $this->makeNeighbor(700 + $i, $cell, $i, "Сосед{$i}");
        }

        $service  = new PlayerDetectionService();
        $rendered = $service->renderDetectionMessage($attacker, $neighbors, 12, 14, true);

        foreach ($rendered['keyboard']['inline_keyboard'] as $row) {
            $this->assertGreaterThanOrEqual(2, count($row), 'ни одна строка клавиатуры не может нести единственную кнопку');
        }

        $runAwayCount = 0;
        foreach ($this->flattenButtons($rendered['keyboard']) as $btn) {
            if (($btn['callback_data'] ?? '') === 'runAway') {
                $runAwayCount++;
            }
        }
        $this->assertSame(1, $runAwayCount, '«🏃 Бежать» обязана быть одна на всё сообщение');
    }
}

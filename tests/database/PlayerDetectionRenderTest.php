<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\CreateActionLogTable;
use App\Database\Migrations\CreateCharactersTable;
use App\Database\Migrations\CreateMapTable;
use App\Database\Migrations\CreatePlayerDetectionHistoryTable;
use App\Database\Migrations\CreateTelegramUsersTable;
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
    private bool $createdMap                   = false;
    private bool $createdTelegramUsers          = false;
    private bool $createdCharacters             = false;
    private bool $createdActionLog              = false;
    private bool $createdPlayerDetectionHistory = false;

    /** @var list<int> */
    private array $mapIds = [];
    /** @var list<int> */
    private array $characterIds = [];
    /** @var list<int> */
    private array $telegramUserIds = [];

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

        // pvp-detection-clarity-22 (minor #G): эта тройка нужна только тесту на
        // `detectNearbyPlayers()` (реальный путь записи истории), остальные тесты
        // файла собирают `renderDetectionMessage()` напрямую и её не трогают —
        // но `createIfMissing`-приём тот же, что у `PvpStandoffServiceTest`.
        if (! $this->conn->tableExists('telegram_users')) {
            $this->requireMigration('CreateTelegramUsersTable', '2024-03-20-153728_CreateTelegramUsersTable.php');
            $forge = Database::forge('tests');
            (new CreateTelegramUsersTable($forge instanceof Forge ? $forge : null))->up();
            $this->conn->resetDataCache();
            $this->createdTelegramUsers = true;
        }
        if (! $this->conn->tableExists('characters')) {
            $this->requireMigration('CreateCharactersTable', '2024-03-20-154155_CreateCharactersTable.php');
            $forge = Database::forge('tests');
            (new CreateCharactersTable($forge instanceof Forge ? $forge : null))->up();
            $this->conn->resetDataCache();
            $this->createdCharacters = true;
        }
        if (! $this->conn->tableExists('action_log')) {
            $this->requireMigration('CreateActionLogTable', '2024-03-18-134951_CreateActionLogTable.php');
            $forge = Database::forge('tests');
            (new CreateActionLogTable($forge instanceof Forge ? $forge : null))->up();
            $this->conn->resetDataCache();
            $this->createdActionLog = true;
        }
        if (! $this->conn->tableExists('player_detection_history')) {
            $this->requireMigration('CreatePlayerDetectionHistoryTable', '2024-09-26-083705_CreatePlayerDetectionHistoryTable.php');
            $forge = Database::forge('tests');
            (new CreatePlayerDetectionHistoryTable($forge instanceof Forge ? $forge : null))->up();
            $this->conn->resetDataCache();
            $this->createdPlayerDetectionHistory = true;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->mapIds as $id) {
            $this->conn->table('map')->where('id', $id)->delete();
        }
        foreach ($this->characterIds as $id) {
            $this->conn->table('player_detection_history')->where('detector_player_id', $id)->orWhere('detected_player_id', $id)->delete();
            $this->conn->table('action_log')->where('character_id', $id)->delete();
            $this->conn->table('characters')->where('id', $id)->delete();
        }
        foreach ($this->telegramUserIds as $id) {
            $this->conn->table('telegram_users')->where('id', $id)->delete();
        }

        if ($this->createdPlayerDetectionHistory) {
            $this->requireMigration('CreatePlayerDetectionHistoryTable', '2024-09-26-083705_CreatePlayerDetectionHistoryTable.php');
            $forge = Database::forge('tests');
            (new CreatePlayerDetectionHistoryTable($forge instanceof Forge ? $forge : null))->down();
        }
        if ($this->createdActionLog) {
            $this->requireMigration('CreateActionLogTable', '2024-03-18-134951_CreateActionLogTable.php');
            $forge = Database::forge('tests');
            (new CreateActionLogTable($forge instanceof Forge ? $forge : null))->down();
        }
        if ($this->createdCharacters) {
            $this->requireMigration('CreateCharactersTable', '2024-03-20-154155_CreateCharactersTable.php');
            $forge = Database::forge('tests');
            (new CreateCharactersTable($forge instanceof Forge ? $forge : null))->down();
        }
        if ($this->createdTelegramUsers) {
            $this->requireMigration('CreateTelegramUsersTable', '2024-03-20-153728_CreateTelegramUsersTable.php');
            $forge = Database::forge('tests');
            (new CreateTelegramUsersTable($forge instanceof Forge ? $forge : null))->down();
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
     * Настоящая строка `characters` (+ `telegram_users`) — нужна только тесту на
     * `detectNearbyPlayers()`: он идёт реальным SQL-путём (join по `cell_number`),
     * а не собирает `renderDetectionMessage()` из синтетического массива.
     */
    private function insertRealCharacter(int $cellNumber, string $name, int $level = 50): int
    {
        $this->conn->table('telegram_users')->insert([
            'telegram_id' => random_int(100_000_000, 999_999_999),
        ]);
        $telegramUserId          = (int) $this->conn->insertID();
        $this->telegramUserIds[] = $telegramUserId;

        $this->conn->table('characters')->insert([
            'name'             => $name,
            'level'            => $level,
            'cell_number'      => (string) $cellNumber,
            'telegram_user_id' => $telegramUserId,
            'created_at'       => date('Y-m-d H:i:s', strtotime('-400 days')),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        $id                   = (int) $this->conn->insertID();
        $this->characterIds[] = $id;

        return $id;
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
        $this->assertStringContainsString('Новичок', $lockButton['text'], 'BLOCK #11: замок обязан нести имя соседа, а не только причину');
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
        $this->assertSame('⚔️ Атаковать: РавныйПротивник', $found['text'], 'BLOCK #11: метка обязана нести имя соседа, а не быть одинаковой на всех кнопках');
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

    /**
     * BLOCK #8: обнаружено больше, чем влезает в max_listed — истории обязана коснуться
     * только показанная часть, непоказанный сосед не должен глохнуть по кулдауну пары
     * на следующем шаге, ни разу не будучи показанным игроку.
     */
    public function testShownIdsCoverOnlyRenderedNeighborsNotAllCandidates(): void
    {
        $attackerCell = $this->uniqueCell();
        $this->insertMapCell($attackerCell);
        $attacker = $this->makeAttacker($attackerCell);

        $neighbors = [];
        for ($i = 1; $i <= 123; $i++) {
            $cell = $this->uniqueCell();
            $this->insertMapCell($cell);
            $neighbors[] = $this->makeNeighbor(800 + $i, $cell, $i, "Сосед{$i}");
        }

        $service  = new PlayerDetectionService();
        $rendered = $service->renderDetectionMessage($attacker, $neighbors, 12, 14, true);

        $this->assertCount(12, $rendered['shown_ids'], 'shown_ids обязан содержать ровно столько id, сколько строк реально показано');

        $shownSet = array_flip($rendered['shown_ids']);
        foreach (array_slice($neighbors, 0, 12) as $expectedShown) {
            $this->assertArrayHasKey($expectedShown['id'], $shownSet, 'первые max_listed соседей (после сортировки — все одинаково активны, порядок по расстоянию) обязаны попасть в shown_ids');
        }
        foreach (array_slice($neighbors, 12) as $expectedHidden) {
            $this->assertArrayNotHasKey($expectedHidden['id'], $shownSet, 'непоказанный сосед не должен попасть в shown_ids — иначе история глушит его по кулдауну, хотя он не был показан');
        }
    }

    /**
     * BLOCK-2 minor #G: `-18` закрывает `shown_ids` только на уровне `renderDetectionMessage()` —
     * само место записи в `player_detection_history` внутри `detectNearbyPlayers()` (реальный
     * SQL-join + цикл сбора кандидатов) ничем не покрыто: если кто-то вернёт `insert()` в цикл
     * сбора (до того, как рендер решит, кто реально попал в текст), тесты на уровне рендера
     * останутся зелёными, а история продолжит писаться на всех кандидатов. Гоняем настоящий
     * `detectNearbyPlayers()`, а не `renderDetectionMessage()` напрямую.
     */
    public function testDetectNearbyPlayersWritesHistoryOnlyForShownNeighbors(): void
    {
        // Request::send() в Longman-библиотеке отдаёт фейковый ServerResponse вместо
        // реального похода в Telegram API, когда эта константа определена (тот же
        // приём, что `StandoffAttackGateTest::callbackQuery()`) — insert в историю
        // происходит ДО попытки отправки, но без этого тест бил бы по сети.
        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }

        $attackerCell = $this->uniqueCell();
        $this->insertMapCell($attackerCell);
        $attackerId = $this->insertRealCharacter($attackerCell, 'Детектор', 50);

        // Все 15 соседей — на ТОЙ ЖЕ клетке (distance=0, уровень 50 → радиус детекта 2,
        // 0<=радиус — все в зоне обнаружения). `game_settings` в этом файле не заведена
        // (GameSettingsService деградирует на default), поэтому max_listed=12 —
        // ровно потолок, использованный `testShownIdsCoverOnlyRenderedNeighborsNotAllCandidates`
        // выше. Ни у кого нет action_log — last_active равны (null у всех), distance
        // равен (0 у всех) — сортировка renderDetectionMessage() стабильно падает на id
        // по возрастанию, значит именно 12 МЕНЬШИХ id обязаны попасть в shown.
        $neighborIds = [];
        for ($i = 1; $i <= 15; $i++) {
            $neighborIds[] = $this->insertRealCharacter($attackerCell, "Сосед{$i}", 50);
        }
        sort($neighborIds);

        (new PlayerDetectionService())->detectNearbyPlayers($attackerId);

        $historyRows = $this->conn->table('player_detection_history')
            ->where('detector_player_id', $attackerId)
            ->get()->getResultArray();
        $historyIds  = array_map(static fn (array $r): int => (int) $r['detected_player_id'], $historyRows);
        sort($historyIds);

        $expectedShown  = array_slice($neighborIds, 0, 12);
        $expectedHidden = array_slice($neighborIds, 12);

        $this->assertCount(12, $historyIds, 'история обязана нести ровно столько строк, сколько реально показано (max_listed=12), а не всех 15 кандидатов');
        $this->assertSame($expectedShown, $historyIds, 'история обязана нести именно показанных (по сортировке рендера) соседей');
        foreach ($expectedHidden as $hiddenId) {
            $this->assertNotContains($hiddenId, $historyIds, 'непоказанный сосед не должен попасть в историю прямо из цикла сбора кандидатов');
        }
    }

    /**
     * BLOCK #11: длинное имя не должно ломать клавиатуру (одиночные строки, разрыв упаковки) —
     * метка обрезается с многоточием, но остаётся привязанной к своему соседу.
     */
    public function testButtonLabelTruncatesLongNameAndKeepsPackingIntact(): void
    {
        $attackerCell = $this->uniqueCell();
        $this->insertMapCell($attackerCell);
        $attacker = $this->makeAttacker($attackerCell);

        $longName  = str_repeat('Оченьдлинноеимясоседа', 3); // ~63 символа
        $shortCell = $this->uniqueCell();
        $longCell  = $this->uniqueCell();
        $this->insertMapCell($shortCell);
        $this->insertMapCell($longCell);

        $short = $this->makeNeighbor(901, $shortCell, 1, 'Крош');
        $long  = $this->makeNeighbor(902, $longCell, 2, $longName);

        $service  = new PlayerDetectionService();
        $rendered = $service->renderDetectionMessage($attacker, [$short, $long], 12, 14, true);

        $buttons = $this->flattenButtons($rendered['keyboard']);

        $shortBtn = null;
        $longBtn  = null;
        foreach ($buttons as $btn) {
            if (($btn['callback_data'] ?? '') === 'attackPlayer_901') {
                $shortBtn = $btn;
            }
            if (($btn['callback_data'] ?? '') === 'attackPlayer_902') {
                $longBtn = $btn;
            }
        }

        $this->assertNotNull($shortBtn);
        $this->assertNotNull($longBtn);
        $this->assertStringContainsString('Крош', $shortBtn['text']);
        $this->assertLessThanOrEqual(40, mb_strlen($longBtn['text']), 'метка с длинным именем обязана быть обрезана, а не растягивать клавиатуру');
        $this->assertStringContainsString('…', $longBtn['text'], 'обрезанное имя несёт многоточие как сигнал усечения');
        $this->assertNotSame($shortBtn['text'], $longBtn['text'], 'метки разных соседей не должны совпадать');

        foreach ($rendered['keyboard']['inline_keyboard'] as $row) {
            $this->assertGreaterThanOrEqual(2, count($row), 'ни одна строка клавиатуры не может нести единственную кнопку');
        }
    }

    /**
     * BLOCK-2 minor #K: обрезка одним многоточием не гарантирует различимость — два соседа
     * с совпадающими первыми 19 символами имени получали бы буквально одинаковую метку
     * (остаток дыры major #11). `buttonName()` теперь несёт хвост `№<id>` при обрезке —
     * id уникален по конструкции, поэтому различимость держится даже на полной коллизии
     * видимой части имени.
     */
    public function testTwoNeighborsWithNamesMatchingFirstNineteenCharsGetDistinctButtonLabels(): void
    {
        $attackerCell = $this->uniqueCell();
        $this->insertMapCell($attackerCell);
        $attacker = $this->makeAttacker($attackerCell);

        $shared = str_repeat('Б', 19); // первые 19 символов совпадают у обоих соседей
        $nameA  = $shared . 'ПерваяХвост';
        $nameB  = $shared . 'ВтораяХвост';
        $this->assertSame(mb_substr($nameA, 0, 19), mb_substr($nameB, 0, 19), 'предпосылка теста: первые 19 символов обязаны совпадать');

        $cellA = $this->uniqueCell();
        $cellB = $this->uniqueCell();
        $this->insertMapCell($cellA);
        $this->insertMapCell($cellB);

        $neighborA = $this->makeNeighbor(950, $cellA, 1, $nameA);
        $neighborB = $this->makeNeighbor(951, $cellB, 2, $nameB);

        $service  = new PlayerDetectionService();
        $rendered = $service->renderDetectionMessage($attacker, [$neighborA, $neighborB], 12, 14, true);

        $buttons = $this->flattenButtons($rendered['keyboard']);
        $btnA    = null;
        $btnB    = null;
        foreach ($buttons as $btn) {
            if (($btn['callback_data'] ?? '') === 'attackPlayer_950') {
                $btnA = $btn;
            }
            if (($btn['callback_data'] ?? '') === 'attackPlayer_951') {
                $btnB = $btn;
            }
        }

        $this->assertNotNull($btnA);
        $this->assertNotNull($btnB);
        $this->assertNotSame($btnA['text'], $btnB['text'], 'соседи с совпадающими первыми 19 символами имени обязаны получить различимые метки кнопок');
        $this->assertStringContainsString('950', $btnA['text'], 'различимость обязана держаться на id, раз видимая часть имени совпала целиком');
        $this->assertStringContainsString('951', $btnB['text']);
    }
}

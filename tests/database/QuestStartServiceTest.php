<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\Quest\GenericQuestStartAction;
use App\Controllers\Telegram\Commands\Actions\Quest\QuestStartExplore30Cells;
use App\Services\Quest\QuestStartService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Request as LongmanRequest;
use Longman\TelegramBot\Telegram;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Http\Message\RequestInterface;

/**
 * w2-n5-deeds-01 — старт квеста в ядре {@see QuestStartService}: повтор не создаёт второй строки
 * `quest_steps`, отказы несут прежние тексты бота, запись идёт под блокировкой строки персонажа.
 *
 * «Параллельный старт» — детерминированно: слушатель `DBQuery` ловит проверку «уже начат?» (чтение
 * `quest_steps` перед вставкой), и второе соединение в этот момент пытается взять ту же блокировку, как
 * это сделал бы параллельный старт из бота или веба. Блокировка держится с проверки, а не только с
 * вставки (вставка сама берёт S-блокировку родителя по внешнему ключу — на ней пробовать бесполезно):
 * второе соединение упирается в таймаут, а его повторный старт после фиксации первого получает `already`.
 *
 * Схема — прогоном миграций (урок: ручной CREATE TABLE тихо расходится с продом).
 *
 * @internal
 */
final class QuestStartServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-05-08-220000_AddDisableMediaFlag',
        '2024-04-26-121416_CreateQuestsTable',
        '2024-04-26-192334_CreateQuestStepsTable',
        '2026-05-21-210000_V11AddQuestPrerequisite',
        '2026-05-21-230000_V12AddQuestObjectiveColumns',
        '2026-06-04-100000_W11AddQuestBranchColumns',
        '2026-06-14-100000_AddFactionIdToQuests',
        '2024-05-15-131853_CreateFactionsTable',
        '2024-05-15-132233_CreateCharacterFactionsTable',
        '2026-05-19-100000_CreateGameSettingsTable',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'quests', 'quest_steps', 'factions', 'character_factions', 'game_settings',
    ];

    private BaseConnection $conn;

    /** @var (callable(mixed): void)|null */
    private $listener = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                require_once APPPATH . 'Database/Migrations/' . $file . '.php';
                $class = 'App\\Database\\Migrations\\' . substr($file, 18);
                $m     = new $class($forge instanceof Forge ? $forge : null);
                $this->assertInstanceOf(Migration::class, $m);
                $m->up();
            }
            $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1)");
            $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1)');
            $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, 771000005, 'Тест'), (8, 771000006, 'Новичок')");
            $this->conn->query(
                'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
                . " VALUES (1, 7, 'Тест', 10, 1.5, 90, 10, 0.5, 0.01, 0.01, 0, 5, 0), (2, 8, 'Новичок', 1, 0, 90, 10, 0.5, 0.01, 0.01, 0, 5, 0)"
            );
            $this->conn->query("DELETE FROM quests");
            $this->conn->query(
                'INSERT INTO quests (id, title_ru, title_en, description, status, min_level, reward, reward_type, objective_type, objective_target, objective_qty, prerequisite_quest, faction_id) VALUES'
                . " (1, 'Запас дров', 'CollectWood', 'Принеси дерево на базу.', 'active', 5, 300, 'gold', 'collect_resource', 'wood', 10, NULL, NULL),"
                . " (2, 'Долг фракции', 'FactionDebt', 'Для своих.', 'active', 1, 100, 'gold', 'collect_resource', 'wood', 5, NULL, 2),"
                . " (3, 'Второй этап', 'ChainStage', 'Этап цепочки.', 'active', 1, 100, 'gold', 'collect_resource', 'wood', 5, 'CollectWood', NULL),"
                . " (4, 'Изучить 30 ячеек', 'Explore30Cells', 'Разведка.', 'active', 5, 500, 'gold', NULL, NULL, NULL, NULL, NULL)"
            );
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->setFlag('quests.extended_enabled', true);
        service('cache')->clean();
    }

    protected function tearDown(): void
    {
        if ($this->listener !== null) {
            Events::removeListener('DBQuery', $this->listener);
        }
        service('cache')->clean();
        $this->dropTables();
        $this->conn->resetDataCache();
        parent::tearDown();
    }

    public function testSecondStartOfTheSameQuestIsAlreadyAndWritesOneRow(): void
    {
        $service = new QuestStartService();

        $first  = $service->start(1, 'CollectWood');
        $second = $service->start(1, 'CollectWood');

        $this->assertTrue($first['ok']);
        $this->assertSame(QuestStartService::STARTED, $first['code']);
        $this->assertSame('Запас дров', $first['title_ru']);
        $this->assertSame(300, $first['reward']);
        $this->assertFalse($second['ok']);
        $this->assertSame(QuestStartService::ALREADY, $second['code']);
        $this->assertSame('Ты уже начал этот квест — смотри «🚀 Активные квесты».', $second['message']);
        $this->assertSame(1, $this->steps(1, 1));
    }

    public function testRefusalsCarryTheBotTextsAndWriteNothing(): void
    {
        $service = new QuestStartService();

        $cases = [
            'уровень'     => [2, 'CollectWood', QuestStartService::LOCKED, 'Квест доступен с 5-го уровня.'],
            'фракция'     => [1, 'FactionDebt', QuestStartService::LOCKED, 'Этот квест доступен только членам соответствующей фракции.'],
            'этап'        => [1, 'ChainStage', QuestStartService::DISABLED, 'Этот квест нельзя начать вручную.'],
            'нет квеста'  => [1, 'NoSuchQuest', QuestStartService::UNKNOWN, 'Квест не найден или недоступен.'],
            'пустой ключ' => [1, '', QuestStartService::UNKNOWN, 'Некорректный квест.'],
            'персонаж'    => [99, 'CollectWood', QuestStartService::UNKNOWN, 'Персонаж не найден.'],
        ];
        foreach ($cases as $case => [$charId, $titleEn, $code, $message]) {
            $res = $service->start($charId, $titleEn);
            $this->assertFalse($res['ok'], $case);
            $this->assertSame($code, $res['code'], $case);
            $this->assertSame($message, $res['message'], $case);
        }
        $this->assertSame(0, (int) $this->other()->query('SELECT COUNT(*) AS n FROM quest_steps')->getRowArray()['n']);
    }

    /** Bespoke-квест со своей кнопкой в боте (Explore30Cells) стартует и через ядро — как его видят «📜 Доступные». Прочие bespoke — нет. */
    public function testLegacyBespokeQuestStartsThroughTheCoreOthersStayManualOnly(): void
    {
        $this->conn->query(
            'INSERT INTO quests (id, title_ru, title_en, description, status, min_level, reward, reward_type, objective_type, prerequisite_quest) VALUES'
            . " (5, 'Старый квест', 'OldBespoke', 'Без обработчика.', 'active', 1, 10, 'gold', NULL, NULL)"
        );
        $this->setFlag('quests.extended_enabled', false);
        $service = new QuestStartService();

        $ok = $service->start(1, 'Explore30Cells');
        $this->assertTrue($ok['ok'], 'своя кнопка в боте — старт и без ADR-088');
        $this->assertSame('Изучить 30 ячеек', $ok['title_ru']);
        $this->assertSame(QuestStartService::ALREADY, $service->start(1, 'Explore30Cells')['code']);
        $this->assertSame(1, $this->steps(1, 4));

        $this->assertSame(QuestStartService::LOCKED, $service->start(2, 'Explore30Cells')['code'], 'уровень проверяется и у bespoke');
        $this->assertSame(QuestStartService::DISABLED, $service->start(1, 'OldBespoke')['code']);
        $this->assertSame(0, $this->steps(1, 5));
    }

    public function testKillswitchOffMakesTheStartDisabled(): void
    {
        $this->setFlag('quests.extended_enabled', false);

        $res = (new QuestStartService())->start(1, 'CollectWood');

        $this->assertSame(QuestStartService::DISABLED, $res['code']);
        $this->assertSame(0, $this->steps(1, 1));
    }

    public function testParallelStartWaitsForTheCharacterLockAndThenSeesTheRow(): void
    {
        $blocked = null;
        $this->onQuery('FROM `quest_steps`', function () use (&$blocked): void {
            $blocked = $this->characterLockIsHeld(1);
        });

        $first = (new QuestStartService())->start(1, 'CollectWood');
        // Параллельный старт после фиксации первого — тот же ядерный путь.
        $retry = (new QuestStartService())->start(1, 'CollectWood');

        $this->assertTrue($first['ok']);
        $this->assertTrue($blocked, 'во время проверки «уже начат?» строка персонажа заблокирована — второй старт ждёт');
        $this->assertSame(QuestStartService::ALREADY, $retry['code']);
        $this->assertSame(1, $this->steps(1, 1));
    }

    public function testClaimFirstStepIsTheOnlyWriterForLegacyStarts(): void
    {
        $service = new QuestStartService();

        $this->assertTrue($service->claimFirstStep(2, 4, 'Начало квеста на изучение 30 ячеек'));
        $this->assertFalse($service->claimFirstStep(2, 4, 'Начало квеста на изучение 30 ячеек'));
        $this->assertSame(1, $this->steps(2, 4));
    }

    /**
     * Бот: ответ generic-старта — байт в байт прежний; повторный тап — прежний алерт, строка одна.
     * Отдельный процесс: соседние тесты определяют `PHPUNIT_TESTSUITE`, и Longman под ним отвечает фейком.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGenericBotStartAnswerIsUnchanged(): void
    {
        $sent = $this->pressBot(771000005, 'questStartCollectWood', GenericQuestStartAction::class);

        $edit = $this->only($sent, 'editMessageText');
        $this->assertSame(
            "🔍 *Запас дров*\n\n📜 Принеси дерево на базу.\n\n🏆 Награда: *300* золота\n\n🛡️ Отслеживай прогресс в *«🚀 Активные квесты»*.",
            $edit['text'] ?? null
        );
        $this->assertSame(
            json_encode(['inline_keyboard' => [[
                ['text' => '🚀 Активные квесты', 'callback_data' => 'activeQuests'],
                ['text' => '📜 Квесты и задания', 'callback_data' => 'questAndTask'],
            ]]]),
            $edit['reply_markup'] ?? null
        );
        $this->assertSame('Квест начат!', $this->only($sent, 'answerCallbackQuery')['text'] ?? null);

        $again = $this->only($this->pressBot(771000005, 'questStartCollectWood', GenericQuestStartAction::class), 'answerCallbackQuery');
        $this->assertSame('Ты уже начал этот квест — смотри «🚀 Активные квесты».', $again['text'] ?? null);
        $this->assertSame(1, $this->steps(1, 1));
    }

    /**
     * Бот: легаси-старт рисует прежний текст; второй тап, прошедший мимо быстрой проверки (квест
     * выше уровня — старый код его не видел в «доступных» и вставлял вторую строку), отказывает в ядре
     * прежним текстом «уже был запущен».
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLegacyBotStartAnswerIsUnchangedAndTheCoreRefusesTheSecondRow(): void
    {
        $sent = $this->pressBot(771000006, 'questStartExplore30Cells', QuestStartExplore30Cells::class);

        $this->assertSame(
            "🗺️ *Великий искатель приключений!*\n\n"
            . "Ты принял вызов квеста *Изучить 30 ячеек*. \nВ награду за твоё мастерство и смелость, при успешном завершении, ты получишь: *500 золото* 🏆.\n"
            . "\n🛡️ Отслеживай прогресс в разделе *'Активные квесты'*.\n"
            . "\n⚔️ Как только все испытания будут преодолены, квест закроется, и ты получишь заслуженные награды и честь.\n\n"
            . "🌟 _Пусть удача сопутствует тебе на этом пути!_",
            $this->only($sent, 'editMessageText')['text'] ?? null
        );

        $again = $this->only($this->pressBot(771000006, 'questStartExplore30Cells', QuestStartExplore30Cells::class), 'sendMessage');
        $this->assertSame(
            "Квест *Изучить 30 ячеек* уже был запущен.\nСмотрите его в разделе:\n*'🚀 Активные квесты'*\nили в разделе\n*'📅 Доступные квесты'*.\n",
            $again['text'] ?? null
        );
        $this->assertSame(1, $this->steps(2, 4));
    }

    // ── помощники ────────────────────────────────────────────────────────────

    private function onQuery(string $fragment, callable $action): void
    {
        $fired          = false;
        $this->listener = static function ($query) use ($fragment, $action, &$fired): void {
            if (! $fired && str_contains((string) $query->getQuery(), $fragment)) {
                $fired = true;
                $action();
            }
        };
        Events::on('DBQuery', $this->listener);
    }

    /** Второе соединение пытается взять блокировку строки персонажа, как параллельный старт. */
    private function characterLockIsHeld(int $characterId): bool
    {
        $other = $this->other();
        $other->query('SET SESSION innodb_lock_wait_timeout = 1');
        $other->transBegin();
        try {
            $res  = $other->query('SELECT id FROM characters WHERE id = ? FOR UPDATE', [$characterId]);
            $held = $res === false && (int) ($other->error()['code'] ?? 0) === 1205;
        } catch (\Throwable $e) {
            $held = str_contains($e->getMessage(), 'Lock wait timeout');
        } finally {
            $other->transRollback();
        }

        return $held;
    }

    /** @return list<array<string, mixed>> */
    private function pressBot(int $fromId, string $data, string $actionClass): array
    {
        $sent = [];
        new Telegram('123456:TEST_TOKEN', 'wildworldtest_bot');
        LongmanRequest::setClient(new Client(['handler' => static function (RequestInterface $request) use (&$sent): PromiseInterface {
            parse_str((string) $request->getBody(), $params);
            $path   = explode('/', $request->getUri()->getPath());
            $sent[] = ['method' => (string) end($path)] + $params;

            return Create::promiseFor(new Response(200, [], '{"ok":true,"result":true}'));
        }]));

        $cbq = new CallbackQuery([
            'id'      => 'cbq-1',
            'from'    => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => ['message_id' => 1, 'date' => time(), 'chat' => ['id' => $fromId, 'type' => 'private'], 'text' => 'x'],
            'chat_instance' => 'ci', 'data' => $data,
        ]);
        $action = new $actionClass($cbq);
        $this->assertTrue(method_exists($action, 'handle'));
        $action->handle();

        return $sent;
    }

    /**
     * @param list<array<string, mixed>> $sent
     * @return array<string, mixed>
     */
    private function only(array $sent, string $method): array
    {
        $calls = array_values(array_filter($sent, static fn (array $c): bool => $c['method'] === $method));
        $this->assertCount(1, $calls, $method);

        return $calls[0];
    }

    private function setFlag(string $key, bool $on): void
    {
        $this->conn->query('DELETE FROM game_settings WHERE setting_key = ?', [$key]);
        $now = date('Y-m-d H:i:s');
        $this->conn->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'world', 'value_type' => 'bool', 'value_bool' => $on ? 1 : 0,
            'default_value_text' => '0', 'rationale_text' => 't', 'effect_text' => 't', 'above_effect_text' => 't',
            'below_effect_text' => 't', 'created_at' => $now, 'updated_at' => $now,
        ]);
        service('cache')->clean();
    }

    private function steps(int $characterId, int $questId): int
    {
        return (int) $this->other()->query(
            'SELECT COUNT(*) AS n FROM quest_steps WHERE character_id = ? AND quest_id = ?',
            [$characterId, $questId]
        )->getRowArray()['n'];
    }

    private function other(): BaseConnection
    {
        return Database::connect(null, false);
    }

    private function dropTables(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLES) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}

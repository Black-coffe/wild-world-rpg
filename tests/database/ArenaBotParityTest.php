<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\PVP\ArenaAction;
use App\Controllers\Telegram\Commands\Actions\PVP\DuelAction;
use App\Controllers\Telegram\Commands\Actions\PVP\PvpLadderAction;
use App\Controllers\Telegram\Commands\Actions\SettingsAction;
use App\Database\Migrations\WidenBattleLogsType;
use App\Services\Logging\TelegramDeliveryProbe;
use App\Services\More\MoreSurfaceService;
use App\Services\Telegram\KeyboardNormalizer;
use App\Services\Telegram\TelegramBridge;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Request as LongmanRequest;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Http\Message\RequestInterface;

/**
 * w2-n7-combat-02 — паритет бота: арена, итог дуэли и рейтинг PvP после выноса в `ArenaScreenService`
 * дают тот же текст и те же кнопки. Снимки сняты со старых handler'ов. Отличаются только добавленные
 * кнопки: «📜 Мои бои» (`battles`) на арене и в рейтинге, «📜 Разбор боя» (`battleLog_<id>`) под итогом
 * дуэли у обоих. Кнопки сравниваются плоским списком (порядок чтения), раскладка рядов — выход
 * `KeyboardNormalizer` — отдельно проверяется на одиночки.
 *
 * Дуэль — настоящий бой под `mt_srand(42)`: перенос не должен ни сдвинуть, ни добавить бросков.
 *
 * Отдельный процесс: соседние тесты определяют `PHPUNIT_TESTSUITE`, и Longman под ним отвечает фейком.
 *
 * @internal
 */
final class ArenaBotParityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-05-08-220000_AddDisableMediaFlag',
        '2026-06-04-230000_W17AddCharacterDuelsOpen',
        '2026-06-05-100000_W18CreatePvpLadderTable',
        '2024-05-15-131853_CreateFactionsTable',
        '2024-05-15-132233_CreateCharacterFactionsTable',
        '2025-02-08-194808_CreateOutfitsTable',
        '2025-02-08-195713_CreateWeaponsTable',
        '2025-02-10-224703_CreateCharactersOutfitsTable',
        '2025-02-11-115603_CreateCharactersWeaponsTable',
        '2024-03-18-134951_CreateActionLogTable',
        '2026-05-19-100000_CreateGameSettingsTable',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'pvp_ladder', 'factions', 'character_factions',
        'outfits', 'weapons', 'characters_outfits', 'characters_weapons', 'action_log', 'game_settings', 'battle_logs',
    ];

    private const TG     = 771000031;
    private const TG_DEF = 771000032;

    private const SEED = 42;

    /** Мост `TelegramBridge::ensure()` поднимается из окружения — как у живого бота. */
    private const ENV = ['telegram.API_KEY' => '123456:TEST_TOKEN', 'telegram.BOT_USERNAME' => 'wildworldtest_bot'];

    /** @var array<string, string|false> */
    private array $envBackup = [];

    /**
     * Снимки «до» (сняты со старых handler'ов): метод, текст, разметка — дословно.
     *
     * @var array<string, array{method: string, text: mixed, reply_markup: mixed}>
     */
    private const SNAPSHOTS = [
        'arena_closed' => [
            'method'       => 'editMessageText',
            'text'         => '🏟 *Арена — равные дуэли*

_Спортивный поединок на равных статах: ни здоровья, ни опыта не теряется. Решают билд и удача. Победы идут в 🏆 Рейтинг PvP._

⚔️ *Открытые бойцы:*
• Боб (ур.12 · 30 очк.)
• Ева (ур.5 · 20 очк.)

🔒 Ты *закрыт* для дуэлей. Откройся в ⚙️ Настройках — тогда и тебя смогут вызвать на арену.',
            'reply_markup' => '{"inline_keyboard":[[{"text":"\\u2694\\ufe0f \\u0412\\u044b\\u0437\\u0432\\u0430\\u0442\\u044c: \\u0411\\u043e\\u0431","callback_data":"arenaDuel_2"},{"text":"\\u2694\\ufe0f \\u0412\\u044b\\u0437\\u0432\\u0430\\u0442\\u044c: \\u0415\\u0432\\u0430","callback_data":"arenaDuel_3"},{"text":"\\u2694\\ufe0f \\u041e\\u0442\\u043a\\u0440\\u044b\\u0442\\u044c\\u0441\\u044f \\u043a \\u0434\\u0443\\u044d\\u043b\\u044f\\u043c","callback_data":"duelsOpenOn"}],[{"text":"\\ud83c\\udfc6 \\u0420\\u0435\\u0439\\u0442\\u0438\\u043d\\u0433 PvP","callback_data":"pvpLadder"},{"text":"\\u25c0\\ufe0f \\u042f","callback_data":"character"}]]}',
        ],
        'arena_open' => [
            'method'       => 'editMessageText',
            'text'         => '🏟 *Арена — равные дуэли*

_Спортивный поединок на равных статах: ни здоровья, ни опыта не теряется. Решают билд и удача. Победы идут в 🏆 Рейтинг PvP._

⚔️ *Открытые бойцы:*
• Боб (ур.12 · 30 очк.)
• Ева (ур.5 · 20 очк.)

✅ Ты *открыт* для дуэлей — тебя могут вызвать. Закрыться можно в ⚙️ Настройках.',
            'reply_markup' => '{"inline_keyboard":[[{"text":"\\u2694\\ufe0f \\u0412\\u044b\\u0437\\u0432\\u0430\\u0442\\u044c: \\u0411\\u043e\\u0431","callback_data":"arenaDuel_2"},{"text":"\\u2694\\ufe0f \\u0412\\u044b\\u0437\\u0432\\u0430\\u0442\\u044c: \\u0415\\u0432\\u0430","callback_data":"arenaDuel_3"}],[{"text":"\\ud83c\\udfc6 \\u0420\\u0435\\u0439\\u0442\\u0438\\u043d\\u0433 PvP","callback_data":"pvpLadder"},{"text":"\\u25c0\\ufe0f \\u042f","callback_data":"character"}]]}',
        ],
        'arena_empty' => [
            'method'       => 'editMessageText',
            'text'         => '🏟 *Арена — равные дуэли*

_Спортивный поединок на равных статах: ни здоровья, ни опыта не теряется. Решают билд и удача. Победы идут в 🏆 Рейтинг PvP._

Пока *никто не открыт* для дуэлей.

✅ Ты *открыт* для дуэлей — тебя могут вызвать. Закрыться можно в ⚙️ Настройках.',
            'reply_markup' => '{"inline_keyboard":[[{"text":"\\ud83c\\udfc6 \\u0420\\u0435\\u0439\\u0442\\u0438\\u043d\\u0433 PvP","callback_data":"pvpLadder"},{"text":"\\u25c0\\ufe0f \\u042f","callback_data":"character"}]]}',
        ],
        'arena_disabled' => [
            'method'       => 'answerCallbackQuery',
            'text'         => 'Арена сейчас закрыта.',
            'reply_markup' => null,
        ],
        'ladder_global' => [
            'method'       => 'editMessageText',
            'text'         => '🏆 *Рейтинг PvP — 🌍 Глобальный*

🥇 *Боб* — 30 очк. _(дуэли 3, PvP 1)_
🥈 *Ева* — 20 очк. _(дуэли 0, PvP 2)_
🥉 *Сан* — 10 очк. _(дуэли 1, PvP 0)_

👤 *Ты:* #3 · 10 очк. (дуэли: 1, PvP: 0)

_Очки за победы: дуэль и летальное PvP. Рейтинг — престиж, без игровых наград._',
            'reply_markup' => '{"inline_keyboard":[[{"text":"\\ud83c\\udff3\\ufe0f \\u041c\\u043e\\u044f \\u0444\\u0440\\u0430\\u043a\\u0446\\u0438\\u044f","callback_data":"pvpLadder_faction_2"},{"text":"\\ud83c\\udfdf \\u0410\\u0440\\u0435\\u043d\\u0430","callback_data":"arena"},{"text":"\\u25c0\\ufe0f \\u042f","callback_data":"character"}]]}',
        ],
        'ladder_faction' => [
            'method'       => 'editMessageText',
            'text'         => '🏆 *Рейтинг PvP — Ржавые*

🥇 *Боб* — 30 очк. _(дуэли 3, PvP 1)_
🥈 *Сан* — 10 очк. _(дуэли 1, PvP 0)_

👤 *Ты:* #3 · 10 очк. (дуэли: 1, PvP: 0)

_Очки за победы: дуэль и летальное PvP. Рейтинг — престиж, без игровых наград._',
            'reply_markup' => '{"inline_keyboard":[[{"text":"\\ud83c\\udf0d \\u0413\\u043b\\u043e\\u0431\\u0430\\u043b\\u044c\\u043d\\u044b\\u0439","callback_data":"pvpLadder_global"},{"text":"\\ud83c\\udfdf \\u0410\\u0440\\u0435\\u043d\\u0430","callback_data":"arena"},{"text":"\\u25c0\\ufe0f \\u042f","callback_data":"character"}]]}',
        ],
        'ladder_unranked' => [
            'method'       => 'editMessageText',
            'text'         => '🏆 *Рейтинг PvP — 🌍 Глобальный*

🥇 *Боб* — 30 очк. _(дуэли 3, PvP 1)_
🥈 *Ева* — 20 очк. _(дуэли 0, PvP 2)_

👤 *Ты* ещё не в рейтинге — выиграй дуэль, чтобы попасть в таблицу.

_Очки за победы: дуэль и летальное PvP. Рейтинг — престиж, без игровых наград._',
            'reply_markup' => '{"inline_keyboard":[[{"text":"\\ud83c\\udff3\\ufe0f \\u041c\\u043e\\u044f \\u0444\\u0440\\u0430\\u043a\\u0446\\u0438\\u044f","callback_data":"pvpLadder_faction_2"},{"text":"\\ud83c\\udfdf \\u0410\\u0440\\u0435\\u043d\\u0430","callback_data":"arena"},{"text":"\\u25c0\\ufe0f \\u042f","callback_data":"character"}]]}',
        ],
        'ladder_disabled' => [
            'method'       => 'sendMessage',
            'text'         => '🏆 *Рейтинг PvP временно недоступен*

_Раздел отключён администрацией._',
            'reply_markup' => null,
        ],
        'duel_defender' => [
            'method'       => 'sendMessage',
            'text'         => 'Тебя вызвали на дуэль!

🤺 <b>Дуэль (равный бой)</b>
Сан ⚔️ Боб
🔁 Обменов ударами: 150

🏆 Победа по очкам: <b>Боб</b>
<i>Осталось больше здоровья.</i>

<i>Спортивный поединок: ни здоровья, ни опыта не потеряно. На равных статах решают билд и удача.</i>',
            'reply_markup' => null,
        ],
        'duel_attacker' => [
            'method'       => 'sendMessage',
            'text'         => '🤺 <b>Дуэль (равный бой)</b>
Сан ⚔️ Боб
🔁 Обменов ударами: 150

🏆 Победа по очкам: <b>Боб</b>
<i>Осталось больше здоровья.</i>

<i>Спортивный поединок: ни здоровья, ни опыта не потеряно. На равных статах решают билд и удача.</i>',
            'reply_markup' => '{"inline_keyboard":[[{"text":"\\u25c0\\ufe0f \\u042f","callback_data":"character"},{"text":"\\u041a\\u0430\\u0440\\u0442\\u0430","callback_data":"move"},{"text":"\\ud83c\\udfc6 \\u0420\\u0435\\u0439\\u0442\\u0438\\u043d\\u0433 PvP","callback_data":"pvpLadder"}]]}',
        ],
        'duel_self' => [
            'method'       => 'answerCallbackQuery',
            'text'         => 'Нельзя вызвать на дуэль самого себя.',
            'reply_markup' => null,
        ],
        'duel_not_open' => [
            'method'       => 'answerCallbackQuery',
            'text'         => 'Этот игрок не открыт для дуэлей. Открыться можно в ⚙️ Настройках.',
            'reply_markup' => null,
        ],
        'duel_missing' => [
            'method'       => 'answerCallbackQuery',
            'text'         => 'Соперник не найден.',
            'reply_markup' => null,
        ],
        'duel_far_field' => [
            'method'       => 'answerCallbackQuery',
            'text'         => 'Соперник слишком далеко — дуэль только в одной/соседней клетке.',
            'reply_markup' => null,
        ],
        'duel_disabled' => [
            'method'       => 'answerCallbackQuery',
            'text'         => 'Дуэли сейчас недоступны.',
            'reply_markup' => null,
        ],
    ];

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testArenaScreens(): void
    {
        $this->assertScreen('arena_closed', ArenaAction::class, 'arena', ['battles']);

        $this->conn->query('UPDATE characters SET duels_open = 1 WHERE id = 1');
        $this->assertScreen('arena_open', ArenaAction::class, 'arena', ['battles']);

        $this->conn->query('UPDATE characters SET duels_open = 0 WHERE id IN (2, 3)');
        $this->assertScreen('arena_empty', ArenaAction::class, 'arena', ['battles']);

        $this->setBool('pvp.duel.enabled', 0);
        $this->assertScreen('arena_disabled', ArenaAction::class, 'arena');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLadderScreens(): void
    {
        $this->assertScreen('ladder_global', PvpLadderAction::class, 'pvpLadder', ['battles']);
        $this->assertScreen('ladder_faction', PvpLadderAction::class, 'pvpLadder_faction_2', ['battles']);

        $this->conn->query('DELETE FROM pvp_ladder WHERE character_id = 1');
        $this->assertScreen('ladder_unranked', PvpLadderAction::class, 'pvpLadder_global', ['battles']);

        $this->setBool('pvp.ladder.enabled', 0);
        $this->assertScreen('ladder_disabled', PvpLadderAction::class, 'pvpLadder');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDuelResultAndRefusals(): void
    {
        mt_srand(self::SEED);
        $sent = $this->sent(DuelAction::class, 'arenaDuel_2');
        $msgs = array_values(array_filter($sent, static fn (array $c): bool => $c['method'] === 'sendMessage'));
        $this->assertCount(2, $msgs, 'защитник и вызвавший: ' . json_encode(array_column($sent, 'method')));
        $this->assertSame((string) self::TG_DEF, (string) ($msgs[0]['chat_id'] ?? ''));
        $this->assertSame((string) self::TG, (string) ($msgs[1]['chat_id'] ?? ''));
        $this->assertParity('duel_defender', $this->shape($msgs[0]), ['battleLog']);
        $this->assertParity('duel_attacker', $this->shape($msgs[1]), ['battleLog']);

        // Повтор сразу — анти-спам кулдаун, без второго боя.
        $again = $this->screen(DuelAction::class, 'arenaDuel_2');
        $this->assertSame('answerCallbackQuery', $again['method']);
        $this->assertIsString($again['text']);
        $this->assertMatchesRegularExpression('/^Подожди \d+ сек\. перед следующей дуэлью\.$/u', $again['text']);

        service('cache')->clean();
        $this->assertScreen('duel_self', DuelAction::class, 'arenaDuel_1');
        $this->assertScreen('duel_not_open', DuelAction::class, 'arenaDuel_4');
        $this->assertScreen('duel_missing', DuelAction::class, 'arenaDuel_999');
        $this->assertScreen('duel_far_field', DuelAction::class, 'duel_3');

        $this->setBool('pvp.duel.enabled', 0);
        $this->assertScreen('duel_disabled', DuelAction::class, 'arenaDuel_2');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSettingsToggleWritesDuelsOpen(): void
    {
        $this->sent(SettingsAction::class, 'duelsOpenOn');
        $this->assertSame(1, $this->duelsOpen(1));

        $this->sent(SettingsAction::class, 'duelsOpenOff');
        $this->assertSame(0, $this->duelsOpen(1));
    }

    /**
     * Хаб «⚙️ Ещё»: «📜 Мои бои» есть при любых флагах (журнал от них не зависит) и стоит сразу за
     * «🏟 Арена», когда она есть. Ряды мерятся на выходе `KeyboardNormalizer` — как их увидит игрок.
     */
    public function testMoreHubAlwaysCarriesBattlesWithoutLoneRows(): void
    {
        foreach ([false, true] as $arena) {
            foreach ([false, true] as $referral) {
                foreach ([false, true] as $tribute) {
                    foreach ([false, true] as $whatsnew) {
                        foreach ([[false, 5], [false, 12], [true, 12]] as [$chosen, $level]) {
                            $state = ['arena' => $arena, 'oracle' => false, 'referral' => $referral, 'tribute' => $tribute,
                                'whatsnew' => $whatsnew, 'factionChosen' => $chosen, 'factionProject' => false, 'level' => $level];
                            $label  = json_encode($state) ?: '';
                            $screen = (new class ($state) extends MoreSurfaceService {
                                /** @param array{arena:bool, oracle:bool, referral:bool, tribute:bool, whatsnew:bool, factionChosen:bool, factionProject:bool, level:int} $s */
                                public function __construct(private readonly array $s)
                                {
                                }

                                protected function gates(int $charId, int $level): array
                                {
                                    return $this->s;
                                }
                            })->buildScreen(['id' => 1, 'level' => $level]);

                            $this->assertStringContainsString('📜 *Мои бои*', $screen['text'], $label);
                            $out  = KeyboardNormalizer::normalize(['reply_markup' => json_encode($screen['keyboard'])]);
                            $rows = self::rows($out['reply_markup']);
                            $data = array_column(self::buttons($out['reply_markup']), 'callback_data');
                            $this->assertContains('battles', $data, $label);
                            if ($arena) {
                                $at = array_search('arena', $data, true);
                                $this->assertIsInt($at, $label);
                                $this->assertSame('battles', $data[$at + 1] ?? null, "рядом с Ареной: {$label}");
                            }
                            foreach ($rows as $i => $row) {
                                $this->assertGreaterThan(1, count($row), "одиночка в ряду {$i}: {$label}");
                            }
                        }
                    }
                }
            }
        }
    }

    // ── помощники ────────────────────────────────────────────────────────────

    private BaseConnection $conn;

    private function duelsOpen(int $id): int
    {
        return (int) ($this->conn->query('SELECT duels_open FROM characters WHERE id = ?', [$id])->getRowArray()['duels_open'] ?? -1);
    }

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
            // У battle_logs нет своей createTable-миграции: схема прода до спеки, затем расширение типа.
            $this->conn->query(
                'CREATE TABLE battle_logs (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, battle_type VARCHAR(3) NOT NULL,'
                . ' player1_id INT NULL, player2_id INT NULL, winner_id INT NULL, created_at DATETIME NOT NULL,'
                . ' finished_at DATETIME NOT NULL, log_data LONGTEXT NOT NULL)'
            );
            $this->conn->resetDataCache();
            require_once APPPATH . 'Database/Migrations/2026-12-17-100000_WidenBattleLogsType.php';
            (new WidenBattleLogsType($forge instanceof Forge ? $forge : null))->up();
            $this->seed();
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        foreach (self::ENV as $k => $v) {
            $this->envBackup[$k] = getenv($k);
            putenv("{$k}={$v}");
        }
        service('cache')->clean();
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            putenv($v === false ? $k : "{$k}={$v}");
        }
        service('cache')->clean();
        $this->dropTables();
        $this->conn->resetDataCache();
        parent::tearDown();
    }

    private function seed(): void
    {
        $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1)");
        $this->conn->query(
            'INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES'
            . ' (5, 5, 4, 0, 1), (6, 6, 5, 0, 1), (90, 90, 40, 40, 1)'
        );
        $this->conn->query(
            'INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, ?, ?), (8, ?, ?), (9, ?, ?)',
            [self::TG, 'Тест', self::TG_DEF, 'Боб', 771000033, 'Ева']
        );
        $chars = [
            // id, tg_user, name, level, cell, duels_open
            [1, 7, 'Сан', 10, 5, 0],
            [2, 8, 'Боб', 12, 6, 1],
            [3, 9, 'Ева', 5, 90, 1],
            [4, null, 'Дин', 40, 5, 0],
        ];
        foreach ($chars as [$id, $tg, $name, $level, $cell, $open]) {
            $this->conn->query(
                'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media, duels_open)'
                . ' VALUES (?, ?, ?, ?, 1.5, 90, 10, 0.5, 0.01, 0.01, 100, ?, 0, ?)',
                [$id, $tg, $name, $level, $cell, $open]
            );
        }
        $this->conn->query("INSERT INTO factions (id, name, description, type, unique_ability) VALUES (2, 'Ржавые', 'x', 'PVP', 'x')");
        $this->conn->query('INSERT INTO character_factions (character_id, faction_id) VALUES (1, 2), (2, 2)');
        $this->conn->query(
            'INSERT INTO pvp_ladder (character_id, faction_id, duel_wins, pvp_wins, points) VALUES'
            . ' (2, 2, 3, 1, 30), (1, 2, 1, 0, 10), (3, NULL, 0, 2, 20)'
        );

        $now  = date('Y-m-d H:i:s');
        $base = [
            'category' => 'combat', 'rationale_text' => 't', 'effect_text' => 't', 'above_effect_text' => 't',
            'below_effect_text' => 't', 'created_at' => $now, 'updated_at' => $now,
        ];
        foreach (['pvp.duel.enabled' => 1, 'pvp.ladder.enabled' => 1, 'onboarding.contextual_hints.enabled' => 0] as $key => $v) {
            $this->conn->table('game_settings')->insert($base + [
                'setting_key' => $key, 'value_type' => 'bool', 'value_bool' => $v, 'default_value_text' => (string) $v,
            ]);
        }
        $this->conn->table('game_settings')->insert($base + [
            'setting_key' => 'pvp.attack_cooldown_sec', 'value_type' => 'int', 'value_int' => 30, 'default_value_text' => '30',
        ]);
    }

    private function setBool(string $key, int $value): void
    {
        $this->conn->query('UPDATE game_settings SET value_bool = ? WHERE setting_key = ?', [$value, $key]);
        service('cache')->clean();
    }

    /**
     * @param list<string> $added префиксы callback_data кнопок, которых в снимке «до» нет
     */
    private function assertScreen(string $name, string $actionClass, string $data, array $added = []): void
    {
        $this->assertParity($name, $this->screen($actionClass, $data), $added);
    }

    /**
     * @param array{method: string, text: mixed, reply_markup: mixed} $actual
     * @param list<string>                                           $added
     */
    private function assertParity(string $name, array $actual, array $added): void
    {
        $dump = getenv('ARENA_PARITY_DUMP');
        if (is_string($dump) && $dump !== '') {
            file_put_contents($dump, var_export([$name => $actual], true) . ",\n", FILE_APPEND);
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertArrayHasKey($name, self::SNAPSHOTS, "нет снимка {$name}");
        $snap = self::SNAPSHOTS[$name];
        $this->assertSame($snap['method'], $actual['method'], $name);
        $this->assertSame($snap['text'], $actual['text'], $name);

        $got      = self::buttons($actual['reply_markup']);
        $isAdded  = static function (array $b) use ($added): bool {
            foreach ($added as $prefix) {
                if ($b['callback_data'] === $prefix || str_starts_with($b['callback_data'], $prefix . '_')) {
                    return true;
                }
            }

            return false;
        };
        $kept     = array_values(array_filter($got, static fn (array $b): bool => ! $isAdded($b)));
        $new      = array_values(array_filter($got, $isAdded));
        $this->assertSame(self::buttons($snap['reply_markup']), $kept, "{$name}: кнопки снимка");
        $this->assertCount(count($added), $new, "{$name}: добавленные кнопки");

        // Ноль одиночек: ряд из одной кнопки допустим только у клавиатуры из одной кнопки.
        $rows = self::rows($actual['reply_markup']);
        if (count($got) > 1) {
            foreach ($rows as $i => $row) {
                $this->assertGreaterThan(1, count($row), "{$name}: одиночка в ряду {$i}");
            }
        }
    }

    /**
     * @return list<array{text: string, callback_data: string}>
     */
    private static function buttons(mixed $markup): array
    {
        $out = [];
        foreach (self::rows($markup) as $row) {
            foreach ($row as $b) {
                $out[] = $b;
            }
        }

        return $out;
    }

    /**
     * @return list<list<array{text: string, callback_data: string}>>
     */
    private static function rows(mixed $markup): array
    {
        $decoded = is_string($markup) ? json_decode($markup, true) : null;
        $rows    = is_array($decoded) && is_array($decoded['inline_keyboard'] ?? null) ? $decoded['inline_keyboard'] : [];
        $out     = [];
        foreach ($rows as $row) {
            $r = [];
            foreach (is_array($row) ? $row : [] as $b) {
                $r[] = ['text' => (string) ($b['text'] ?? ''), 'callback_data' => (string) ($b['callback_data'] ?? '')];
            }
            $out[] = $r;
        }

        return $out;
    }

    /** @return array{method: string, text: mixed, reply_markup: mixed} */
    private function screen(string $actionClass, string $data): array
    {
        $sent  = $this->sent($actionClass, $data);
        $calls = array_values(array_filter($sent, static fn (array $c): bool => in_array($c['method'], ['sendMessage', 'editMessageText'], true)));
        $alert = array_values(array_filter($sent, static fn (array $c): bool => $c['method'] === 'answerCallbackQuery' && isset($c['text'])));
        if ($calls === [] && $alert !== []) {
            return ['method' => 'answerCallbackQuery', 'text' => $alert[0]['text'], 'reply_markup' => null];
        }
        $this->assertCount(1, $calls, 'screen calls in ' . json_encode(array_column($sent, 'method')));

        return $this->shape($calls[0]);
    }

    /**
     * @param array<string, mixed> $call
     *
     * @return array{method: string, text: mixed, reply_markup: mixed}
     */
    private function shape(array $call): array
    {
        return ['method' => (string) $call['method'], 'text' => $call['text'] ?? null, 'reply_markup' => $call['reply_markup'] ?? null];
    }

    /** @return list<array<string, mixed>> */
    private function sent(string $actionClass, string $data): array
    {
        $sent = [];
        // Мост поднят заранее, как у живого бота: ядро арены зовёт `TelegramBridge::ensure()` само, и
        // повторный вызов не должен заменить перехватчик клиентом зонда.
        $this->assertTrue(TelegramBridge::ensure());
        $client = new Client(['handler' => static function (RequestInterface $request) use (&$sent): PromiseInterface {
            parse_str((string) $request->getBody(), $params);
            $path   = explode('/', $request->getUri()->getPath());
            $sent[] = ['method' => (string) end($path)] + $params;

            return Create::promiseFor(new Response(200, [], '{"ok":true,"result":true}'));
        }]);
        (new \ReflectionProperty(TelegramDeliveryProbe::class, 'client'))->setValue(null, $client);
        LongmanRequest::setClient($client);

        $cbq = new CallbackQuery([
            'id'      => 'cbq-1',
            'from'    => ['id' => self::TG, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => ['message_id' => 1, 'date' => time(), 'chat' => ['id' => self::TG, 'type' => 'private'], 'text' => 'x'],
            'chat_instance' => 'ci', 'data' => $data,
        ]);
        $action = new $actionClass($cbq);
        $this->assertTrue(method_exists($action, 'handle'));
        $action->handle();

        return $sent;
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

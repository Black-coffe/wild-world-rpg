<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\Adr164SeedFieldPvpTip;
use App\Database\Migrations\Adr186FixFieldPvpTip;
use App\Database\Migrations\CreateCharactersTable;
use App\Database\Migrations\CreateGameTipsTable;
use App\Database\Migrations\CreateTelegramUsersTable;
use App\Database\Migrations\TipsAExtendCategories;
use App\Services\Onboarding\GuideCatalog;
use App\Services\PVE\StandoffNotifier;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * pvp-detection-clarity-05 — четыре текста про PvP перестают врать.
 *
 * Честность формулировки тестом не проверяется — это выносят Редколлегия и Tier-3 (brief.md
 * стенограмма). Здесь только то, что машина реально может проверить: раздел `settings` больше
 * не несёт запрещённую формулировку «тумблер = полевой PvP», и миграция `Adr186FixFieldPvpTip`
 * идемпотентно переписывает `content` совета `FieldPvpAndArena` ровно по одной строке, без
 * дублей при повторном прогоне.
 *
 * Схема (`game_tips`) строится прогоном настоящих классов миграций
 * (`Adr164SeedFieldPvpTip` — реальный prod-сид, старую миграцию тест не трогает, только
 * запускает её `up()` как фикстуру) — только если таблицы ещё нет; CI гоняет набор на пустой
 * базе, локальный стенд её обычно уже несёт персистентно.
 *
 * @internal
 */
final class PvpTextsHonestyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private \CodeIgniter\Database\BaseConnection $conn;

    private bool $createdGameTips = false;
    private bool $seededOriginalTip = false;

    private bool $createdTelegramUsers = false;
    private bool $createdCharacters    = false;

    /** @var list<int> */
    private array $characterIds = [];
    /** @var list<int> */
    private array $telegramUserIds = [];
    /** @var array<int,int> character_id => telegram_id, чтобы проверить адресата */
    private array $telegramIdByCharacter = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');
        $this->conn->resetDataCache();

        if (! $this->conn->tableExists('game_tips')) {
            $this->requireMigration('CreateGameTipsTable', '2024-04-22-133908_CreateGameTipsTable.php');
            $forge = Database::forge('tests');
            (new CreateGameTipsTable($forge instanceof Forge ? $forge : null))->up();
            $this->conn->resetDataCache();

            $this->requireMigration('TipsAExtendCategories', '2026-05-22-300000_TipsAExtendCategories.php');
            $forge = Database::forge('tests');
            (new TipsAExtendCategories($forge instanceof Forge ? $forge : null))->up();
            $this->conn->resetDataCache();

            $this->createdGameTips = true;
        }

        $existing = $this->conn->table('game_tips')->where('title_en', 'FieldPvpAndArena')->get()->getRowArray();
        if (empty($existing)) {
            $this->requireMigration('Adr164SeedFieldPvpTip', '2026-11-07-110000_Adr164SeedFieldPvpTip.php');
            $forge = Database::forge('tests');
            (new Adr164SeedFieldPvpTip($forge instanceof Forge ? $forge : null))->up();
            $this->seededOriginalTip = true;
        }

        // pvp-detection-clarity-16 — StandoffNotifier читает characters/telegram_users
        // (chatIdFor/nameFor), те же таблицы, что StandoffExpiryHandlerTest.
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
    }

    protected function tearDown(): void
    {
        foreach ($this->characterIds as $id) {
            $this->conn->table('characters')->where('id', $id)->delete();
        }
        foreach ($this->telegramUserIds as $id) {
            $this->conn->table('telegram_users')->where('id', $id)->delete();
        }

        if ($this->seededOriginalTip) {
            $this->conn->table('game_tips')->where('title_en', 'FieldPvpAndArena')->delete();
        }

        if ($this->createdGameTips) {
            $this->requireMigration('TipsAExtendCategories', '2026-05-22-300000_TipsAExtendCategories.php');
            $forge = Database::forge('tests');
            (new TipsAExtendCategories($forge instanceof Forge ? $forge : null))->down();

            $this->requireMigration('CreateGameTipsTable', '2024-04-22-133908_CreateGameTipsTable.php');
            $forge = Database::forge('tests');
            (new CreateGameTipsTable($forge instanceof Forge ? $forge : null))->down();
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

        parent::tearDown();
    }

    private function requireMigration(string $shortClass, string $file): void
    {
        $class = 'App\\Database\\Migrations\\' . $shortClass;
        if (! class_exists($class, false)) {
            require_once APPPATH . 'Database/Migrations/' . $file;
        }
    }

    // ── GuideCatalog: раздел settings больше не врёт ────────────────────────

    public function testSettingsSectionDoesNotClaimToggleGatesFieldPvp(): void
    {
        $sections = GuideCatalog::sections();
        $settings = null;
        foreach ($sections as $section) {
            if ($section['key'] === 'settings') {
                $settings = $section;
                break;
            }
        }

        $this->assertNotNull($settings, 'Раздел settings обязан существовать в каталоге.');
        $this->assertStringNotContainsString(
            'пускать ли тебя на арену/в полевой PvP',
            $settings['body'],
            'Раздел settings всё ещё утверждает, что тумблер пускает/не пускает в полевой PvP.'
        );
        $this->assertStringContainsString(
            'Полевой бой на карте он не выключает',
            $settings['body'],
            'Раздел settings обязан прямо сказать, что тумблер не выключает полевой бой.'
        );
    }

    public function testArenaSectionMentionsLockBeforeAttack(): void
    {
        $sections = GuideCatalog::sections();
        $arena = null;
        foreach ($sections as $section) {
            if ($section['key'] === 'arena') {
                $arena = $section;
                break;
            }
        }

        $this->assertNotNull($arena, 'Раздел arena обязан существовать в каталоге.');
        $this->assertStringContainsString(
            'замок с причиной',
            $arena['body'],
            'Раздел arena обязан объяснять, что часть соседей закрыта для атаки замком, видимым до тапа.'
        );
        $this->assertStringContainsString(
            'Полевой бой тумблером не отключается',
            $arena['body'],
            'Раздел arena обязан оставаться честным про то, что тумблер полевой бой не отключает.'
        );
    }

    // ── Миграция: content переписывается идемпотентно ───────────────────────

    public function testMigrationRewritesTipContentAndDropsToggleClaim(): void
    {
        $before = $this->conn->table('game_tips')->where('title_en', 'FieldPvpAndArena')->get()->getRowArray();
        $this->assertIsArray($before);
        $this->assertStringContainsString('держи тумблер', $before['content']);

        $this->requireMigration('Adr186FixFieldPvpTip', '2026-09-11-230000_Adr186FixFieldPvpTip.php');
        $forge = Database::forge('tests');
        (new Adr186FixFieldPvpTip($forge instanceof Forge ? $forge : null))->up();

        $rows = $this->conn->table('game_tips')->where('title_en', 'FieldPvpAndArena')->get()->getResultArray();
        $this->assertCount(1, $rows, 'Миграция обязана оставить ровно одну строку по title_en.');

        $after = $rows[0];
        $this->assertStringNotContainsString(
            'Не хочешь драк — держи тумблер',
            $after['content'],
            'Ложная фраза про тумблер как способ не драться обязана уйти.'
        );
        $this->assertStringContainsString(
            'столкнёшься с другим выжившим на карте',
            $after['content'],
            'Честный абзац про столкновение на карте без согласия обязан остаться.'
        );
        $this->assertSame('бой', $after['tip_type'], 'Категория совета не должна меняться.');
        $this->assertSame(0, substr_count($after['content'], '*') % 2, 'Markdown «*» в новом content обязан быть парным.');
    }

    public function testMigrationIsIdempotentOnRepeatedRun(): void
    {
        $this->requireMigration('Adr186FixFieldPvpTip', '2026-09-11-230000_Adr186FixFieldPvpTip.php');
        $forge = Database::forge('tests');
        (new Adr186FixFieldPvpTip($forge instanceof Forge ? $forge : null))->up();
        $firstRun = $this->conn->table('game_tips')->where('title_en', 'FieldPvpAndArena')->get()->getResultArray();
        $this->assertCount(1, $firstRun);
        $contentAfterFirst = $firstRun[0]['content'];

        // Повторный прогон не плодит дублей и не меняет содержимое ещё раз.
        $forge = Database::forge('tests');
        (new Adr186FixFieldPvpTip($forge instanceof Forge ? $forge : null))->up();
        $secondRun = $this->conn->table('game_tips')->where('title_en', 'FieldPvpAndArena')->get()->getResultArray();

        $this->assertCount(1, $secondRun, 'Повторный прогон миграции не должен плодить строки.');
        $this->assertSame($contentAfterFirst, $secondRun[0]['content']);
    }

    // ── StandoffNotifier: тексты не обещают того, чего код не делает (-16) ──

    /**
     * BLOCK major #4: «Укрыться» больше не обещает «сразу принять бой» —
     * `StandoffHoldAction` только переводит статус и размораживает нападавшего,
     * бой сам собой не начинается (второй тап по «⚔️ Атаковать» обязателен).
     */
    public function testAlertTextDoesNotPromiseImmediateCombatOnHold(): void
    {
        $defender = $this->insertCharacter();
        $attacker = $this->insertCharacter();

        $notifier = $this->capturingNotifier();
        $notifier->alertDefender([
            'id'          => 1,
            'defender_id' => $defender,
            'attacker_id' => $attacker,
            'expires_at'  => date('Y-m-d H:i:s', time() + 120),
        ]);

        $this->assertCount(1, $notifier->calls);
        $text = $notifier->calls[0]['text'];

        $this->assertStringNotContainsString(
            'сразу принять бой',
            $text,
            'Укрыться не начинает бой сразу — StandoffHoldAction только размораживает нападавшего.'
        );
        $this->assertStringContainsString(
            'разморозить',
            $text,
            'Текст обязан честно сказать, что «Укрыться» размораживает нападавшего, а не запускает бой.'
        );
    }

    /**
     * BLOCK major #10: надбавка достаётся только тому нападавшему, от которого
     * защитник укрылся, и только пока свежо (`cooldown_sec`) — текст не должен
     * обещать её безусловно, любому и навсегда.
     */
    public function testAlertTextScopesDefenseBonusToThatAttackerAndNotForever(): void
    {
        $defender = $this->insertCharacter();
        $attacker = $this->insertCharacter();

        $notifier = $this->capturingNotifier();
        $notifier->alertDefender([
            'id'          => 2,
            'defender_id' => $defender,
            'attacker_id' => $attacker,
            'expires_at'  => date('Y-m-d H:i:s', time() + 120),
        ]);

        $text = $notifier->calls[0]['text'];

        $this->assertStringContainsString(
            'против него',
            $text,
            'Надбавка должна быть явно привязана к конкретному нападавшему, а не обещана вообще.'
        );
        $this->assertStringNotContainsString(
            'с добавкой к защите',
            $text,
            'Старая безусловная формулировка надбавки не должна вернуться.'
        );
    }

    /**
     * BLOCK major #9: пинг об истечении не хардкодит «Пять минут» — окно
     * admin-tunable (`pvp.standoff.window_sec`, hard 30–1800), число могут открутить.
     */
    public function testExpiredPingDoesNotNameHardcodedWindowDuration(): void
    {
        $defender = $this->insertCharacter();
        $attacker = $this->insertCharacter();

        $notifier = $this->capturingNotifier();
        $notifier->notifyAttackerExpired([
            'attacker_id' => $attacker,
            'defender_id' => $defender,
        ]);

        $this->assertCount(1, $notifier->calls);
        $text = $notifier->calls[0]['text'];

        $this->assertStringNotContainsString(
            'Пять минут',
            $text,
            'Пинг не должен называть хардкоженную длительность окна — она admin-tunable.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\d+\s*(секунд|минут)/u',
            $text,
            'Пинг не должен называть числовую длительность окна словами вообще.'
        );
        $this->assertStringContainsString(
            'Окно закрылось',
            $text,
            'Пинг обязан честно сообщить, что окно закрылось, не называя его длительность.'
        );
    }

    /**
     * @return object{calls: list<array{chat_id:int,text:string}>}&StandoffNotifier
     */
    private function capturingNotifier(): object
    {
        return new class () extends StandoffNotifier {
            /** @var list<array{chat_id:int,text:string}> */
            public array $calls = [];

            protected function sendDefenderAlert(int $chatId, string $text, array $keyboard): bool
            {
                $this->calls[] = ['chat_id' => $chatId, 'text' => $text];
                return true;
            }

            protected function sendExpiredPing(int $chatId, string $text): bool
            {
                $this->calls[] = ['chat_id' => $chatId, 'text' => $text];
                return true;
            }
        };
    }

    private function insertCharacter(): int
    {
        $telegramId = random_int(100_000_000, 999_999_999);
        $this->conn->table('telegram_users')->insert([
            'telegram_id' => $telegramId,
        ]);
        $telegramUserId          = (int) $this->conn->insertID();
        $this->telegramUserIds[] = $telegramUserId;

        $this->conn->table('characters')->insert([
            'name'             => 'T' . random_int(100000, 999999),
            'level'            => 10,
            'cell_number'      => '1',
            'telegram_user_id' => $telegramUserId,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        $id                                = (int) $this->conn->insertID();
        $this->characterIds[]              = $id;
        $this->telegramIdByCharacter[$id]  = $telegramId;
        return $id;
    }
}

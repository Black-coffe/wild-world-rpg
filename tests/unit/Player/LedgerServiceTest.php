<?php

declare(strict_types=1);

namespace Tests\Unit\Player;

use App\Services\Player\LedgerService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\CallbackRoutes;

/**
 * chat-requests-batch-06 — экран «🧾 Куда ушло» (Ivan Divan «лога движения средств тоже
 * нету и не понятно нихера»; Max Syskov «У меня исчезло 50% ресурсов…»).
 *
 * Локальная `wildworld_tests` разреженная (`reference_local_db_bootstrap_from_testbot`):
 * `action_log` / `event_effects_log` / `events` тут не migrated. setUp/tearDown создают их
 * САМИ под `tableExists()`-гейтом (без FK на `characters` — она в этой базе тоже
 * отсутствует и запросам `LedgerService::entries()` не нужна) и дропают только то, что
 * создали. `resetDataCache()` — иначе builder держит закэшированный список таблиц с
 * прошлого DDL соседнего теста и врёт про существование только что созданной.
 *
 * @internal
 */
final class LedgerServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private bool $createdActionLog       = false;
    private bool $createdEventEffectsLog = false;
    private bool $createdEvents          = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->resetDataCache();

        if (! $this->db->tableExists('action_log')) {
            $this->db->query(
                'CREATE TABLE action_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    character_id INT UNSIGNED NOT NULL,
                    chat_id BIGINT UNSIGNED NOT NULL,
                    action_name VARCHAR(255),
                    action_status VARCHAR(20),
                    description TEXT,
                    created_at DATETIME,
                    updated_at DATETIME
                )'
            );
            $this->createdActionLog = true;
        }
        if (! $this->db->tableExists('events')) {
            $this->db->query(
                'CREATE TABLE events (event_id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255))'
            );
            $this->createdEvents = true;
        }
        if (! $this->db->tableExists('event_effects_log')) {
            $this->db->query(
                'CREATE TABLE event_effects_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    character_id INT UNSIGNED NOT NULL,
                    event_id INT UNSIGNED NOT NULL,
                    cell_number INT UNSIGNED NULL,
                    biome_id INT UNSIGNED NULL,
                    effect_details TEXT NOT NULL,
                    event_time DATETIME NOT NULL,
                    additional_info TEXT NULL
                )'
            );
            $this->createdEventEffectsLog = true;
        }

        // Второй resetDataCache(): `tableExists()` (cached=true) кэширует список
        // таблиц ПРИ ПЕРВОМ вызове — а он уже был выше, в проверках `! tableExists(...)`
        // ДО CREATE TABLE. Без сброса `LedgerService::entries()` читает тот же стухший
        // список и врёт «таблицы нет», хотя CREATE TABLE только что отработал.
        $this->db->resetDataCache();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->createdEventEffectsLog) {
            $this->db->query('DROP TABLE IF EXISTS event_effects_log');
        }
        if ($this->createdEvents) {
            $this->db->query('DROP TABLE IF EXISTS events');
        }
        if ($this->createdActionLog) {
            $this->db->query('DROP TABLE IF EXISTS action_log');
        }
    }

    private function insertAction(int $characterId, string $actionName, string $description, string $createdAt): void
    {
        $this->hasInDatabase('action_log', [
            'character_id'  => $characterId,
            'chat_id'       => 111,
            'action_name'   => $actionName,
            'action_status' => 'Completed',
            'description'   => $description,
            'created_at'    => $createdAt,
            'updated_at'    => $createdAt,
        ]);
    }

    private function insertEvent(int $characterId, string $eventName, array $effectDetails, string $eventTime): void
    {
        $this->hasInDatabase('events', ['name' => $eventName]);
        $eventId = (int) $this->db->insertID();

        $this->hasInDatabase('event_effects_log', [
            'character_id'    => $characterId,
            'event_id'        => $eventId,
            'effect_details'  => json_encode($effectDetails, JSON_UNESCAPED_UNICODE),
            'event_time'      => $eventTime,
        ]);
    }

    // ── entries(): merge + scope + depth ──────────────────────────────────

    public function testEntriesShowOnlyOwnCharacterAndIgnoreNonEconomyNoise(): void
    {
        $this->insertAction(501, 'TAX_BUILDINGS', 'Налог за 2 зданий: -400 золота', '2026-08-20 10:00:00');
        // Чужой персонаж — не должен утечь в ленту 501.
        $this->insertAction(999, 'TAX_BUILDINGS', 'Налог за 5 зданий: -900 золота', '2026-08-20 11:00:00');
        // Не экономика (онбординг-маркер) — не относится к «куда ушло».
        $this->insertAction(501, 'onboarding_hint_seen', 'служебная метка', '2026-08-20 12:00:00');

        $entries = (new LedgerService(null, null, $this->db))->entries(501);

        $this->assertCount(1, $entries);
        $this->assertStringContainsString('Налог за 2 зданий', $entries[0]['text']);
    }

    public function testEntriesMergeActionLogAndEventEffectsLogSortedNewestFirst(): void
    {
        $this->insertAction(501, 'DEATH_RESPAWN', 'Смерть персонажа — возрождение на клетке #42', '2026-08-20 09:00:00');
        // Событие обязано МЕНЯТЬ золото/ресурсы, чтобы попасть в ленту — HP-only
        // событие сюда не годится (см. testEntriesExcludeEventsWithoutGoldOrResourceImpact).
        $this->insertEvent(501, 'Ураган', ['gold_delta' => -40, 'log_summary' => '-40 золота унесло ветром'], '2026-08-20 10:00:00');
        $this->insertAction(501, 'TAX_BEACONS', 'Налог за 3 маяков: -150 золота', '2026-08-20 11:00:00');

        $entries = (new LedgerService(null, null, $this->db))->entries(501);

        $this->assertCount(3, $entries);
        // Свежее сверху.
        $this->assertStringContainsString('Налог за 3 маяков', $entries[0]['text']);
        $this->assertStringContainsString('Ураган', $entries[1]['text']);
        $this->assertStringContainsString('возрождение на клетке #42', $entries[2]['text']);
    }

    /**
     * Ревью-находка №3: `EventDispatcher::logToDb()` пишет КАЖДЫЙ применённый эффект,
     * включая чисто-HP и атрибутные — без фильтра пятнадцать событий с уроном по
     * здоровью подряд вытеснили бы из ленты налог и продажи. В ленту ПРО ЗАПАС
     * попадают только эффекты, реально менявшие золото или ресурсы.
     */
    public function testEntriesExcludeEventsWithoutGoldOrResourceImpact(): void
    {
        $this->insertAction(501, 'TAX_BUILDINGS', 'Налог за 1 зданий: -100 золота', '2026-08-20 09:00:00');
        // Чисто-HP эффект — gold_delta=0, magnitude пуст (нет resource_loss_percent).
        $this->insertEvent(501, 'Метеоритный дождь', ['gold_delta' => 0, 'log_summary' => '-15 HP', 'magnitude' => []], '2026-08-20 10:00:00');
        // Атрибутный эффект — тоже без денег/ресурсов.
        $this->insertEvent(501, 'Прилив сил', ['gold_delta' => 0, 'log_summary' => '+0.05 к ловкости'], '2026-08-20 11:00:00');

        $entries = (new LedgerService(null, null, $this->db))->entries(501);

        $this->assertCount(1, $entries, 'HP/атрибутные события не должны попадать в ленту про запас');
        $this->assertStringContainsString('Налог', $entries[0]['text']);
    }

    /** `magnitude.resource_loss_percent` (DamageResourcesEffect) — второй легитимный повод показать событие. */
    public function testEntriesIncludeEventsWithResourceLossPercentEvenWithZeroGoldDelta(): void
    {
        $this->insertEvent(501, 'Кислотный дождь', [
            'gold_delta'   => 0,
            'log_summary'  => 'Ресурсы: -12%',
            'magnitude'    => ['resource_loss_percent' => 12.0, 'effect_kind' => 'damage_resources'],
        ], '2026-08-20 10:00:00');

        $entries = (new LedgerService(null, null, $this->db))->entries(501);

        $this->assertCount(1, $entries);
        $this->assertStringContainsString('Кислотный дождь', $entries[0]['text']);
    }

    /**
     * Ревью-находка: `SELL_GEAR`/`ORACLE_BET` пишут ТОЙ ЖЕ строкой `action_name` и
     * удачную сделку (Completed), и отклонённую попытку (REJECTED, `logRejected()`).
     * Без фильтра по `action_status` неудавшаяся попытка выглядела бы как реальная потеря.
     */
    public function testEntriesExcludeRejectedActionStatus(): void
    {
        $this->hasInDatabase('action_log', [
            'character_id'  => 501,
            'chat_id'       => 111,
            'action_name'   => 'SELL_GEAR',
            'action_status' => 'REJECTED',
            'description'   => 'item_not_sellable',
            'created_at'    => '2026-08-20 10:00:00',
            'updated_at'    => '2026-08-20 10:00:00',
        ]);
        $this->insertAction(501, 'SELL_GEAR', 'Продано торговцу: Топор x1 за 300 золота', '2026-08-20 11:00:00');

        $entries = (new LedgerService(null, null, $this->db))->entries(501);

        $this->assertCount(1, $entries, 'REJECTED-попытка не должна выглядеть реальной потерей');
        $this->assertStringContainsString('Продано торговцу', $entries[0]['text']);
    }

    /**
     * Ревью-находка №1 (blocker): whitelist покрывал 5 из 7 категорий Goal. Снос
     * здания за неуплату налога — «самая болезненная потеря», добавлен явно.
     */
    public function testTaxBuildingDestroyedIsCovered(): void
    {
        $this->insertAction(501, 'TAX_BUILDING_DESTROYED', 'Здание (ID=7) снесено за неуплату налога (2-й недобор подряд)', '2026-08-20 10:00:00');

        $entries = (new LedgerService(null, null, $this->db))->entries(501);

        $this->assertCount(1, $entries);
        $this->assertStringContainsString('снесено за неуплату', $entries[0]['text']);
    }

    /** Ставка у Оракула (крупнейший добровольный слив золота, по слову ревьюера) тоже видна. */
    public function testOracleBetIsCovered(): void
    {
        $this->insertAction(501, 'ORACLE_BET', 'market=3 outcome=up stake=500', '2026-08-20 10:00:00');

        $entries = (new LedgerService(null, null, $this->db))->entries(501);

        $this->assertCount(1, $entries);
        $this->assertStringContainsString('Ставка у Оракула', $entries[0]['text']);
    }

    public function testDepthCapsEntryCountKeepingNewest(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->insertAction(501, 'BUY_RESOURCE', "res=1 qty={$i} gold=-{$i}", "2026-08-20 10:0{$i}:00");
        }

        $ledger  = new LedgerService(null, ['economy.ledger.depth' => 2], $this->db);
        $entries = $ledger->entries(501);

        $this->assertCount(2, $entries);
        $this->assertStringContainsString('qty=5', $entries[0]['text'], 'самая свежая запись обязана остаться');
        $this->assertStringContainsString('qty=4', $entries[1]['text']);
    }

    public function testDeathLossDescriptionIsShownVerbatimNotReparsed(): void
    {
        // Story 11 формат — состав потерь текстом; LedgerService не имеет права его
        // разбирать на части, только показать как есть (Notes истории 06).
        $this->insertAction(501, 'DEATH_LOSS', 'Смерть персонажа: -1200 золота; ресурсы: Дерево ×5, Вода ×3', '2026-08-20 10:00:00');

        $entries = (new LedgerService(null, null, $this->db))->entries(501);

        $this->assertSame(
            '💀 Смерть персонажа: -1200 золота; ресурсы: Дерево ×5, Вода ×3',
            $entries[0]['text']
        );
    }

    // ── depth() ────────────────────────────────────────────────────────────

    public function testDepthOverrideBypassesGameSettings(): void
    {
        $ledger = new LedgerService(null, ['economy.ledger.depth' => 7]);

        $this->assertSame(7, $ledger->depth());
    }

    public function testDepthFallsBackToSaneDefaultOnGarbageOverride(): void
    {
        $ledger = new LedgerService(null, ['economy.ledger.depth' => 'not-a-number']);

        $this->assertGreaterThan(0, $ledger->depth());
    }

    // ── renderScreen(): pure, no DB ────────────────────────────────────────

    public function testEmptyLedgerExplainsItselfInsteadOfDeadEnd(): void
    {
        $out = LedgerService::renderScreen([], 15);

        $this->assertStringContainsString('золото или ресурсы', $out['text']);
        $this->assertStringNotContainsString('Fatal', $out['text']);
        // Пустая лента не тупик — кнопки на выход всё равно есть.
        $this->assertNotEmpty($out['buttons']);
    }

    /**
     * Ревью-находка №2: экран не имеет права утверждать факты о мире шире своей
     * области видимости. Старый текст перечислял категории («не было налога,
     * торговли…») как будто это исчерпывающий список — новый обобщён и не
     * перечисляет конкретные категории вовсе (не устареет при следующем добавлении
     * кода в whitelist).
     */
    public function testEmptyLedgerDoesNotClaimSpecificCategoriesDidNotHappen(): void
    {
        $out = LedgerService::renderScreen([], 15);

        foreach (['налога', 'торговли', 'мирового', 'смерти'] as $category) {
            $this->assertStringNotContainsString($category, $out['text'], "текст не должен перечислять категорию «{$category}» как исчерпывающий факт");
        }
    }

    /**
     * Ревью-находка: `tableExists()` в проде превращает пропавшую таблицу (тихий
     * сбой) в «движений не было» (уверенный факт) — разные вещи, разный текст.
     */
    public function testRenderScreenIsHonestWhenSourcesIncomplete(): void
    {
        $out = LedgerService::renderScreen([], 15, false);

        $this->assertStringContainsString('технический сбой', $out['text']);
        // Явная негация «НЕ подтверждение того, что не менялся» — не голое
        // утверждение факта «не менялся», как было бы у обычного пустого состояния.
        $this->assertStringContainsString('не подтверждение', $out['text']);
        $this->assertStringNotContainsString('Пока ни одна отслеженная запись', $out['text'], 'ветка «пусто, но честно» не должна путаться с обычной пустой лентой');
    }

    public function testSourcesCompleteTrueWhenBothTablesPresent(): void
    {
        $this->insertAction(501, 'TAX_BUILDINGS', 'Налог за 1 зданий: -100 золота', '2026-08-20 10:00:00');

        $ledger = new LedgerService(null, null, $this->db);
        $ledger->entries(501);

        $this->assertTrue($ledger->sourcesComplete());
    }

    /**
     * Симулируем пропавшую таблицу (не тестовая специфика — реальный сценарий, на
     * который указал ревьюер): `entries()` не имеет права выдавать пустой список,
     * маскируя сбой чтения под честное «ничего не произошло».
     */
    public function testSourcesCompleteFalseWhenEventEffectsLogTableMissing(): void
    {
        $this->db->resetDataCache();
        $this->db->query('DROP TABLE IF EXISTS event_effects_log');
        $this->db->resetDataCache();

        $this->insertAction(501, 'TAX_BUILDINGS', 'Налог за 1 зданий: -100 золота', '2026-08-20 10:00:00');

        $ledger  = new LedgerService(null, null, $this->db);
        $entries = $ledger->entries(501);

        $this->assertFalse($ledger->sourcesComplete());
        $this->assertNotEmpty($entries, 'action_log всё ещё читается — недоступен только один источник из двух');
        // Таблицу заново создавать не нужно: следующий тест сам поднимет её в своём
        // setUp() (тот же tableExists()-гейт, что и здесь) — DatabaseTestTrait
        // вызывает setUp()/tearDown() на каждый тест-метод отдельно.
    }

    public function testNoRowHasExactlyOneButton(): void
    {
        foreach ([[], [['time' => '2026-08-20 10:00:00', 'text' => '🏛️ Налог: тест']]] as $entries) {
            $out = LedgerService::renderScreen($entries, 15);
            foreach ($out['buttons'] as $row) {
                $this->assertGreaterThanOrEqual(2, count($row), 'ряд не должен состоять из одной кнопки');
            }
        }
    }

    public function testAsterisksArePairedInRenderedText(): void
    {
        $out = LedgerService::renderScreen([
            ['time' => '2026-08-20 10:00:00', 'text' => '🏛️ Налог за 2 зданий: -400 золота'],
        ], 15);

        $this->assertSame(0, substr_count($out['text'], '*') % 2, 'непарная * роняет Legacy Markdown отправку молча');
    }

    /**
     * Ревью-довесок (денежный путь): `description`/`events.name` теперь несут имена
     * ресурсов/крафта из БД — «Верстак_2» или «Сталь*» без обезвреживания ломают ВЕСЬ
     * рендер тихим Telegram 400 (легаси-Markdown не поддерживает бэкслеш-эскейп,
     * feedback_legacy_markdown_no_backslash_escape). Опасные символы обязаны исчезнуть
     * из строки, а не просто оставаться «парными».
     */
    public function testActionLineSanitizesMarkdownBreakingCharsFromDescription(): void
    {
        $line = LedgerService::actionLine('DEATH_LOSS', 'Смерть персонажа: ресурсы: Верстак_2 ×1, Сталь* ×3');

        $this->assertStringNotContainsString('_', $line);
        $this->assertStringNotContainsString('*', $line);
        $this->assertStringContainsString('Верстак2', $line);
        $this->assertStringContainsString('Сталь', $line);
    }

    public function testEventLineSanitizesMarkdownBreakingCharsFromEventNameAndSummary(): void
    {
        $line = LedgerService::eventLine([
            'event_name'     => 'Атака_Раптора*',
            'effect_details' => json_encode(['log_summary' => 'Урон по броне [Верстак_2]: -5', 'gold_delta' => 0], JSON_UNESCAPED_UNICODE),
        ]);

        $this->assertStringNotContainsString('_', $line);
        $this->assertStringNotContainsString('*', $line);
        $this->assertStringNotContainsString('[', $line);
    }

    /**
     * Сквозной прогон через `renderScreen()` на записи с опасным именем — итоговое
     * сообщение обязано остаться markdown-safe целиком, не только сама строка-источник.
     */
    public function testRenderScreenStaysMarkdownSafeWithDangerousEntryText(): void
    {
        $out = LedgerService::renderScreen([
            ['time' => '2026-08-20 10:00:00', 'text' => LedgerService::actionLine('BUY_RESOURCE', 'res=Сталь* qty=Верстак_2 gold=-45')],
        ], 15);

        $this->assertSame(0, substr_count($out['text'], '*') % 2, 'непарная * роняет Legacy Markdown отправку молча');
        $this->assertSame(0, substr_count($out['text'], '_') % 2, 'непарный _ роняет Legacy Markdown отправку молча');
    }

    /**
     * 🔴 Текстовое сообщение ≤ 4096 на предельном состоянии: глубина 300, длинные
     * описания — лента режется осознанно, с явной пометкой, а не тихо теряется
     * (feedback_caption_length_needs_a_test_not_a_note). Ревью-находка: старый тест
     * проверял лимит ПОДПИСИ К ФОТО (1024) — экран текстовый, лимит 4096, и депту
     * 50 не хватало, чтобы реально упереться в правильный бюджет (нужно ~300 строк).
     */
    public function testTextFitsWithinTelegramLimitOnWorstCaseDepth(): void
    {
        $entries = [];
        for ($i = 0; $i < 300; $i++) {
            $entries[] = [
                'time' => sprintf('2026-08-20 %02d:00:00', $i % 24),
                'text' => '🏛️ Налог за 12 зданий и 8 маяков: -123456 золота (частично, длинное описание для проверки лимита)',
            ];
        }

        $out = LedgerService::renderScreen($entries, 300);

        $this->assertLessThanOrEqual(4096, mb_strlen($out['text']));
        $this->assertStringContainsString('не влезли', $out['text'], 'обрезка обязана быть явной, не тихой');
    }

    /**
     * Ревью-находка №4: резерв под пометку «…и ещё N» вычитался из бюджета ВСЕГДА,
     * даже когда обрезка не нужна — лента могла терять последнюю строку на ровном
     * месте. Граничный случай: две строки, которые ЦЕЛИКОМ влезают в бюджет, но не
     * влезли бы, если бы резерв под пометку применялся заранее безусловно.
     */
    public function testTruncationReserveOnlyAppliesWhenTruncationActuallyNeeded(): void
    {
        // fixedLen (header+intro+footer) выводим ЭМПИРИЧЕСКИ через сам renderScreen(),
        // а не копией текста header/intro/footer — иначе тест ломается при каждой
        // правке формулировок (ровно так и случилось при добавлении Descoped-строки
        // про крафт в footer).
        $probeText = '_';
        $probe     = LedgerService::renderScreen([['time' => '2026-08-20 10:00:00', 'text' => $probeText]], 15);
        $probeLine = '20.08 10:00 — ' . $probeText;
        $fixedLen  = mb_strlen($probe['text']) - mb_strlen($probeLine);
        $available = 4096 - $fixedLen;

        $method = new \ReflectionMethod(LedgerService::class, 'truncationNotice');
        $method->setAccessible(true);
        $noticeLen = mb_strlen((string) $method->invoke(null, 1));

        // formatStamp() всегда отдаёт «dd.mm HH:MM» — 11 символов, плюс « — » (3).
        $stampAndDash = 11 + 3;

        $line1 = str_repeat('А', 300);
        $len1  = $stampAndDash + mb_strlen($line1) + 1; // +1 — перевод строки, как в packLines()

        // Вторая строка НАМЕРЕННО в зоне «влезает без резерва, но не влезла бы с
        // резервом»: available минус len1 минус ПОЛОВИНА noticeLen (гарантированно
        // меньше available без резерва, но больше available минус noticeLen целиком).
        $len2Text = $available - $len1 - $stampAndDash - (int) ($noticeLen / 2);
        $line2    = str_repeat('Б', max(10, $len2Text));

        $out = LedgerService::renderScreen([
            ['time' => '2026-08-20 10:01:00', 'text' => $line1],
            ['time' => '2026-08-20 10:00:00', 'text' => $line2],
        ], 15);

        $this->assertStringContainsString($line2, $out['text'], 'вторая запись не должна теряться из-за резерва, который тут не нужен');
        $this->assertStringNotContainsString('не влезли', $out['text']);
    }

    public function testShortLedgerFitsWithoutTruncationNotice(): void
    {
        $out = LedgerService::renderScreen([
            ['time' => '2026-08-20 10:00:00', 'text' => '🏛️ Налог за 2 зданий: -400 золота'],
        ], 15);

        $this->assertStringNotContainsString('не влезли', $out['text']);
    }

    // ── discoverability: вход безусловный и достижимый ──────────────────────

    public function testWhereItWentCallbackIsRoutedAndReachableFromInventory(): void
    {
        $routes = new CallbackRoutes();

        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\Economy\WhereItWentAction::class,
            $routes->resolve('whereItWent'),
            'callback `whereItWent` не резолвится — кнопка будет мёртвой.'
        );

        $inventory = (string) file_get_contents(
            APPPATH . 'Controllers/Telegram/Commands/Actions/InventoryAction.php'
        );
        $this->assertStringContainsString(
            "'callback_data' => 'whereItWent'",
            $inventory,
            'Вход на «Куда ушло» обязан стоять на «Инвентарь» безусловно (UX-DISCOVERABILITY).'
        );
    }
}

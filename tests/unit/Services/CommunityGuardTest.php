<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Database\Migrations\Adr177CommunityGuardThresholds;
use App\Models\GameSettingsModel;
use App\Services\Community\CommunityGuard;
use App\Services\Community\Verdict;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\CommunityStopTopics;
use Config\CommunityVoice;
use Config\Database;

/**
 * Story community-chat-bot-07 — `CommunityGuard`: пять рубежей против выуживания
 * читов. Story 63 (ADR-177) переписала рубеж 1: единица подтверждения —
 * предложение-юнит корпуса (не документ целиком), корпус без `site_posts`,
 * сравнительно-оценочная форма деньится независимо от лексики. ADR-178
 * (амендмент к той же story) лишила провенанс права вето: измерено, что
 * лексическое покрытие детектирует ДОСЛОВНОСТЬ, а не ПРАВДИВОСТЬ (законный
 * пересказ своими словами — ratio 0.265, лучший несравнительный фабрикат —
 * 0.805, порога между ними не существует). Рубеж 1 теперь возвращает
 * `Verdict::allow($advisories)` — пометки для ревьюера, а не отказ; вето
 * остаётся у сравнительно-оценочной формы и у рубежей 2-5. `deny`/`off` в
 * `community.guard.provenance_mode` — откат к старому вето / полное отключение.
 *
 * Корпус и killswitch/настройки-читатель во всех тестах подменены — гвард
 * проверяется как чистый алгоритм над контролируемыми данными, без реальной БД
 * (`GuideCatalog`/`game_tips`/`game_settings` из прод-корпуса не используются, за
 * исключением тестов с суффиксом `AgainstRealCorpus`, которые намеренно держат
 * гвард на живом `GuideCatalog::sections()` — паттерн подмены `GameSettingsModel`
 * тот же, что в `CommunityIngestServiceTest`).
 *
 * Story 65 (ADR-177 §5а): калибровка (`testCalibrationOnFrozenProvenanceCorpusFixture`)
 * НЕ мерит живой `GuideCatalog`/`game_tips` вовсе — раньше это молча давало
 * благополучные числа на 32 фрагментах вместо боевых 133 (дважды пропустило
 * BLOCK). Судит `tests/_support/Community/provenance-corpus.php` — версионированный
 * срез боевого корпуса, состав которого утверждается тестом поимённо. Живой
 * корпус мерит `php spark community:guard-calibrate` (story 66), не PHPUnit.
 *
 * @internal
 */
final class CommunityGuardTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    /** DatabaseTestTrait: остальные тесты этого файла работают на двойниках, реальные миграции не нужны. */
    protected $migrate = false;

    /**
     * Story 63 — таблица `game_settings` для `testMigration*()` ниже создаётся
     * вручную по продовой схеме (тот же паттерн, что `CommunitySettingsSeedTest`
     * story 03/25): независимость от порядка/состояния миграций тестовой БД.
     */
    private bool $createdGameSettingsTable = false;

    /**
     * `GameSettingsService::get()` кеширует на 60с через `service('cache')`
     * (файловый хендлер по умолчанию, см. `Config\Cache`). Несколько тестов этого
     * файла подряд подменяют значение ОДНОГО И ТОГО ЖЕ ключа (`world.vehicle.enabled`,
     * story 63 — три новых ключа рубежа 1) через разные двойники `GameSettingsModel`;
     * без мок-кеша второй вызов в том же процессе рисковал бы читать файловый кеш,
     * записанный первым вызовом, а не свежий двойник. `mockCache()` — штатный метод
     * `CIUnitTestCase` (`Services::injectMock('cache', new MockCache())`).
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->mockCache();

        $db = Database::connect('tests');
        if (! $db->tableExists('game_settings')) {
            $db->query('
                CREATE TABLE game_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    setting_key VARCHAR(64) NOT NULL,
                    category VARCHAR(32) NOT NULL,
                    value_type VARCHAR(16) NOT NULL,
                    value_int INT NULL,
                    value_float DECIMAL(12,4) NULL,
                    value_bool TINYINT NULL,
                    value_string VARCHAR(255) NULL,
                    default_value_text TEXT NOT NULL,
                    rationale_text TEXT NOT NULL,
                    effect_text TEXT NOT NULL,
                    above_effect_text TEXT NOT NULL,
                    below_effect_text TEXT NOT NULL,
                    recommended_min VARCHAR(64) NULL,
                    recommended_max VARCHAR(64) NULL,
                    hard_min VARCHAR(64) NULL,
                    hard_max VARCHAR(64) NULL,
                    updated_by VARCHAR(128) NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY setting_key (setting_key)
                )
            ');
            $this->createdGameSettingsTable = true;
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->table('game_settings')->whereIn('setting_key', self::MIGRATION_KEYS)->delete();
        if ($this->createdGameSettingsTable) {
            $db->query('DROP TABLE IF EXISTS game_settings');
        }

        parent::tearDown();
    }

    private const MIGRATION_KEYS = [
        'community.guard.provenance_threshold',
        'community.guard.min_source_sentence_words',
        'community.guard.comparative_form',
        'community.guard.provenance_mode',
    ];

    /**
     * Story 65 — состав `tests/_support/Community/provenance-corpus.php` на
     * дату среза (2026-08-26), утверждается ПОИМЁННО (не только счётчиком
     * 32+28=60) — расхождение даже при совпавшем количестве обязано красить
     * тест (`testProvenanceCorpusFixtureHasExpectedComposition`).
     */
    private const PROVENANCE_CORPUS_EXPECTED_SOURCES = [
        'guide:arena',
        'guide:atmosphere',
        'guide:base',
        'guide:biomes',
        'guide:bosses',
        'guide:chat',
        'guide:combat',
        'guide:craft',
        'guide:endgame',
        'guide:faction',
        'guide:food',
        'guide:gather',
        'guide:grow',
        'guide:insurance',
        'guide:island',
        'guide:march',
        'guide:move',
        'guide:nav',
        'guide:npc',
        'guide:oracle',
        'guide:quests',
        'guide:referral',
        'guide:robots',
        'guide:settings',
        'guide:storage',
        'guide:teleport',
        'guide:top',
        'guide:trade',
        'guide:transport',
        'guide:tribute',
        'guide:worldevents',
        'guide:wounds',
        'tip:1st level workbench',
        'tip:Buy a buy buy buy',
        'tip:Character level calculation',
        'tip:Character relocation',
        'tip:Characters first ride',
        'tip:Events in the game',
        'tip:Experience gain',
        'tip:Explore and Collect',
        'tip:Explore territories and bust character',
        'tip:First aid kit basic',
        'tip:Food and water',
        'tip:Fraction availability',
        'tip:Game "Guess Number',
        'tip:Game "Stone, Scissors, Paper"',
        'tip:Game "Wheel of Fortune',
        'tip:Game map',
        'tip:Greenhouse Bonuses',
        'tip:Health regeneration',
        'tip:Knowledge is power!',
        'tip:Kraft gives rise to parameters',
        'tip:Random gaming advice',
        'tip:Resource extraction (formula)',
        'tip:Resource sale',
        'tip:Set up camp',
        'tip:Terrain Study = Success',
        'tip:The craft or the shopping?',
        'tip:Tool precedence',
        'tip:Water resource',
    ];

    /**
     * Story 63/65 — 22 полных естественных НЕсравнительных фабриката (не
     * title-конкатенацию — та даёт вырожденно короткие фразы с искусственно
     * высоким ratio, см. `## Implementation notes` story 63) о РАЗНЫХ
     * механиках. Story 65 §«Старую фабрикатную выборку не переиспользовать»:
     * старые 29 утверждений вида «X даёт больше пользы, чем Y» после R1-R4
     * отсекаются раньше рубежа 1 как `comparative_claim` — эта выборка
     * намеренно НЕ содержит союзов/корней/оборотов сравнения, используется и
     * story 63's `testExpandedNonComparativeFabricationSampleReportsAdvisoryCoverageAgainstRealCorpus`,
     * и story 65's калибровкой на замороженном корпусе — единственный список,
     * не дублирован.
     */
    private const NON_COMPARATIVE_FABRICATED_SAMPLE = [
        'Если идти в поход голодным, добыча падает.',
        'Дрон заряжается от солнечной станции на базе.',
        'Раны затягиваются быстрее, если спать у костра на привале.',
        'Караваны платят пошлину, если проходят рядом с занятой базой.',
        'Оракул острова открывает ставки только по выходным.',
        'Ловушки у воды приманивают редких рыб по ночам.',
        'Телепорт между маяками сжигает часть прочности рюкзака.',
        'Фракция выдаёт бесплатный ремонт брони раз в неделю.',
        'Трофейная подать снижается, если сдать её лично старосте.',
        'Роботы в Ангаре требуют топлива из Мастерской для запуска.',
        'Арена открывается только после завершения похода.',
        'Топ игроков обновляется сразу после каждого боя.',
        'Настройки уведомлений сбрасываются при каждом уровне персонажа.',
        'Общий чат отключается на время активного похода.',
        'Ресурсы со склада пропадают, если не заходить неделю.',
        'Узлы-боссы появляются только рядом с занятыми базами.',
        'Эндгейм-коллекции открывают доступ к бесплатному телепорту.',
        'Мировые события начинаются сразу после завершения похода.',
        'Раны мешают собирать ресурсы в тундре.',
        'Ферма даёт двойной урожай в дождь.',
        'Транспорт ломается, если ехать через воду.',
        'Мастерская расположена рядом с Лабораторией и Роботами на базе.',
    ];

    private function migration(): Adr177CommunityGuardThresholds
    {
        if (! class_exists(Adr177CommunityGuardThresholds::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-08-26-100000_Adr177CommunityGuardThresholds.php';
        }
        $forge = Database::forge('tests');

        return new Adr177CommunityGuardThresholds($forge instanceof Forge ? $forge : null);
    }

    // ── ADR-177 §4, миграция — идемпотентность и полнота (паттерн CommunitySettingsSeedTest) ──

    public function testMigrationSeedsExactlyTheFourDocumentedKeysWithAllFourTextFieldsFilled(): void
    {
        $this->migration()->up();

        $db   = Database::connect('tests');
        $rows = $db->table('game_settings')->whereIn('setting_key', self::MIGRATION_KEYS)->get()->getResultArray();

        $keys = array_column($rows, 'setting_key');
        sort($keys);
        $expected = self::MIGRATION_KEYS;
        sort($expected);
        $this->assertSame($expected, $keys, 'миграция обязана посеять ровно эти четыре ключа (ADR-178 добавила provenance_mode)');

        foreach ($rows as $row) {
            $this->assertNotSame('', trim((string) $row['default_value_text']), $row['setting_key'] . ': default_value_text пуст');
            $this->assertNotSame('', trim((string) $row['rationale_text']), $row['setting_key'] . ': rationale_text пуст');
            $this->assertNotSame('', trim((string) $row['above_effect_text']), $row['setting_key'] . ': above_effect_text пуст');
            $this->assertNotSame('', trim((string) $row['below_effect_text']), $row['setting_key'] . ': below_effect_text пуст');
        }

        // ADR-178: обязательство «перемерить и сдвинуть дефолт» с этой миграции снято —
        // порог возвращён к исходным 0.65 ADR-177 (признак двумодален, настраивать нечего).
        $threshold = $db->table('game_settings')->where('setting_key', 'community.guard.provenance_threshold')->get()->getRowArray();
        $this->assertSame('0.65', $threshold['default_value_text'], 'ADR-178: 0.65 — исходное число ADR-177, не 0.80 story 63 (обязательство перемерить снято)');

        $mode = $db->table('game_settings')->where('setting_key', 'community.guard.provenance_mode')->get()->getRowArray();
        $this->assertSame('advisory', $mode['default_value_text'], 'ADR-178: default режим — advisory (пометка, не вето)');
    }

    public function testMigrationUpIsIdempotent(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $db    = Database::connect('tests');
        $count = $db->table('game_settings')->whereIn('setting_key', self::MIGRATION_KEYS)->countAllResults();

        $this->assertSame(count(self::MIGRATION_KEYS), $count, 'повторный up() не должен создавать дубли');
    }

    public function testMigrationDownRemovesExactlyTheseKeysAndNothingElse(): void
    {
        $this->migration()->up();

        $db = Database::connect('tests');
        $db->table('game_settings')->insert([
            'setting_key'        => 'unrelated.probe',
            'category'           => 'experimental',
            'value_type'         => 'bool',
            'value_bool'         => 1,
            'default_value_text' => 'true',
            'rationale_text'     => 'probe',
            'effect_text'        => 'probe',
            'above_effect_text'  => 'probe',
            'below_effect_text'  => 'probe',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $this->migration()->down();

        $remaining = $db->table('game_settings')->whereIn('setting_key', self::MIGRATION_KEYS)->countAllResults();
        $this->assertSame(0, $remaining, 'down() обязан удалить все четыре ключа');

        $probeStillThere = $db->table('game_settings')->where('setting_key', 'unrelated.probe')->countAllResults();
        $this->assertSame(1, $probeStillThere, 'down() не должен трогать ключи вне списка');

        $db->table('game_settings')->where('setting_key', 'unrelated.probe')->delete();
    }

    /**
     * Единственный фрагмент корпуса про смерть/страховку — намеренно ОДИН документ
     * (не два), т.к. рубеж 1 требует, чтобы предложение опиралось на ОДИН
     * предложение-юнит, а не собиралось из слов, надёрганных по разным
     * предложениям источника. Плюс два фрагмента из другой темы — доказывают,
     * что «не в корпусе» предложение (testSentenceNotInWhiteCorpus…) не проходит
     * по случайному совпадению общих слов.
     */
    private function corpus(): array
    {
        return [
            [
                'source' => 'guide:insurance',
                'text'   => 'Смерть на острове не отматывает прогресс к нулю, но забирает свою долю: '
                    . 'часть ресурсов, часть золота и часть вещей теряется сразу. Если у тебя есть '
                    . 'обжитая база, потери небольшие и по ощущениям безопаснее, чем без базы. '
                    . 'Страховка гасит потери золотом — при смерти не теряешь опыт и характеристики, '
                    . 'пока полис активен.',
            ],
            [
                'source' => 'guide:move',
                'text'   => 'Перемещайся по клеткам стрелками направлений под компасом — каждый шаг '
                    . 'открывает кусочек карты рядом с тобой. Обзор показывает общий вид карты мира.',
            ],
            [
                'source' => 'guide:craft',
                'text'   => 'Верстак общий открывает базовые рецепты сразу на старте. Мастерская и '
                    . 'Профессиональный верстак — более глубокие уровни крафта на базе.',
            ],
        ];
    }

    /**
     * Story 63: двойник `GameSettingsModel` расширен до типизированных значений
     * (было — только `bool` для рубежа 5), чтобы тесты могли подменять три новых
     * ключа рубежа 1 (`provenance_threshold` float, `min_source_sentence_words`
     * int, `comparative_form` string) тем же паттерном, что и килсвитчи.
     *
     * @param array<string, bool|int|float|string> $settingValues
     */
    private function settingsService(array $settingValues): GameSettingsService
    {
        $model = new class ($settingValues) extends GameSettingsModel {
            /** @param array<string, bool|int|float|string> $values */
            public function __construct(private array $values)
            {
            }

            public function findByKey(string $key): ?array
            {
                if (! array_key_exists($key, $this->values)) {
                    return null;
                }
                $value = $this->values[$key];

                return match (true) {
                    is_bool($value)  => ['setting_key' => $key, 'value_type' => 'bool', 'value_bool' => $value ? 1 : 0],
                    is_int($value)   => ['setting_key' => $key, 'value_type' => 'int', 'value_int' => $value],
                    is_float($value) => ['setting_key' => $key, 'value_type' => 'float', 'value_float' => $value],
                    default          => ['setting_key' => $key, 'value_type' => 'string', 'value_string' => (string) $value],
                };
            }
        };

        return new GameSettingsService($model);
    }

    /** @param array<string, bool|int|float|string> $settingValues */
    private function guard(?array $corpusOverride = null, array $settingValues = []): CommunityGuard
    {
        return new CommunityGuard($corpusOverride ?? $this->corpus(), $this->settingsService($settingValues));
    }

    /**
     * Story 21: гвард держит на РЕАЛЬНОМ `defaultCorpus()` (живой `GuideCatalog` +
     * `game_tips`), а не на самодельных фрагментах — `game_tips` внутри него молча
     * сужается до пустоты без БД (см. докблок `defaultCorpus()`), `GuideCatalog`
     * достаточно, он pure-данные (см. `GuideCatalogTest`).
     *
     * @param array<string, bool|int|float|string> $settingValues
     */
    private function realGuard(array $settingValues = []): CommunityGuard
    {
        return new CommunityGuard(null, $this->settingsService($settingValues));
    }

    // ── Рубеж 1, ADR-177 §1 — единица провенанса ────────────────────────────

    /**
     * ADR-178 (амендмент к story 63): провенанс лишён права вето — рубеж 1 больше
     * НЕ возвращает `deny('no_provenance')`, а собирает `advisories`. Инвариант
     * «Подтверждение набирается одним предложением-источником; объединение двух
     * юнитов не подтверждает ничего» теперь проверяется через ПОМЕТКУ, а не
     * отказ: ответ склеивает мысль ПЕРВОГО предложения фрагмента («часть
     * ресурсов, часть золота и часть вещей») со ВТОРЫМ («потери небольшие») —
     * ни ОДИН юнит целиком не покрывает объединённую мысль, поэтому
     * `verdict()` — `allow()`, но с непустой пометкой, называющей ИМЕННО это
     * предложение, лучший (недостаточный) источник и его ratio.
     */
    public function testUnionOfTwoUnitsDoesNotConfirmAnythingButGetsAnAdvisory(): void
    {
        $answer  = 'При смерти теряешь часть ресурсов, золота и вещей, а под базой потери небольшие.';
        $verdict = $this->guard()->verdict($answer, 'Что теряю, когда умираю?', null);

        $this->assertTrue($verdict->isAllow(), 'ADR-178: провенанс без права вето — allow, даже когда предложение не подтверждено');
        $this->assertCount(1, $verdict->advisories, 'объединение двух юнитов не подтверждает ничего — предложение обязано получить пометку');
        $this->assertStringContainsString('guide:insurance', $verdict->advisories[0], 'пометка обязана называть адрес лучшего источника');
        $this->assertMatchesRegularExpression('/ratio=0\.\d\d/', $verdict->advisories[0], 'пометка обязана называть ratio');
    }

    /**
     * Контрольная пара к предыдущему тесту: ОДНО предложение фрагмента целиком
     * (та же мысль про «потери небольшие», без склейки с соседним предложением)
     * подтверждается ПОЛНОСТЬЮ — allow без единой пометки, рубеж 1 не создаёт
     * лишнего шума на полезном тексте.
     */
    public function testSingleWholeUnitConfirmsTheClaimItCarriesWithNoAdvisory(): void
    {
        $verdict = $this->guard()->verdict(
            'Если у тебя есть обжитая база, потери небольшие.',
            'Что теряю, когда умираю?',
            null,
        );

        $this->assertTrue($verdict->isAllow(), 'предложение, подтверждённое ОДНИМ юнитом целиком, обязано пройти');
        $this->assertSame([], $verdict->advisories, 'полностью подтверждённое предложение не должно получать пометку');
    }

    /**
     * ADR-178: предложения, которых нет ни в одном юните корпуса, тоже allow —
     * но с пометкой, называющей именно это предложение (deny здесь больше
     * невозможен структурно, провенанс не решает allow/deny).
     */
    public function testSentenceNotInWhiteCorpusStillAllowsWithAnAdvisory(): void
    {
        // Story 63: фраза без союза сравнения — деталь именно в том, что
        // предложения нет ни в одном юните корпуса (рубеж 1), не в сравнительной
        // форме (это отдельный, независимый гейт, см. testComparativeClaim…).
        $answer  = 'Ночной дозор находит редкие ресурсы значительно активнее обычного.';
        $verdict = $this->guard()->verdict($answer, 'Когда лучше разведывать новые клетки?', null);

        $this->assertTrue($verdict->isAllow());
        $this->assertCount(1, $verdict->advisories);
        $this->assertStringContainsString(mb_substr($answer, 0, -1), $verdict->advisories[0], 'пометка обязана называть непокрытое предложение');
    }

    /**
     * ADR-178, acceptance: `community.guard.provenance_mode=deny` сохраняет
     * прежнее вето ADR-177 — тот же ответ, что в `testSentenceNotInWhiteCorpusStillAllowsWithAnAdvisory`,
     * денится, если режим явно переключён на `deny` (откат без деплоя).
     */
    public function testProvenanceModeDenyRestoresTheOldVeto(): void
    {
        // Story 72: `deny` денит ТОЛЬКО в контексте одобрения — $isApprovalContext=true.
        $guard   = $this->guard(null, ['community.guard.provenance_mode' => 'deny']);
        $verdict = $guard->verdict(
            'Ночной дозор находит редкие ресурсы значительно активнее обычного.',
            'Когда лучше разведывать новые клетки?',
            null,
            true,
        );

        $this->assertTrue($verdict->isDeny(), '`deny` обязан сохранять старое вето ADR-177 в контексте одобрения');
        $this->assertSame('no_provenance', $verdict->reason);
        $this->assertNotNull($verdict->route);
        $this->assertSame([], $verdict->advisories, 'у deny() пометок нет — причина отказа уже несёт смысл');
    }

    /**
     * Story 72, acceptance: тот же `deny`-режим на пути ОТПРАВКИ (`$isApprovalContext`
     * не передан — default `false`) вето НЕ применяет. Второй ревьюер нашёл: до фикса
     * `deny` денил на любом вызывающем без разбора, включая тик авто-ответа —
     * ADR-178 требует «вето только на одобрении, никогда на отправке». Красит,
     * если `deny` снова начнёт резать путь отправки.
     */
    public function testProvenanceModeDenyDoesNotVetoOnSendContextByDefault(): void
    {
        $guard   = $this->guard(null, ['community.guard.provenance_mode' => 'deny']);
        $verdict = $guard->verdict(
            'Ночной дозор находит редкие ресурсы значительно активнее обычного.',
            'Когда лучше разведывать новые клетки?',
            null,
        );

        $this->assertTrue($verdict->isAllow(), '`deny` не должен денить на пути отправки (default $isApprovalContext=false)');
    }

    /** Контрольная пара: то же самое явным `false` — не полагается на молчаливый default. */
    public function testProvenanceModeDenyDoesNotVetoWhenApprovalContextIsExplicitlyFalse(): void
    {
        $guard   = $this->guard(null, ['community.guard.provenance_mode' => 'deny']);
        $verdict = $guard->verdict(
            'Ночной дозор находит редкие ресурсы значительно активнее обычного.',
            'Когда лучше разведывать новые клетки?',
            null,
            false,
        );

        $this->assertTrue($verdict->isAllow(), '`deny` не должен денить, когда контекст явно не одобрение');
    }

    /**
     * Story 72, acceptance: `advisory`/`off` не зависят от `$isApprovalContext`
     * вовсе — параметр управляет ТОЛЬКО веткой `deny`.
     */
    public function testIsApprovalContextDoesNotAffectAdvisoryOrOffModes(): void
    {
        $answer = 'Идти в поход голодным невыгодно: добыча заметно падает.';

        $advisoryFalse = $this->realGuard(['community.guard.provenance_mode' => 'advisory'])->verdict($answer, 'Расскажи?', null, false);
        $advisoryTrue  = $this->realGuard(['community.guard.provenance_mode' => 'advisory'])->verdict($answer, 'Расскажи?', null, true);
        $this->assertSame($advisoryFalse->reason, $advisoryTrue->reason, 'advisory-режим не должен зависеть от контекста вызова');

        $offFalse = $this->guard(null, ['community.guard.provenance_mode' => 'off'])->verdict('Если у тебя есть обжитая база, потери небольшие.', 'Что теряю?', null, false);
        $offTrue  = $this->guard(null, ['community.guard.provenance_mode' => 'off'])->verdict('Если у тебя есть обжитая база, потери небольшие.', 'Что теряю?', null, true);
        $this->assertTrue($offFalse->isAllow());
        $this->assertTrue($offTrue->isAllow());
        $this->assertSame([], $offFalse->advisories);
        $this->assertSame([], $offTrue->advisories);
    }

    /**
     * ADR-178, acceptance: `community.guard.provenance_mode=off` отключает
     * пометки целиком — тот же непокрытый ответ проходит без единой пометки.
     */
    public function testProvenanceModeOffDisablesAdvisoriesEntirely(): void
    {
        $guard   = $this->guard(null, ['community.guard.provenance_mode' => 'off']);
        $verdict = $guard->verdict(
            'Ночной дозор находит редкие ресурсы значительно активнее обычного.',
            'Когда лучше разведывать новые клетки?',
            null,
        );

        $this->assertTrue($verdict->isAllow());
        $this->assertSame([], $verdict->advisories, '`off` обязан отключать пометки, а не просто ослаблять их');
    }

    /**
     * Story 30/38's «известный ложный пропуск» (window-анти-рекомбинация ловила
     * это на грани 0.605) закрылся сам собой удалением окна (story 63): единица
     * теперь предложение, объединение двух соседних мыслей фрагмента больше не
     * подтверждается никаким обходом. R2 («лучше») дополнительно ловит эту же
     * фразу независимо от провенанса. Формула изменилась — фиксируем НОВОЕ
     * поведение вместо старого known-limitation, как и просил докблок story 38.
     */
    public function testFormerlyKnownFalseAllowTrapExampleIsNowDeniedAgainstRealCorpus(): void
    {
        $verdict = $this->realGuard()->verdict(
            'Ставь ловушки у воды — там добыча идёт лучше.',
            'Расскажи про механику.',
            null,
        );

        $this->assertTrue($verdict->isDeny(), 'story 63: формула больше не пропускает эту фразу (закрыто R2, корень «лучше»)');
        $this->assertSame('comparative_claim', $verdict->reason);
    }

    /**
     * Story 21, acceptance: гвард держит на РЕАЛЬНОМ `defaultCorpus()` — шесть
     * ручных утечек из ADR-177 (§«Замер»), правдоподобные, но выдуманные
     * утверждения. Первые пять денятся вето (сравнительная форма или лексика).
     *
     * 🔴 ADR-178: шестая («Редкие ресурсы попадаются чаще, если идти в поход без
     * брони.») ранее денилась ИМЕННО провенансом (`no_provenance`) — единственным
     * рубежом, который ADR-178 лишил права вето. Она не несёт союза сравнения
     * (R1/R2/R3 не срабатывают) и не задевает лексический стоп-лист/стоп-темы,
     * поэтому теперь легитимно `allow` — с пометкой, называющей её ratio.
     * Это не сокрытая находка: явно замерено и запротоколировано в `## Findings`
     * story 63, а не подогнано под старый чекбокс «шесть утечек отклоняются».
     */
    public function testAllSixAdrManualLeakExamplesAreDeniedAgainstRealCorpus(): void
    {
        $examples = [
            'Лаборатория приносит больше пользы, чем Мастерская.',
            'Ночью в лесу шанс найти редкий ресурс выше, чем днём.',
            'Дрон находит узлы охотнее в горах, чем в лесу.',
            'Мастерская на базе даёт больше ресурсов, чем Лаборатория.',
            'Идти в поход голодным невыгодно: добыча заметно падает.',
            // R4 (ADR-178, поправка №2) — сравнительная степень («чаще») + условие
            // с инфинитивом («если идти») в одном предложении. До R4 это была
            // единственная утечка, проходившая все рубежи (см. `## Findings`).
            'Редкие ресурсы попадаются чаще, если идти в поход без брони.',
        ];

        foreach ($examples as $example) {
            $verdict = $this->realGuard()->verdict($example, 'Расскажи про механику.', null);
            $this->assertFalse($verdict->isAllow(), "«{$example}» не должно проходить как allow против реального корпуса");
        }
    }

    /**
     * R4 (ADR-178, поправка №2), acceptance: сравнительная степень + условие-
     * действие в одном предложении денится как `comparative_claim`, независимо
     * от рубежа 1 (провенанса) — тот же принцип, что у R1 («Дрон находит узлы
     * охотнее, чем в лесу»): форма ловится до того, как имеет значение, есть ли
     * для утверждения лексическая опора.
     */
    public function testR4ComparativeDegreeWithActionConditionIsDeniedAsComparativeClaim(): void
    {
        $verdict = $this->guard()->verdict(
            'Редкие ресурсы попадаются чаще, если идти в поход без брони.',
            'Расскажи про механику.',
            null,
        );

        $this->assertTrue($verdict->isDeny());
        $this->assertSame('comparative_claim', $verdict->reason);
    }

    /**
     * R4, признанная граница: условие БЕЗ действия игрока (состояние, не выбор)
     * не задевает R4 — «под своей базой» это обстоятельство, а не «если сделать
     * X». Дублирует часть `testComparativeDegreeWithoutSecondObjectOrValueJudgmentStillPasses`
     * намеренно — здесь именно про R4, а не про R1/R2 в целом.
     */
    public function testR4DoesNotCatchComparativeDegreeWithoutAnActionCondition(): void
    {
        $corpus = [[
            'source' => 'guide:insurance',
            'text'   => 'Под своей базой смерть забирает меньшую часть вещей, ресурсов и золота. '
                . 'Без базы потеря больше и по ощущениям куда тяжелее.',
        ]];
        $guard = $this->guard($corpus);

        $verdict = $guard->verdict('Смерть под своей базой забирает меньшую часть вещей.', 'Что теряю при смерти?', null);

        $this->assertTrue($verdict->isAllow(), 'состояние («под своей базой») — не условие-действие, R4 не должна его ловить');
    }

    /**
     * R4, признанный пробел (ADR-178, поправка №2, зафиксировано прямым текстом
     * — не закрывать): условие с ЛИЧНОЙ формой глагола («если ты идёшь»), а не
     * инфинитивом, R4 не ловит. Это НЕ регрессия — это задокументированная
     * граница: обобщённый совет по-русски берёт инфинитив, личная форма
     * адресна и на лайфхак похожа меньше.
     */
    public function testR4KnownGapPersonalVerbFormInConditionIsNotCaught(): void
    {
        $verdict = $this->guard()->verdict(
            'Редкие ресурсы попадаются чаще, если ты идёшь в поход без брони.',
            'Расскажи про механику.',
            null,
        );

        $this->assertFalse(
            $verdict->reason === 'comparative_claim',
            'признанный пробел R4: личная форма глагола в условии не ловится — задокументировано, не баг',
        );
    }

    /**
     * Story 69, второй круг (ревью тимлида): story 69 закрыла случайное
     * срабатывание `NUMERAL_WORDS` («одно» внутри «невыг**одно**») — фиксом
     * границы слова обнажилась утечка «Идти в поход голодным невыгодно: добыча
     * заметно падает.», которую раньше денил именно этот случайный матч, а не
     * рубеж сравнительной формы по делу. R2 не ловил её, потому что корень
     * «выгодне» (обрезан под сравнительную степень «-ее») не встречается в
     * базовой форме «выгодн**о**». Корни `COMPARATIVE_EVALUATION_ROOTS`
     * укорочены на одну букву — теперь substring совпадает и со сравнительной
     * степенью, и с базовым наречием/предикативом, И с отрицанием «не-» перед
     * корнем (substring не проверяет, что стоит ДО совпадения). Не список слов
     * — укорочение существующих корней, применяется единообразно.
     */
    public function testNegatedEvaluativeAdverbIsDeniedAsComparativeClaim(): void
    {
        $verdict = $this->guard()->verdict(
            'Идти в поход голодным невыгодно: добыча заметно падает.',
            'Расскажи про механику.',
            null,
        );

        $this->assertTrue($verdict->isDeny());
        $this->assertSame('comparative_claim', $verdict->reason);
    }

    /**
     * Story 63, acceptance: буквально из story — «Дрон находит узлы охотнее,
     * чем в лесу» отклоняется как `comparative_claim`, а НЕ случайно провенансом
     * (в отличие от прототипа со списком прилагательных, который пропускал
     * «охотнее» и полагался на то, что провенанс случайно не наберёт порог).
     */
    public function testDroneHuntsMoreEagerlyThanInForestIsDeniedAsComparativeClaimNotByAccident(): void
    {
        $verdict = $this->realGuard()->verdict(
            'Дрон находит узлы охотнее, чем в лесу.',
            'Расскажи про механику.',
            null,
        );

        $this->assertTrue($verdict->isDeny());
        $this->assertSame(
            'comparative_claim',
            $verdict->reason,
            'должно быть denied ИМЕННО правилом сравнительной формы (R1, союз «чем»), а не случайным no_provenance',
        );
    }

    /**
     * Story 70 (ADR-178, поправка №3), acceptance: «чем» БЕЗ сравнительной
     * степени в том же ответе — риторический оборот, не сопоставление, R1
     * больше не денит. Story 65 нашла это как искажающий состав отказов на
     * замороженном эталоне (9.4% вместо ожидавшихся ~3%), два из трёх — ровно
     * этот класс.
     */
    public function testQuestionWordUsageOfChemPassesWithoutComparativeDegree(): void
    {
        $verdict = $this->guard()->verdict(
            'Не знаешь, чем заняться — открой Дела.',
            'Расскажи про механику.',
            null,
        );

        $this->assertTrue($verdict->isAllow(), '«чем» как вопросительное местоимение — не сопоставление, не должно денится');
    }

    /**
     * 🔴 Story 73 (ADR-178, поправка №4) ОТМЕНЯЕТ story 70's вырез под
     * «а не только/просто/лишь» — различитель стоял на неверной оси:
     * поверхность уточнения («можно говорить, а не только драться» — законно)
     * и замены («Ставь X, а не просто Y» — лайфхак) идентична, списком слов
     * после союза семантика не различается. Замена БЕЗ выреза денится честно;
     * законное уточнение теперь тоже денится (цена, принятая ADR — уточнение
     * тривиально перефразируется, `guide:npc` уходит в петлю «лечить источник»).
     * Тест краснеет на возврат story 70's поведения (снова allow на замене).
     */
    public function testANeIsUnconditionalAgainAfterAdr178Amendment4(): void
    {
        $substitution1 = $this->guard()->verdict('Ставь Лабораторию, а не просто Мастерскую.', 'Расскажи про механику.', null);
        $substitution2 = $this->guard()->verdict('Качай выносливость, а не только силу.', 'Расскажи про механику.', null);

        $this->assertTrue($substitution1->isDeny(), 'совет-замена под прикрытием «а не просто» обязан денится');
        $this->assertSame('comparative_claim', $substitution1->reason);
        $this->assertTrue($substitution2->isDeny(), 'совет-замена под прикрытием «а не только» обязан денится');
        $this->assertSame('comparative_claim', $substitution2->reason);
    }

    /**
     * Story 73: цена отката — законное уточнение объёма («не только X, а ещё
     * Y») тоже теперь денится рубежом формы. Это НЕ регрессия story 63/70, а
     * принятая цена ADR-178 поправки №4 — `guide:npc` переходит в петлю «лечить
     * источник», как раньше находка про телепорт (правка `GuideCatalog` —
     * отдельная задача, не эта story).
     */
    public function testLegitimateOnlyClarificationNowDeniedAsAcceptedCost(): void
    {
        $verdict = $this->guard()->verdict(
            'Там можно торговать, а не только драться.',
            'Расскажи про механику.',
            null,
        );

        $this->assertTrue($verdict->isDeny(), 'ADR-178 поправка №4: цена отката — уточнение тоже денится, лечится правкой источника');
        $this->assertSame('comparative_claim', $verdict->reason);
    }

    /**
     * Story 70, acceptance: «нежели»/«вместо» остаются БЕЗУСЛОВНЫМИ — советы-
     * замены не несут сравнительной степени по построению («Ставь Лабораторию
     * вместо Мастерской» — нет ни одного слова из `hasComparativeDegree()»),
     * и требовать её открыло бы этот класс лайфхака. Ни R3, ни R4 их не ловят
     * (нет инфинитива-условия, нет рекомендательного триггера).
     */
    public function testSubstitutionAdviceIsDeniedUnconditionallyViaVstoAndNeither(): void
    {
        $viaVsto = $this->guard()->verdict('Ставь Лабораторию вместо Мастерской.', 'Расскажи про механику.', null);
        $viaANe  = $this->guard()->verdict('Иди в лес, а не в горы.', 'Расскажи про механику.', null);

        $this->assertTrue($viaVsto->isDeny(), '«вместо» — безусловный триггер, степень требовать запрещено');
        $this->assertSame('comparative_claim', $viaVsto->reason);
        $this->assertTrue($viaANe->isDeny(), '«а не» — безусловный триггер (story 73: и с «только/просто/лишь» тоже)');
        $this->assertSame('comparative_claim', $viaANe->reason);
    }

    /**
     * Story 70, acceptance: детектор сравнительной степени — ОДНА функция,
     * общая для R1 («чем») и R4 (степень + условие). Проверяется поведенчески:
     * степень, добавленная к «чем», денит (R1), та же степень в условной форме
     * денит тоже (R4) — оба пути видят один и тот же признак «дороже», а не
     * два независимых списка, которые могли бы разойтись.
     */
    public function testComparativeDegreeDetectorIsSharedBetweenR1AndR4(): void
    {
        $viaR1 = $this->guard()->verdict('Верстак общий открывает базовые рецепты дороже, чем другие способы.', 'Расскажи?', null);
        $viaR4 = $this->guard()->verdict('Верстак общий открывает базовые рецепты дороже, если покупать у торговца.', 'Расскажи?', null);

        $this->assertTrue($viaR1->isDeny());
        $this->assertSame('comparative_claim', $viaR1->reason);
        $this->assertTrue($viaR4->isDeny());
        $this->assertSame('comparative_claim', $viaR4->reason);
    }

    /**
     * 🔴 Story 78 (ADR-178, поправка №5): R5 УДАЛЁН ЦЕЛИКОМ — денил ядро того,
     * что бот обязан говорить (описательные фразы про механику). Красит на
     * возврат правила: если «клауза-императив + маркер оптимизации» когда-
     * нибудь вернётся в любом виде, эти пять пар (различаются только первым
     * словом — существительное-подлежащее вместо местоимения — при неизменном
     * смысле) снова начнут денится.
     */
    public function testR5RemovedDescriptivePhrasesAboutMechanicsNowAllow(): void
    {
        $descriptive = [
            'Уровень здоровья восстанавливается, пока ты на базе.',
            'Здоровье восстанавливается, пока ты на базе.',
            'Верстаки доступны, пока ты в лагере.',
            'Маяки работают, пока стоит база.',
            'Инвентарь блокируется во время боя.',
        ];

        foreach ($descriptive as $answer) {
            $verdict = $this->guard()->verdict($answer, 'Расскажи про механику.', null);
            $this->assertTrue($verdict->isAllow(), "«{$answer}» — описательная фраза про механику, обязана проходить (R5 удалён)");
        }
    }

    /**
     * Story 73, acceptance: правка границы слова story 69 («максимум одна
     * лишняя буква») закрыла случайные подстрочные совпадения, но заодно
     * перестала ловить падежные формы самого стоп-слова длиннее одной буквы —
     * «потолка»/«потолки» (беглая гласная «о» ломает буквальный префикс,
     * закрыто `STEM_OVERRIDES`), «порогов»/«сотни» (2-3-буквенное окончание,
     * закрыто допуском на суффикс). Пять слов из story 69 остаются allow —
     * граница не откатилась к голому `str_contains()`.
     */
    public function testStopWordDeclensionFormsAreCaughtWithoutReopeningStory69FalsePositives(): void
    {
        $declinedForms = [
            'Урон упирается в потолка прочности.',
            'Урон упирается в потолки прочности.',
            'Стражи охраняют порогов входа.',
            'В сундуке лежат сотни монет.',
        ];
        foreach ($declinedForms as $answer) {
            $verdict = $this->guard()->verdict($answer, 'Расскажи про механику.', null);
            $this->assertTrue($verdict->isDeny(), "«{$answer}» — падежная форма стоп-слова, обязана денится");
            $this->assertSame('lexical_stoplist', $verdict->reason);
        }

        $protectedByStory69 = [
            'Здесь тихое место для лагеря.',
            'Верстак стоит на базе.',
            'Это просто крафт ресурсов.',
            'Копьё имеет острое остриё.',
            'Все постройки выглядят одинаково.',
        ];
        foreach ($protectedByStory69 as $answer) {
            $verdict = $this->guard()->verdict($answer, 'Расскажи про механику.', null);
            $this->assertTrue($verdict->isAllow(), "«{$answer}» — совпадение внутри чужого слова, story 69 обязана остаться в силе");
        }
    }

    /**
     * Story 78, acceptance: спрягаемые формы глаголов-стоп-слов («упирается»
     * → «упираются», «окупается» → «окупаются», «перестаёт» → «перестают»)
     * меняют гласную ПЕРЕД окончанием («-ется»/«-ются», «-ёт»/«-ют»), а не
     * только добавляют буквы в хвост — допуск story 73 по длине суффикса это
     * не покрывал (буквы расходятся раньше конца слова). Закрыто
     * `STEM_OVERRIDES`/`TOLERANCE_OVERRIDES`.
     */
    public function testConjugatedStopVerbFormsAreCaught(): void
    {
        $cases = [
            'Показатели упираются в предел.',
            'Такие вылазки не окупаются.',
            'Батареи перестают держать заряд.',
        ];

        foreach ($cases as $answer) {
            $verdict = $this->guard()->verdict($answer, 'Расскажи про механику.', null);
            $this->assertTrue($verdict->isDeny(), "«{$answer}» — спрягаемая форма стоп-глагола, обязана денится");
            $this->assertSame('lexical_stoplist', $verdict->reason);
        }
    }

    /**
     * Story 78, acceptance: обратные ложные срабатывания — «стол»/«сток»
     * (посторонние слова, начинающиеся так же, как числительное «сто», плюс
     * ОДНА буква — старый допуск их ловил) и «достаточность» (другая часть
     * речи от «достаточно» через суффикс «-ость», не падежная форма). Story 78
     * убрала допуск для «сто» (0 букв, ADR: оно практически не склоняется) и
     * не расширяла допуск «достаточно» сверх default (1 буква).
     */
    public function testReverseFalsePositivesNoLongerMatch(): void
    {
        $notLeaks = [
            'Поставь стол в углу.',
            'Возьми сток воды.',
            'Достаточность пайка проверена.',
        ];

        foreach ($notLeaks as $answer) {
            $verdict = $this->guard()->verdict($answer, 'Расскажи про механику.', null);
            $this->assertTrue($verdict->isAllow(), "«{$answer}» — постороннее слово, не должно денится стоп-листом");
        }
    }

    /**
     * Story 63, acceptance: сравнительная форма деньится НЕЗАВИСИМО от того,
     * есть ли для неё лексическое подтверждение — ответ почти дословно совпадает
     * с реальным фрагментом (лексика прошла бы провенанс), но несёт союз «чем» и
     * денится ДО проверки провенанса.
     */
    public function testComparativeClaimIsDeniedRegardlessOfLexicalSupport(): void
    {
        // Story 70 (ADR-178 поправка №3): «чем» без сравнительной степени в
        // ответе R1 больше не ловит (см. testUnconditionalRewordingsAllow…) —
        // фраза для этого теста обязана нести И «чем», И степень («дороже»),
        // иначе тест перестанет проверять именно R1.
        $verdict = $this->guard()->verdict(
            'Верстак общий открывает базовые рецепты дороже, чем другие способы.',
            'Расскажи про верстак.',
            null,
        );

        $this->assertTrue($verdict->isDeny());
        $this->assertSame('comparative_claim', $verdict->reason);
    }

    /**
     * Story 63, acceptance: сравнительная степень БЕЗ второго объекта и БЕЗ
     * оценки пользы по-прежнему проходит — оба примера дословно из ADR-177 §2
     * («Смерть под своей базой забирает меньшую часть вещей»/«Без базы потеря
     * больше»), подтверждённые фрагментом корпуса, специально несущим эту мысль
     * (§5.4 оговорка про качественный ответ о смерти не должна резаться).
     */
    public function testComparativeDegreeWithoutSecondObjectOrValueJudgmentStillPasses(): void
    {
        $corpus = [[
            'source' => 'guide:insurance',
            'text'   => 'Под своей базой смерть забирает меньшую часть вещей, ресурсов и золота. '
                . 'Без базы потеря больше и по ощущениям куда тяжелее.',
        ]];
        $guard = $this->guard($corpus);

        $withoutBase = $guard->verdict('Смерть под своей базой забирает меньшую часть вещей.', 'Что теряю при смерти?', null);
        $noBase      = $guard->verdict('Без базы потеря больше.', 'Что теряю при смерти?', null);

        $this->assertTrue($withoutBase->isAllow(), 'сравнительная степень без союза и без оценки пользы не должна резаться рубежом сравнительной формы');
        $this->assertTrue($noBase->isAllow(), 'сравнительная степень без союза и без оценки пользы не должна резаться рубежом сравнительной формы');
        $this->assertSame([], $withoutBase->advisories, 'полностью подтверждённое предложение не должно получать пометку');
        $this->assertSame([], $noBase->advisories, 'полностью подтверждённое предложение не должно получать пометку');
    }

    /**
     * Story 63, расширенная выборка (22 полных естественных несравнительных
     * фабриката про РАЗНЫЕ механики) остаётся в тесте, но её роль сменилась
     * вместе с ADR-178: раньше она проверяла коридор вето (≤10% ложных
     * пропусков), теперь вето у провенанса нет вовсе, и «ложный пропуск» — не
     * ошибка, а норма advisory-режима. Тест проверяет то, что действительно
     * держится независимо от режима:
     *  - ни один из 22 не денится `comparative_claim` (выборка намеренно
     *    несравнительная — случайное срабатывание R1/R2/R3 означало бы, что
     *    тест перестал измерять именно провенанс, а не сравнительную форму);
     *  - каждый исход, где ЕДИНСТВЕННЫЙ денящий рубеж — рубеж 5/3 (dormant/
     *    лексика), остаётся deny (эти рубежи вето не теряли);
     *  - остальные — allow, с advisory-покрытием, которое печатается в STDERR
     *    поимённо при каждом прогоне (для видимости дрейфа, не как gate).
     */
    public function testExpandedNonComparativeFabricationSampleReportsAdvisoryCoverageAgainstRealCorpus(): void
    {
        $fabrications = self::NON_COMPARATIVE_FABRICATED_SAMPLE;
        $this->assertGreaterThanOrEqual(20, count($fabrications), 'story обязала расширить выборку до ≥20');

        $guard             = $this->realGuard();
        $withAdvisory      = [];
        $allowNoAdvisory   = [];
        $byReason          = [];
        foreach ($fabrications as $fabrication) {
            $verdict = $guard->verdict($fabrication, 'Расскажи про механику.', null);
            $reason  = $verdict->isAllow() ? 'allow' : $verdict->reason;
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;

            // Acceptance: ни один из 22 не денится comparative_claim — выборка
            // намеренно несравнительная, случайное срабатывание R1/R2/R3 здесь
            // означало бы, что тест перестал измерять именно провенанс.
            $this->assertNotSame(
                'comparative_claim',
                $reason,
                "«{$fabrication}» помечен как сравнительный — выборка должна оставаться чисто провенансной",
            );

            if ($verdict->isAllow() && $verdict->advisories !== []) {
                $withAdvisory[] = $fabrication;
            } elseif ($verdict->isAllow()) {
                $allowNoAdvisory[] = $fabrication;
            }
        }

        fwrite(STDERR, sprintf(
            "\n[CommunityGuard story 63, несравнительная фабрикатная выборка, advisory-режим] "
                . "по причине: %s\n  allow с пометкой: %d/%d\n  allow БЕЗ пометки (ratio>=порога поимённо): %s\n",
            json_encode($byReason, JSON_UNESCAPED_UNICODE),
            count($withAdvisory),
            count($fabrications),
            $allowNoAdvisory === [] ? '—' : implode(' | ', $allowNoAdvisory),
        ));

        // Смоук-гарантия механизма: пометки реально появляются на этой выборке
        // (не молчаливый no-op) — доля не гейтится, только факт ненулевого сигнала.
        $this->assertNotEmpty($withAdvisory, 'ни один фабрикат не получил пометку — рубеж 1 подозрительно тих');
    }

    /**
     * Story 30, acceptance: три строки из ревью-таблицы дефекта. Фабрикат денится
     * ИМЕННО провенансом (`no_provenance`) — до фикса набирал высокий doc-ratio
     * против совершенно постороннего фрагмента (см. `## Findings` story 30).
     */
    public function testStory30DefectTableThreeRequiredRowsAgainstRealCorpus(): void
    {
        // ADR-178: провенанс лишён права вето — фабрикат теперь `allow` с
        // пометкой (было `deny('no_provenance')` до ADR-178). Проверяем то, что
        // осталось верным: пометка ЕСТЬ, отказа НЕТ.
        $fabrication = $this->realGuard()->verdict(
            'Если ходить в поход голодным, добыча падает.',
            'Расскажи про механику.',
            null,
        );
        $this->assertTrue($fabrication->isAllow(), 'ADR-178: провенанс без права вето — allow даже для непокрытого фабриката');
        $this->assertNotEmpty($fabrication->advisories, 'фабрикат обязан получить пометку — ни один источник не подтверждает его целиком');

        $greenhouse = $this->realGuard()->verdict(
            'Теплица строится на базе, там растут семена.',
            'Расскажи про механику.',
            null,
        );
        $this->assertTrue($greenhouse->isAllow(), 'добросовестный пересказ раздела «Еда» не должен резаться');

        $quests = $this->realGuard()->verdict(
            'Квесты открываются в разделе Дела внизу экрана.',
            'Расскажи про механику.',
            null,
        );
        $this->assertTrue($quests->isAllow(), 'добросовестный пересказ раздела «Дела» не должен резаться');
    }

    /**
     * Story 63: единственное предложение источника, дословно из раздела «🛡 Что
     * забирает смерть» (`guide:insurance`) — добросовестная цитата подтверждается
     * ОДНИМ юнитом целиком и не режется ужесточённым рубежом 1. Story 21's старый
     * пример (пересказ, склеивающий ДВА предложения источника) закрыт story 63
     * инвариантом «один юнит целиком» — см. `testUnionOfTwoUnitsDoesNotConfirmAnything`
     * выше, где та же склейка теперь denied НАМЕРЕННО.
     */
    public function testSingleSentenceQuoteOfRealCorpusFragmentIsAllowed(): void
    {
        $verdict = $this->realGuard()->verdict(
            'Под своей крышей потери символические, у бездомного — тяжёлые.',
            'Что теряю, когда умираю?',
            null,
        );

        $this->assertTrue($verdict->isAllow(), 'дословное предложение реального раздела не должно резаться ужесточённым рубежом 1');
        $this->assertSame([], $verdict->advisories, 'дословная цитата подтверждена целиком — пометки быть не должно');
    }

    /**
     * Story 30: совпадение лексики с одним фрагментом само по себе не должно
     * давать полное (безпометочное) подтверждение — фраза правдоподобно звучит и
     * делит реальные имена («Мастерская», «Лаборатория», «Роботы») с настоящими
     * разделами, но нигде в корпусе не стоят рядом в этой комбинации.
     *
     * 🔴 ADR-178: рубеж провенанса больше не денит — он ПОМЕЧАЕТ. Story 63,
     * `## Findings`: одна конкретная фраза этого класса («Мастерская расположена
     * рядом с Лабораторией и Роботами на базе.») случайно набирает ratio ≥0.65 и
     * не получает даже пометки — доказательство того, что признак не разделяет
     * классы (ADR-178, посылка). Здесь используется соседняя фраза той же
     * рекомбинации с бОльшим числом слов, которая честно получает пометку —
     * тест проверяет механизм (пометка появляется), а не конкретный порог.
     */
    public function testRecombinedFabricationSharingLexiconWithOneFragmentGetsAnAdvisory(): void
    {
        $verdict = $this->realGuard()->verdict(
            'Мастерская выдаёт семена рядом с Лабораторией и Роботами.',
            'Расскажи про механику.',
            null,
        );

        $this->assertTrue($verdict->isAllow(), 'ADR-178: провенанс без права вето — allow даже для непокрытой рекомбинации');
        $this->assertNotEmpty($verdict->advisories, 'рекомбинация реальной лексики без реального утверждения обязана получить пометку');
    }

    // ── ADR-177 §3 — состав корпуса ─────────────────────────────────────────

    /**
     * Story 63, acceptance: `site_posts` в корпусе нет; `community_answers` в
     * корпусе нет. Реальный `defaultCorpus()` инспектируется через reflection —
     * источники обязаны начинаться только с `guide:`/`tip:`.
     */
    public function testRealCorpusContainsNoSitePostsAndNoCommunityAnswers(): void
    {
        $guard      = new CommunityGuard();
        $reflection = new \ReflectionClass($guard);
        $property   = $reflection->getProperty('corpus');
        $property->setAccessible(true);
        /** @var list<array{source: string, text: string}> $corpus */
        $corpus = $property->getValue($guard);

        $this->assertNotEmpty($corpus, 'живой корпус (guide+tips) обязан быть непустым в тестовом окружении');
        foreach ($corpus as $fragment) {
            $this->assertStringStartsNotWith('post:', $fragment['source'], 'site_posts не должны попадать в корпус (ADR-177 §3)');
            $this->assertStringStartsNotWith('community_answer', $fragment['source'], 'community_answers — инвариант анти-храповика');
        }
    }

    /**
     * Source-скан (как `testCommunityGuardHasNoGameBalanceInDependencies`):
     * `SitePostModel` не используется вовсе. Скан НЕ проверяет инвариант
     * анти-храповика («`community_answers` не входят в корпус») — строка
     * `community_answers` легитимно встречается в докблоках/комментариях как
     * объяснение ПОЧЕМУ, и скан исходника в принципе не доказательство: сломанный
     * метод оставит такой тест зелёным. Тот инвариант доказывается исполнением на
     * реальной БД — это story 64, не эта.
     */
    public function testCommunityGuardDoesNotReferenceSitePostModel(): void
    {
        $source = file_get_contents(APPPATH . 'Services/Community/CommunityGuard.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('SitePostModel', $source, 'ADR-177 §3: site_posts исключены из корпуса целиком');
    }

    // ── ADR-177 §4 — три ключа GameSettings ─────────────────────────────────
    //
    // Каждая пара «строгое/мягкое значение» — ДВА отдельных теста, не два вызова
    // `$this->guard()` в одном методе: `GameSettingsService::get()` кеширует по
    // ключу на 60с через `service('cache')` (`mockCache()` в `setUp()` даёт
    // каждому ТЕСТУ свежий пустой кеш, но ВНУТРИ одного теста два разных двойника
    // на тот же ключ всё равно читали бы значение первого вызова — проверено
    // прогоном без мока: та же настройка, два значения подряд в одном процессе,
    // второй вызов вернул закешированное первое).

    /** Story 63, acceptance: высокий порог рубежа 1 обязан денить частично совпадающее предложение. */
    /**
     * ADR-178: порог больше не решает allow/deny — он регулирует шумность
     * пометки. Высокий порог из `GameSettings` обязан добавить пометку к тому же
     * частично совпадающему предложению, которое низкий порог оставляет чистым.
     */
    public function testHighProvenanceThresholdFromGameSettingsAddsAnAdvisoryToPartialMatch(): void
    {
        $corpus = [['source' => 'guide:insurance', 'text' => 'Смерть на острове забирает часть вещей и часть золота, если у тебя нет базы.']];
        $guard  = $this->guard($corpus, ['community.guard.provenance_threshold' => 0.99]);

        $verdict = $guard->verdict(
            'Смерть на острове забирает часть вещей и часть золота, если у тебя нет крепкой обороны.',
            'Что теряю?',
            null,
        );

        $this->assertTrue($verdict->isAllow(), 'ADR-178: провенанс без права вето — allow независимо от порога');
        $this->assertNotEmpty($verdict->advisories, 'высокий порог из GameSettings обязан добавить пометку частично совпадающему предложению');
    }

    /** Story 63, acceptance: низкий порог рубежа 1 не добавляет пометку тому же частично совпадающему предложению. */
    public function testLowProvenanceThresholdFromGameSettingsAllowsPartialMatchWithNoAdvisory(): void
    {
        $corpus = [['source' => 'guide:insurance', 'text' => 'Смерть на острове забирает часть вещей и часть золота, если у тебя нет базы.']];
        $guard  = $this->guard($corpus, ['community.guard.provenance_threshold' => 0.05]);

        $verdict = $guard->verdict(
            'Смерть на острове забирает часть вещей и часть золота, если у тебя нет крепкой обороны.',
            'Что теряю?',
            null,
        );

        $this->assertTrue($verdict->isAllow(), 'низкий порог из GameSettings обязан пропускать то же частично совпадающее предложение');
        $this->assertSame([], $verdict->advisories, 'низкий порог не должен добавлять пометку тому, что он сам считает достаточным');
    }

    /** Story 63, acceptance: непомерно высокий минимум слов юнита вычищает корпус — подтверждать нечем, allow с пометкой. */
    public function testHugeMinSourceSentenceWordsFromGameSettingsEmptiesTheCorpusAndAddsAnAdvisory(): void
    {
        $guard = $this->guard($this->corpus(), ['community.guard.min_source_sentence_words' => 100]);

        $verdict = $guard->verdict('Если у тебя есть обжитая база, потери небольшие.', 'Что теряю?', null);

        $this->assertTrue($verdict->isAllow(), 'ADR-178: провенанс без права вето — allow даже когда корпус вычищен целиком');
        $this->assertNotEmpty($verdict->advisories, 'минимум в 100 слов обязан вычистить корпус — ни один юнит не наберётся, предложение обязано получить пометку');
    }

    /** Контрольная пара: значение по умолчанию (3) на том же предложении по-прежнему проходит без пометки. */
    public function testDefaultMinSourceSentenceWordsStillAllowsTheSameSentenceWithNoAdvisory(): void
    {
        $guard = $this->guard($this->corpus(), ['community.guard.min_source_sentence_words' => 3]);

        $verdict = $guard->verdict('Если у тебя есть обжитая база, потери небольшие.', 'Что теряю?', null);

        $this->assertTrue($verdict->isAllow());
        $this->assertSame([], $verdict->advisories);
    }

    /** Story 63, acceptance: `comparative_form=deny` (default) — сравнительная форма деньится с причиной `comparative_claim`. */
    public function testComparativeFormDenyModeRejectsComparativeAnswer(): void
    {
        $corpus = [['source' => 'guide:insurance', 'text' => 'Под своей базой потери меньше, а без неё — намного больше.']];
        $guard  = $this->guard($corpus, ['community.guard.comparative_form' => 'deny']);

        $verdict = $guard->verdict('Под своей базой потери меньше, чем без неё.', 'Что теряю?', null);

        $this->assertTrue($verdict->isDeny());
        $this->assertSame('comparative_claim', $verdict->reason);
    }

    /**
     * Story 63, acceptance: `comparative_form=off` выключает ИМЕННО эту проверку —
     * тот же ответ на тот же корпус (лексически им полностью подтверждён) при
     * выключенном рубеже сравнительной формы проходит рубеж 1 целиком (allow),
     * доказывая, что выключатель снимает блокировку, а не остаётся no-op.
     */
    public function testComparativeFormOffLetsTheSameFullySupportedAnswerThrough(): void
    {
        $corpus = [['source' => 'guide:insurance', 'text' => 'Под своей базой потери меньше, а без неё — намного больше.']];
        $guard  = $this->guard($corpus, ['community.guard.comparative_form' => 'off']);

        $verdict = $guard->verdict('Под своей базой потери меньше, чем без неё.', 'Что теряю?', null);

        $this->assertTrue($verdict->isAllow(), 'при off и полной лексической поддержке ответ обязан пройти — выключатель реально снимает блокировку');
    }

    // ── Рубеж 3 — лексический стоп-лист ─────────────────────────────────────

    public function testLexicalLeakWithoutAnyDigitsIsDenied(): void
    {
        $verdict = $this->guard()->verdict(
            'Лаборатория окупается быстрее Мастерской.',
            'Что лучше строить: Лабораторию или Мастерскую?',
            null,
        );

        $this->assertTrue($verdict->isDeny());
        $this->assertSame('lexical_stoplist', $verdict->reason);
        $this->assertDoesNotMatchRegularExpression('/\d/', 'Лаборатория окупается быстрее Мастерской.');
        $this->assertNotNull($verdict->route);
    }

    public function testSpelledOutNumeralsAreCaughtLikeDigits(): void
    {
        $percentWords = $this->guard()->verdict('Сорок процентов прочности теряется сразу.', 'Сколько прочности теряется?', null);
        $doubleWord   = $this->guard()->verdict('Урон вдвое выше ночью.', 'Как меняется урон ночью?', null);
        $rawDigits    = $this->guard()->verdict('Урон 40% выше ночью.', 'Как меняется урон ночью?', null);

        $this->assertTrue($percentWords->isDeny());
        $this->assertTrue($doubleWord->isDeny());
        $this->assertTrue($rawDigits->isDeny());
        $this->assertSame('lexical_stoplist', $percentWords->reason);
        $this->assertSame('lexical_stoplist', $doubleWord->reason);
        $this->assertSame('lexical_stoplist', $rawDigits->reason);
    }

    /**
     * Story 69, acceptance: гипотеза «`NUMERAL_WORDS`/`LEXICAL_STOPLIST` сверяются
     * `str_contains()` без границы слова» подтверждена исполнением (см.
     * `## Findings`) — все пять примеров, названных в story, реально денились
     * `lexical_stoplist` ДО фикса. Красит тест на возврат наивного `str_contains()`:
     * если границу слова убрать, все пять снова станут `deny`.
     */
    public function testStopWordsNoLongerMatchInsideUnrelatedWords(): void
    {
        $safe = [
            'Здесь тихое место для лагеря.',
            'Верстак стоит на базе.',
            'Это просто крафт ресурсов.',
            'Копьё имеет острое остриё.',
            'Все постройки выглядят одинаково.',
        ];

        foreach ($safe as $answer) {
            $verdict = $this->guard()->verdict($answer, 'Расскажи про механику.', null);
            $this->assertTrue($verdict->isAllow(), "«{$answer}» не должно денится — совпадение внутри чужого слова, не самим словом");
        }
    }

    /** Контрольная пара: те же слова как САМОСТОЯТЕЛЬНЫЕ по-прежнему ловятся. */
    public function testStopWordsStillMatchAsStandaloneWords(): void
    {
        $leaks = [
            'Сто золотых лежит в сундуке.',
            'Три ресурса нужно для крафта.',
        ];

        foreach ($leaks as $answer) {
            $verdict = $this->guard()->verdict($answer, 'Расскажи про механику.', null);
            $this->assertTrue($verdict->isDeny(), "«{$answer}» — числительное самостоятельным словом обязано денится");
            $this->assertSame('lexical_stoplist', $verdict->reason);
        }
    }

    /**
     * Story 69: единственное осознанное исключение из границы слова — «дцать»/
     * «десят» никогда не бывают отдельным словом (инфикс-фрагменты составных
     * числительных), граница убила бы их целиком. Контрольная пара доказывает,
     * что исключение не сломано фиксом (составные числительные всё ещё ловятся).
     */
    public function testCompoundNumeralsStillCaughtViaFragmentException(): void
    {
        $leaks = [
            'Двадцать пять единиц урона.',
            'Пятьдесят монет за визит.',
        ];

        foreach ($leaks as $answer) {
            $verdict = $this->guard()->verdict($answer, 'Расскажи про механику.', null);
            $this->assertTrue($verdict->isDeny(), "«{$answer}» — составное числительное обязано денится через «дцать»/«десят»");
            $this->assertSame('lexical_stoplist', $verdict->reason);
        }
    }

    public function testMonosyllableConfirmationOnMechanicQuestionIsDenied(): void
    {
        $verdict = $this->guard()->verdict('Да.', 'Уворот действительно есть в игре?', null);

        $this->assertTrue($verdict->isDeny());
        $this->assertSame('lexical_stoplist', $verdict->reason);
    }

    // ── Рубеж 2 — гвард на входящем ─────────────────────────────────────────

    public function testQuestionWithNumberAndConfirmationFormDeniesAnyAnswer(): void
    {
        $safeAnswer = $this->guard()->verdict(
            'Не знаю точно. Передал вопрос разработчику.',
            'Уворот же упирается в 75, да?',
            null,
        );
        $unrelatedAnswer = $this->guard()->verdict(
            'Верстак общий открывает базовые рецепты сразу на старте.',
            'Уворот же упирается в 75, да?',
            null,
        );

        $this->assertTrue($safeAnswer->isDeny(), 'даже безопасный текст UNKNOWN не должен уходить на такой вопрос');
        $this->assertTrue($unrelatedAnswer->isDeny());
        $this->assertSame('question_leaks_signal', $safeAnswer->reason);
        $this->assertSame('question_leaks_signal', $unrelatedAnswer->reason);
    }

    public function testQuestionInHypothesisFormWithoutDigitsIsDenied(): void
    {
        $verdict = $this->guard()->verdict(
            'Верстак общий открывает базовые рецепты сразу на старте.',
            'Правда, что второй верстак открывается сразу после первого похода?',
            null,
        );

        $this->assertTrue($verdict->isDeny());
        $this->assertSame('question_leaks_signal', $verdict->reason);
    }

    /**
     * Story 30: хвостовая регулярка формы гипотезы обязана требовать границу
     * слова ПЕРЕД «да» — без неё «Где вода?» / «Роби, где взять еда?» ловились
     * как гипотеза только потому, что оканчиваются на буквы «да» (часть другого
     * слова, не отдельное подтверждающее «да»).
     */
    public function testHypothesisTailRequiresWordBoundaryBeforeDa(): void
    {
        $safeAnswer = 'Верстак общий открывает базовые рецепты сразу на старте.';

        $whereWaterCrowd = $this->guard()->verdict($safeAnswer, 'Народ, а где вода?', null);
        $whereWater       = $this->guard()->verdict($safeAnswer, 'Где вода?', null);
        $whereFood        = $this->guard()->verdict($safeAnswer, 'Роби, где взять еда?', null);
        $droneHypothesis  = $this->guard()->verdict($safeAnswer, 'Дрон летает дольше, да?', null);

        $this->assertTrue($whereWaterCrowd->isAllow(), '«Народ, а где вода?» — обычный вопрос, не гипотеза');
        $this->assertTrue($whereWater->isAllow(), '«Где вода?» — обычный вопрос, не гипотеза');
        $this->assertTrue($whereFood->isAllow(), '«Роби, где взять еда?» — обычный вопрос, не гипотеза');
        $this->assertTrue($droneHypothesis->isDeny(), '«…, да?» — форма проверки гипотезы, блокирует любой ответ');
        $this->assertSame('question_leaks_signal', $droneHypothesis->reason);
    }

    /**
     * Story 54: живой Tier-3 нашёл вопрос с обратным слэшем после «?», который
     * прошёл рубеж 2 — якорь `\?\s*$` требовал, чтобы «?» был последним значащим
     * символом. Живая пунктуация («да?)», «да?!», «да?..», «да?))», «да? 🙂»,
     * «да?\») не редкость, а норма письма, и не должна выключать проверку.
     * Граница слова перед «да» (регрессия story 30) обязана остаться цела.
     */
    public function testHypothesisTailSurvivesTrailingPunctuation(): void
    {
        $safeAnswer = 'Верстак общий открывает базовые рецепты сразу на старте.';

        $tails = ['да?)', 'да?!', 'да?..', 'да?))', 'да? 🙂', 'да?\\'];
        foreach ($tails as $tail) {
            $verdict = $this->guard()->verdict($safeAnswer, "Дрон перестаёт приносить после софткапа, {$tail}", null);
            $this->assertTrue(
                $verdict->isDeny(),
                "«{$tail}» — форма проверки гипотезы, живая пунктуация не должна её выключать"
            );
            $this->assertSame('question_leaks_signal', $verdict->reason);
        }

        $whereWater = $this->guard()->verdict($safeAnswer, 'Где вода?', null);
        $whereFood  = $this->guard()->verdict($safeAnswer, 'Роби, где взять еда?', null);
        $this->assertTrue($whereWater->isAllow(), '«Где вода?» — обычный вопрос, не гипотеза (story 30 регрессия)');
        $this->assertTrue($whereFood->isAllow(), '«Роби, где взять еда?» — обычный вопрос, не гипотеза (story 30 регрессия)');
    }

    // ── Рубеж 5 — live vs dormant ────────────────────────────────────────────

    public function testMentionOfOracleWithoutRequiresSettingIsDenied(): void
    {
        $verdict = $this->guard()->verdict(
            'Оракул острова принимает ставки на исход событий.',
            'Что такое Оракул?',
            null,
        );

        $this->assertTrue($verdict->isDeny());
        $this->assertSame('missing_requires_setting', $verdict->reason);
        $this->assertNotNull($verdict->route);
    }

    public function testTransportAnswerDeniedWhileKillswitchDisabled(): void
    {
        $guard = $this->guard(null, ['world.vehicle.enabled' => false]);

        $verdict = $guard->verdict(
            'Машина едет быстрее пешего похода.',
            'Есть ли в игре транспорт?',
            'world.vehicle.enabled',
        );

        $this->assertTrue($verdict->isDeny());
        $this->assertSame('dormant_setting_disabled', $verdict->reason);
        $this->assertNotNull($verdict->route);
    }

    public function testTransportAnswerReachesFurtherGatesWhileKillswitchEnabled(): void
    {
        $guard = $this->guard(null, ['world.vehicle.enabled' => true]);

        $verdict = $guard->verdict(
            'Машина едет быстрее пешего похода.',
            'Есть ли в игре транспорт?',
            'world.vehicle.enabled',
        );

        // Включённый килсвитч не даёт мгновенный allow — тот же текст ловится
        // следующим рубежом (лексика: «быстрее»), доказывая, что рубеж 5 пройден.
        $this->assertTrue($verdict->isDeny());
        $this->assertSame('lexical_stoplist', $verdict->reason);
    }

    /**
     * Story 21, acceptance: заполненный `requires_setting` закрывает СВОЮ тему
     * (транспорт), но не должен маскировать упоминание ДРУГОЙ dormant-темы в том
     * же ответе — до фикса проверка dormant-маркеров жила в ветке `elseif` и
     * пропускалась целиком, как только `requires_setting` был заполнен чем угодно.
     */
    public function testTransportAnswerMentioningDisabledOracleIsDeniedDespiteOwnKillswitchEnabled(): void
    {
        $guard = $this->guard(null, ['world.vehicle.enabled' => true, 'oracle.enabled' => false]);

        $verdict = $guard->verdict(
            'В игре есть транспорт, а ещё Оракул острова.',
            'Что нового в игре?',
            'world.vehicle.enabled',
        );

        $this->assertTrue($verdict->isDeny());
        $this->assertSame('missing_requires_setting', $verdict->reason);
        $this->assertNotNull($verdict->route);
    }

    /**
     * Story 30, acceptance: «перевёрнутая пара» — первый по порядку константы
     * `DORMANT_SUBSYSTEM_MARKERS` маркер («оракул») жив, а упомянутый следом
     * («карава») выключен. До фикса `matchedDormantSubsystem()` (единственное
     * число) возвращал только «оракул» (он первый в списке), проверка проходила
     * (Оракул жив), и «карава» до сверки killswitch'а не доходил вовсе — держалось
     * это только порядком констант, не смыслом.
     */
    public function testReversedDormantPairFirstMarkerLiveSecondDisabledIsDenied(): void
    {
        $guard = $this->guard(null, ['oracle.enabled' => true, 'caravan.enabled' => false]);

        $verdict = $guard->verdict(
            'Оракул острова работает, а караваны ходят между поселениями.',
            'Что нового на острове?',
            null,
        );

        $this->assertTrue($verdict->isDeny(), 'выключенный караван не должен маскироваться живым Оракулом');
        $this->assertSame('missing_requires_setting', $verdict->reason);
        $this->assertNotNull($verdict->route);
    }

    // ── Стоп-темы: узкое сужение не режет полезное ──────────────────────────

    public function testBugReportQuestionIsQuittedNotDenied(): void
    {
        $verdict = $this->guard()->verdict(
            'Не знаю точно. Передал вопрос разработчику.',
            'У меня баг: после крафта пропал предмет.',
            null,
        );

        $this->assertTrue($verdict->isManual(), 'баг-репорт не молчит и не деньится — квитируется и эскалируется');
        $this->assertSame('bug_report_topic', $verdict->reason);
    }

    /**
     * Story 21, acceptance: «баг» — короткий корень, substring-матч ловил и
     * «багаж» (случайно начинается с тех же трёх букв, другое слово). Ответ
     * не должен уходить в манual-очередь баг-репорта на пустом месте.
     */
    public function testLuggageMentionDoesNotTriggerBugMarker(): void
    {
        $verdict = $this->guard()->verdict(
            'Ночной дозор находит редкие ресурсы значительно активнее обычного.',
            'У меня багаж полный, что с ним делать?',
            null,
        );

        $this->assertFalse($verdict->isManual(), '«багаж» не должен опознаваться как баг-репорт');
        $this->assertNotSame('bug_report_topic', $verdict->reason);
    }

    // ── Инвариант: любой deny несёт маршрут ─────────────────────────────────

    public function testEveryDenyCarriesARoute(): void
    {
        $denies = [
            $this->guard()->verdict('Лаборатория окупается быстрее Мастерской.', 'Что лучше строить?', null),
            $this->guard()->verdict('Не знаю точно. Передал вопрос разработчику.', 'Уворот же упирается в 75, да?', null),
            $this->guard()->verdict('Оракул острова принимает ставки.', 'Что такое Оракул?', null),
            $this->guard(null, ['world.vehicle.enabled' => false])->verdict('Транспорт есть в игре.', 'Есть транспорт?', 'world.vehicle.enabled'),
            // ADR-178: провенанс лишён права вето, поэтому пятая фикстура — не
            // «не в корпусе» (теперь allow+advisory), а `comparative_claim`
            // (единственный рубеж 1, сохранивший вето).
            $this->guard()->verdict('Дрон находит узлы охотнее, чем в лесу.', 'Расскажи про механику.', null),
        ];

        foreach ($denies as $verdict) {
            $this->assertTrue($verdict->isDeny(), 'фикстура обязана быть deny для этого теста');
            $this->assertNotNull($verdict->route, 'deny без маршрута запрещён как класс');
            $this->assertNotSame('', trim($verdict->route));
        }
    }

    public function testDenyRouteComesFromApprovedCommunityVoiceCanon(): void
    {
        $verdict = $this->guard()->verdict('Лаборатория окупается быстрее Мастерской.', 'Что лучше строить?', null);

        $allApprovedRoutes = array_merge(CommunityVoice::REFUSAL_WITH_ROUTE, CommunityVoice::STOP_TOPIC);
        $this->assertContains($verdict->route, $allApprovedRoutes);
    }

    // ── Verdict — структурный инвариант ─────────────────────────────────────

    public function testVerdictDenyCannotBeConstructedWithoutRoute(): void
    {
        $reflection = new \ReflectionMethod(Verdict::class, 'deny');
        $routeParam = $reflection->getParameters()[1];

        $this->assertFalse($routeParam->allowsNull(), '`deny()` обязан требовать непустой маршрут по типу, не по соглашению');
        $this->assertSame('string', (string) $routeParam->getType());
    }

    /** ADR-178: `Verdict::allow()` без аргумента даёт пустые пометки; переданный список сохраняется как есть. */
    public function testVerdictAllowCarriesAdvisoriesDefaultingToEmpty(): void
    {
        $bare = Verdict::allow();
        $this->assertSame([], $bare->advisories);

        $advised = Verdict::allow(['пометка А', 'пометка Б']);
        $this->assertSame(['пометка А', 'пометка Б'], $advised->advisories);
    }

    /** ADR-178: `deny()`/`manual()` не несут пометок — у отказа своя причина, добавлять к ней нечего. */
    public function testVerdictDenyAndManualCarryNoAdvisories(): void
    {
        $this->assertSame([], Verdict::deny('reason', 'route')->advisories);
        $this->assertSame([], Verdict::manual('reason')->advisories);
    }

    // ── Не зависит от GameBalance ────────────────────────────────────────────

    public function testCommunityGuardHasNoGameBalanceInDependencies(): void
    {
        $source = file_get_contents(APPPATH . 'Services/Community/CommunityGuard.php');
        $this->assertIsString($source);

        // Сканируем КОД, не докблоки/комментарии — сам класс обязан пояснять этот
        // инвариант текстом (в т.ч. называя `Config\GameBalance`), это не нарушение.
        $codeOnly = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $codeOnly .= is_array($token) ? $token[1] : $token;
        }

        $this->assertStringNotContainsString('GameBalance', $codeOnly, 'CommunityGuard не имеет права зависеть от Config\\GameBalance');
    }

    // ── Story 65 — калибровка судит зафиксированный корпус-эталон ───────────

    /**
     * Story 65 (ADR-177 §5а): PHPUnit больше не меряет живой `GuideCatalog`/
     * `game_tips` (story 38/47/63's `testCalibrationMeasuresBothErrorRates…`
     * печатала благополучные числа на 32 фрагментах вместо боевых 133 и НЕ
     * ЗНАЛА об этом — ровно это дважды пропустило BLOCK). Эталон —
     * `tests/_support/Community/provenance-corpus.php`, срез боевого корпуса на
     * дату 2026-08-26. Живой корпус мерит отдельная spark-команда (story 66),
     * не PHPUnit.
     *
     * Состав утверждается ПОИМЁННО, не только числом — расхождение даже при
     * совпавших счётчиках (напр. подменили один tip на другой того же
     * количества) обязано красить тест.
     */
    public function testProvenanceCorpusFixtureHasExpectedComposition(): void
    {
        $fixture = $this->provenanceCorpusFixture();

        $sources = array_column($fixture['corpus'], 'source');
        sort($sources);
        $expected = self::PROVENANCE_CORPUS_EXPECTED_SOURCES;
        sort($expected);
        $this->assertSame($expected, $sources, 'состав эталонного корпуса разошёлся с зафиксированным списком источников');

        $byKind = [];
        foreach ($fixture['corpus'] as $fragment) {
            $kind = explode(':', $fragment['source'])[0];
            $byKind[$kind] = ($byKind[$kind] ?? 0) + 1;
        }
        $this->assertSame(['guide' => 32, 'tip' => 28], $byKind, 'эталон обязан быть 32 раздела /guide + 28 советов');
        $this->assertCount(32, $fixture['guideBodies'], 'guideBodies обязаны покрывать все 32 раздела');
    }

    /**
     * Story 65 — три выборки на замороженном корпусе-эталоне, каждая строится
     * ДЕТЕРМИНИРОВАННО, без обратной связи от классификатора:
     *
     *  1. **Дословная цитата** — по одному предложению на раздел `/guide` (32),
     *     первое предложение `guideBodies[$key]` (ТЕЛО без заголовка — story
     *     65's находка: заголовок с двоеточием типа «📋 Дела: квесты и задания
     *     дня» иначе ломает нарезку на предложения, `sentencesOf()` режет и по
     *     «:») с ≥6 значимыми стемами. Не «пока не дойдёт до рубежа»
     *     (Non-goals story 65, ровно то, чем грешила `firstProvenanceReachableSentenceOf()`)
     *     — предложение берётся ПЕРВОЕ подходящее по длине, независимо от
     *     того, что оно вызовет у гварда.
     *  2. **Пересказ** — та же цитата минус каждое третье значимое слово
     *     (известный оптимизм этого метода — см. `## Findings` story 63/65).
     *  3. **Фабрикат** — `NON_COMPARATIVE_FABRICATED_SAMPLE` (22, story 63),
     *     НЕ старые 29 сравнительных (Non-goals: они отсекаются раньше рубежа
     *     1 после R1-R4 и мерили бы не тот рубеж).
     *
     * ADR-178: провенанс не денит — «ложного пропуска по провенансу» не
     * существует. Меряются: (а) ложный отказ рубежа ФОРМЫ (`comparative_claim`)
     * на дословной цитате, коридор ≤10%, отклонённые фрагменты — поимённо; (б)
     * шумность пометки (доля `advisory` непустых) на дословной и на пересказе;
     * (в) число фабрикатов, прошедших БЕЗ пометки вовсе — обязательная величина
     * (доказательство инварианта ADR-178 «пометки нет → не значит ничего»),
     * поимённо, без гейта на долю.
     */
    public function testCalibrationOnFrozenProvenanceCorpusFixture(): void
    {
        $fixture = $this->provenanceCorpusFixture();

        // Килсвитчи спящих подсистем включены — замер соответствует
        // прод-конфигурации, а не стенду, где все они читаются выключенными
        // (story 47).
        $liveSettings = array_fill_keys(array_values(CommunityStopTopics::DORMANT_SUBSYSTEM_SETTINGS), true);
        $guard        = $this->guard($fixture['corpus'], $liveSettings);

        $verbatim = $this->verbatimSampleFromGuideBodies($guard, $fixture['guideBodies']);
        $this->assertCount(32, $verbatim, 'дословная выборка обязана покрывать все 32 раздела эталона');

        $paraphrase = $this->paraphraseOf($verbatim);
        $this->assertNotEmpty($paraphrase);

        $fabrications = self::NON_COMPARATIVE_FABRICATED_SAMPLE;

        // ── (а) дословная цитата: ложный отказ рубежа формы, поимённо ──
        $verbatimDeniedByForm = [];
        $verbatimByReason     = [];
        $verbatimNoisy        = 0;
        foreach ($verbatim as $source => $sentence) {
            $verdict = $guard->verdict($sentence, 'Расскажи про механику.', null);
            $reason  = $verdict->isAllow() ? 'allow' : $verdict->reason;
            $verbatimByReason[$reason] = ($verbatimByReason[$reason] ?? 0) + 1;
            if ($reason === 'comparative_claim') {
                $verbatimDeniedByForm[] = sprintf('%s: «%s»', $source, $sentence);
            }
            if ($verdict->isAllow() && $verdict->advisories !== []) {
                $verbatimNoisy++;
            }
        }
        $formRejectionRate = count($verbatimDeniedByForm) / count($verbatim);

        // ── (б) пересказ: та же шумность пометки, для сравнения с (а) ──
        $paraphraseNoisy = 0;
        foreach ($paraphrase as $sentence) {
            $verdict = $guard->verdict($sentence, 'Расскажи про механику.', null);
            if ($verdict->isAllow() && $verdict->advisories !== []) {
                $paraphraseNoisy++;
            }
        }

        // ── (в) фабрикат: сколько проходит БЕЗ пометки вовсе, поимённо ──
        $fabricationsWithoutAdvisory = [];
        $fabricationsByReason        = [];
        foreach ($fabrications as $fabrication) {
            $verdict = $guard->verdict($fabrication, 'Расскажи про механику.', null);
            $reason  = $verdict->isAllow() ? 'allow' : $verdict->reason;
            $fabricationsByReason[$reason] = ($fabricationsByReason[$reason] ?? 0) + 1;
            if ($verdict->isAllow() && $verdict->advisories === []) {
                $fabricationsWithoutAdvisory[] = $fabrication;
            }
        }

        fwrite(STDERR, sprintf(
            "\n[CommunityGuard story 65, замороженный корпус-эталон] "
                . "ложный отказ рубежа формы (дословно)=%.1f%% (%d/%d): %s\n"
                . "  шумность пометки: дословно=%d/%d, пересказ=%d/%d\n"
                . "  дословная сторона по причине: %s\n"
                . "  фабрикатная сторона по причине: %s\n"
                . "  фабрикаты БЕЗ пометки вовсе (%d/%d, поимённо — инвариант «пометки нет → не значит ничего»): %s\n",
            $formRejectionRate * 100,
            count($verbatimDeniedByForm),
            count($verbatim),
            $verbatimDeniedByForm === [] ? '—' : implode(' | ', $verbatimDeniedByForm),
            $verbatimNoisy,
            count($verbatim),
            $paraphraseNoisy,
            count($paraphrase),
            json_encode($verbatimByReason, JSON_UNESCAPED_UNICODE),
            json_encode($fabricationsByReason, JSON_UNESCAPED_UNICODE),
            count($fabricationsWithoutAdvisory),
            count($fabrications),
            $fabricationsWithoutAdvisory === [] ? '—' : implode(' | ', $fabricationsWithoutAdvisory),
        ));

        // Acceptance: коридор ≤10% по comparative_claim на дословной цитате —
        // проверяется утверждением, не только печатается. Рост лечится правкой
        // источника (переписать сравнительно-оценочное предложение в
        // GuideCatalog), а не ослаблением правила (ADR-177) — фрагменты уже
        // названы поимённо выше и в сообщении assert.
        $this->assertLessThanOrEqual(
            0.10,
            $formRejectionRate,
            'ложный отказ рубежа формы на дословной цитате вышел за коридор ADR (≤10%): '
                . implode(' | ', $verbatimDeniedByForm),
        );
    }

    /**
     * Story 78: третья калибровочная выборка. Обе первые (дословная цитата
     * /guide, её пересказ) — связная проза справочника; ни одна не
     * структурно похожа на то, что бот реально отвечает игроку — короткий
     * ответ в тоне владельца по механике. Отдельная выборка нужна потому,
     * что story 73 (R5) прошла коридор 2/32 на прозе и всё равно денила
     * рабочие фразы этого жанра — коридор на прозе не мог увидеть класс
     * ошибок, который затрагивает именно короткие фразы.
     */
    private const OWNER_TONE_SHORT_ANSWER_SAMPLE = [
        'Здоровье восстанавливается, пока ты на базе.',
        'Теплицу можно поставить только на своей базе.',
        'Верстак общий открывает базовые рецепты сразу.',
        'Раны едой не лечатся — нужна аптечка.',
        'Оракул принимает ставки на исход событий.',
        'Задания дня обновляются каждые сутки.',
        'Позвать друга можно реферальной ссылкой.',
        'Топ считает живых игроков отдельно от легенд.',
        'Узел стоит осмотреть перед боем.',
        'Готовка тратит ингредиенты из инвентаря.',
        'Транспорт открывается с шестого уровня.',
        'Постройки ставятся только на своей базе.',
        'Квесты выдаются в разделе Дела.',
        'Маяки нужны для быстрых прыжков.',
    ];

    /**
     * Story 78, обязательный замер: ложный отказ рубежа формы на короткой
     * выборке в тоне владельца — отдельно от коридора story 65 на прозе
     * `/guide`. 🔴 Если бы ставка была заметно выше, чем на прозе — задача
     * требовала бы не подкрутки правил, а эскалации архитектору (ADR-178,
     * поправка №5); измеренное значение печатается поимённо ниже, а
     * assert фиксирует, что единственное совпадение — легитимный улов R3
     * («стоит» + инфинитив = форма совета), а не ложное срабатывание.
     */
    public function testCalibrationOnOwnerToneShortAnswerSample(): void
    {
        $fixture      = $this->provenanceCorpusFixture();
        $liveSettings = array_fill_keys(array_values(CommunityStopTopics::DORMANT_SUBSYSTEM_SETTINGS), true);
        $guard        = $this->guard($fixture['corpus'], $liveSettings);

        $sample       = self::OWNER_TONE_SHORT_ANSWER_SAMPLE;
        $deniedByForm = [];
        $byReason     = [];
        foreach ($sample as $answer) {
            $verdict = $guard->verdict($answer, 'Расскажи про механику.', null);
            $reason  = $verdict->isAllow() ? 'allow' : $verdict->reason;
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
            if ($reason === 'comparative_claim') {
                $deniedByForm[] = $answer;
            }
        }
        $rate = count($deniedByForm) / count($sample);

        fwrite(STDERR, sprintf(
            "\n[CommunityGuard story 78, третья выборка — тон владельца] "
                . "ложный отказ рубежа формы=%.1f%% (%d/%d): %s\n"
                . "  по причине: %s\n",
            $rate * 100,
            count($deniedByForm),
            count($sample),
            $deniedByForm === [] ? '—' : implode(' | ', $deniedByForm),
            json_encode($byReason, JSON_UNESCAPED_UNICODE),
        ));

        // Единственное совпадение обязано быть «Узел стоит осмотреть перед
        // боем.» — легитимный R3 (форма совета «стоит + инфинитив»), а не
        // накопление новых ложных срабатываний. Именованная проверка вместо
        // голого счётчика — иначе будущая регрессия молча спрячется под тем
        // же числом.
        $this->assertSame(
            ['Узел стоит осмотреть перед боем.'],
            $deniedByForm,
            'третья выборка обязана денить ровно этот один легитимный R3-улов, не больше и не меньше',
        );

        // Ставка на короткой выборке (тон владельца) не заметно выше, чем на
        // прозе /guide (story 65, 2/32 ≈ 6.2%) — иначе по правилу story 78
        // требовалась бы эскалация архитектору, а не правка регулярок здесь.
        $this->assertLessThanOrEqual(
            0.15,
            $rate,
            'ложный отказ на короткой выборке заметно выше, чем на прозе — вопрос к архитектору, не подкрутка',
        );
    }

    /**
     * Story 65: `guideBodies[$key]` — ТЕЛО раздела БЕЗ заголовка (см. докблок
     * фикстуры). Первое предложение с ≥6 значимыми стемами, взятое КАК ЕСТЬ —
     * без учёта того, что оно вызовет у гварда (Non-goals story 65: замер не
     * строится методом «перебирай, пока не дойдёт до рубежа»).
     *
     * @param array<string, string> $guideBodies
     * @return array<string, string> ключ — `guide:<key>`, значение — предложение
     */
    private function verbatimSampleFromGuideBodies(CommunityGuard $guard, array $guideBodies): array
    {
        $reflection    = new \ReflectionClass($guard);
        $sentencesOf   = $reflection->getMethod('sentencesOf');
        $sentencesOf->setAccessible(true);
        $stemsOrdered  = $reflection->getMethod('stemsOrdered');
        $stemsOrdered->setAccessible(true);

        $sample = [];
        foreach ($guideBodies as $key => $body) {
            foreach ($sentencesOf->invoke($guard, $body) as $sentence) {
                $stems = array_unique($stemsOrdered->invoke($guard, $sentence));
                if (count($stems) < 6) {
                    continue;
                }
                $sample['guide:' . $key] = $sentence;
                break;
            }
        }

        return $sample;
    }

    /**
     * Добросовестный пересказ: та же цитата минус каждое третье значимое
     * слово (тот же метод, что ADR-177/story 63) — известный оптимизм:
     * подмножество совпавшего юнита не может дать МЕНЬШЕЕ покрытие себя же,
     * так что этот метод systematически завышает шумность пересказа с
     * синонимами, которого не проверяет ни один из этих замеров.
     *
     * @param array<string, string> $verbatim
     * @return list<string>
     */
    private function paraphraseOf(array $verbatim): array
    {
        $paraphrase = [];
        foreach ($verbatim as $sentence) {
            preg_match_all('/\p{L}{2,}/u', $sentence, $matches);
            $words = $matches[0];
            if (count($words) < 6) {
                continue;
            }
            $kept = [];
            foreach ($words as $i => $word) {
                if ($i % 3 !== 2) {
                    $kept[] = $word;
                }
            }
            $paraphrase[] = implode(' ', $kept) . '.';
        }

        return $paraphrase;
    }

    /**
     * @return array{corpus: list<array{source: string, text: string}>, guideBodies: array<string, string>}
     */
    private function provenanceCorpusFixture(): array
    {
        /** @var array{corpus: list<array{source: string, text: string}>, guideBodies: array<string, string>} $fixture */
        $fixture = require SUPPORTPATH . 'Community/provenance-corpus.php';

        return $fixture;
    }

    /**
     * Story 69 — печатает СРАБОТАВШЕЕ слово `NUMERAL_WORDS`/`LEXICAL_STOPLIST`,
     * а не только причину `lexical_stoplist`. Вызывает ту же приватную
     * `CommunityGuard::matchesStopWord()` через reflection — не дублирует
     * логику совпадения (дубль рисковал бы разойтись с продом и врать), просто
     * прогоняет её список слов, пока не найдёт первое совпадение. Это и есть
     * инструмент, которым доказана гипотеза story 69, и он же остаётся полезным
     * после фикса — покажет поимённо, если граница слова когда-нибудь сломается.
     */
    private function matchedStopWordOf(CommunityGuard $guard, string $answer): ?string
    {
        $reflection = new \ReflectionClass($guard);
        $matcher    = $reflection->getMethod('matchesStopWord');
        $matcher->setAccessible(true);

        $lower = mb_strtolower($answer);
        $words = array_merge(
            $reflection->getConstant('NUMERAL_WORDS'),
            $reflection->getConstant('LEXICAL_STOPLIST'),
        );
        foreach ($words as $word) {
            if ($matcher->invoke($guard, $lower, $word)) {
                return $word;
            }
        }

        return null;
    }

}

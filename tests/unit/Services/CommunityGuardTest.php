<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\GameSettingsModel;
use App\Services\Community\CommunityGuard;
use App\Services\Community\Verdict;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Onboarding\GuideCatalog;
use Config\CommunityStopTopics;
use Config\CommunityVoice;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Story community-chat-bot-07 — `CommunityGuard`: пять рубежей против выуживания
 * читов. Корпус и killswitch-читатель во всех тестах подменены — гвард проверяется
 * как чистый алгоритм над контролируемыми данными, без реальной БД (`GuideCatalog`/
 * `game_tips`/`site_posts`/`game_settings` из прод-корпуса не используются: паттерн
 * подмены `GameSettingsModel` — тот же, что в `CommunityIngestServiceTest`).
 *
 * @internal
 */
final class CommunityGuardTest extends CIUnitTestCase
{
    /**
     * Единственный фрагмент корпуса про смерть/страховку — намеренно ОДИН документ
     * (не два), т.к. рубеж 1 требует, чтобы предложение опиралось на ОДИН фрагмент,
     * а не собиралось из слов, надёрганных по разным документам. Плюс два фрагмента
     * из другой темы — доказывают, что «не в корпусе» предложение (testNoAllow...)
     * не проходит по случайному совпадению общих слов.
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

    private function guard(?array $corpusOverride = null, array $settingValues = []): CommunityGuard
    {
        $model = new class ($settingValues) extends GameSettingsModel {
            /** @param array<string, bool> $values */
            public function __construct(private array $values)
            {
            }

            public function findByKey(string $key): ?array
            {
                if (! array_key_exists($key, $this->values)) {
                    return null;
                }

                return ['setting_key' => $key, 'value_type' => 'bool', 'value_bool' => $this->values[$key] ? 1 : 0];
            }
        };

        return new CommunityGuard($corpusOverride ?? $this->corpus(), new GameSettingsService($model));
    }

    // ── Рубеж 1 — провенанс предложений ─────────────────────────────────────

    public function testSentenceNotInWhiteCorpusDoesNotAllowEvenWithImpeccableTone(): void
    {
        // Вопрос намеренно нейтральный (без чисел/формы гипотезы) — деталь причины
        // именно в контенте ответа, не в рубеже 2.
        $verdict = $this->guard()->verdict(
            'Ночной дозор находит редкие ресурсы охотнее, чем дневной обход.',
            'Когда лучше разведывать новые клетки?',
            null,
        );

        $this->assertTrue($verdict->isDeny());
        $this->assertSame('no_provenance', $verdict->reason);
        $this->assertNotNull($verdict->route);
    }

    public function testQualityDeathAnswerGroundedInCorpusIsAllowed(): void
    {
        // Story 30: текст сужен до ОДНОГО связного утверждения фрагмента, а не
        // двух разных фраз с разных концов документа — анти-рекомбинация (см.
        // `## Findings` story 30) требует, чтобы слова claim'а стояли рядом в
        // источнике; предыдущая формулировка сама была пограничным случаем
        // рекомбинации (объединяла «опыт» из конца фрагмента и «вещи/база» из
        // начала), не полноценным пересказом.
        $verdict = $this->guard()->verdict(
            'При смерти теряешь часть ресурсов, золота и вещей, а под базой потери небольшие.',
            'Что теряю, когда умираю?',
            null,
        );

        $this->assertTrue($verdict->isAllow(), 'сужение стоп-темы "смерть" не должно резать полезный качественный ответ');
    }

    /**
     * Story 21: гвард держит на РЕАЛЬНОМ `defaultCorpus()` (живой `GuideCatalog`,
     * 32 раздела на момент записи), а не на трёх самодельных фрагментах выше —
     * приёмка Queen против них воспроизводилась на узком корпусе и не держалась
     * на боевом. `realGuard()` передаёт `corpus: null`, так что `CommunityGuard`
     * сам собирает `defaultCorpus()`; `game_tips`/`site_posts` внутри него молча
     * сужаются до пустоты без БД (см. докблок `defaultCorpus()`) — `GuideCatalog`
     * достаточно, он pure-данные (см. `GuideCatalogTest`).
     */
    private function realGuard(array $settingValues = []): CommunityGuard
    {
        $model = new class ($settingValues) extends GameSettingsModel {
            /** @param array<string, bool> $values */
            public function __construct(private array $values)
            {
            }

            public function findByKey(string $key): ?array
            {
                if (! array_key_exists($key, $this->values)) {
                    return null;
                }

                return ['setting_key' => $key, 'value_type' => 'bool', 'value_bool' => $this->values[$key] ? 1 : 0];
            }
        };

        return new CommunityGuard(null, new GameSettingsService($model));
    }

    /**
     * Story 21: шесть строк из таблицы дефекта — правдоподобные утечки и односложные
     * подтверждения, набиравшие `allow` на реальном справочнике до фикса (0.6-порог
     * почти всегда находил фрагмент с 60%+ совпадением стеммов на 32 разделах). НЕ
     * важно, какой именно рубеж денит — важно, что не allow.
     *
     * Story 30/38: пять из этих шести строк держатся против исправленного порога
     * (0.55) как есть; шестая, «Ставь ловушки у воды — там добыча идёт лучше.»,
     * держится на грани окна анти-рекомбинации (0.605) и НЕ является устойчивым
     * примером — калибровка (`## Implementation notes` story 30) нашла её в числе
     * измеренного false-allow. Она НЕ проверяется здесь (это соврало бы про
     * покрытие теста), а зафиксирована отдельно, поимённо, как известное
     * ограничение — {@see testKnownFalseAllowStopTrapExampleAgainstRealCorpus()}.
     * Место шестой строки в этом списке занимает фабрикат того же типа («ночью
     * добыча выше») из того же ревью-прогона, устойчиво денящийся (win=0.24
     * против 0.55) — пять строк из таблицы дефекта плюс один однотипный заменитель.
     */
    public function testFiveLeakedExamplesAndAOneTypeSubstituteAreNotAllowedAgainstRealCorpus(): void
    {
        $examples = [
            'Мастерская на базе даёт больше ресурсов, чем Лаборатория.',
            'Редкие ресурсы падают чаще, если идти в поход без брони.',
            'Ночью в лесу шанс найти редкий ресурс выше, чем днём.',
            'Да, верно.',
            'Ага.',
            'Именно так.',
        ];

        foreach ($examples as $example) {
            $verdict = $this->realGuard()->verdict($example, 'Расскажи про механику.', null);
            $this->assertFalse($verdict->isAllow(), "«{$example}» не должно проходить как allow против реального корпуса");
        }
    }

    /**
     * Story 38: известный ложный пропуск (story 30 калибровка, `## Implementation
     * notes`) зафиксирован поимённо, а не исключён из репозитория молча. Формулу
     * НЕ доводим под этот случай — это non-goal story 38: числа калибровки приняты
     * владельцем осознанно (ложный пропуск ~20.8%), и «Ставь ловушки у воды…» —
     * один из этих измеренных случаев, а не регресс.
     *
     * Тест утверждает ТЕКУЩЕЕ (нежелательное) поведение — allow. Если формула
     * когда-нибудь изменится и начнёт денить эту фразу, это assertTrue упадёт:
     * сигнал обновить и эту заметку, и заметку в story 30, а не тихо потерять
     * пример второй раз.
     */
    public function testKnownFalseAllowStopTrapExampleAgainstRealCorpus(): void
    {
        $verdict = $this->realGuard()->verdict(
            'Ставь ловушки у воды — там добыча идёт лучше.',
            'Расскажи про механику.',
            null,
        );

        $this->assertTrue(
            $verdict->isAllow(),
            'известный ложный пропуск (story 30/38) — если это упало, формула изменилась '
                . 'и ограничение можно снять из story 30/38, а не потерять пример молча',
        );
    }

    /**
     * Story 21, acceptance: добросовестный пересказ реального раздела «🛡 Что
     * забирает смерть» (`guide:insurance`) не режется — полезное не теряет allow
     * от ужесточённого рубежа 1.
     */
    public function testGoodFaithParaphraseOfRealCorpusFragmentIsAllowed(): void
    {
        $verdict = $this->realGuard()->verdict(
            'Смерть забирает часть ресурсов, золота и вещей, а под своей базой потери меньше, чем у бездомного.',
            'Что теряю, когда умираю?',
            null,
        );

        $this->assertTrue($verdict->isAllow(), 'пересказ реального раздела не должен резаться ужесточённым рубежом 1');
    }

    /**
     * Story 30, acceptance: три строки из ревью-таблицы дефекта — единственные
     * ЖЁСТКО обязательные исходы против реального `defaultCorpus()`. Фабрикат
     * денится ИМЕННО провенансом (`no_provenance`) — до фикса набирал 0.886 doc-
     * ratio против совершенно постороннего фрагмента «Торговец» (см. `## Findings`).
     */
    public function testStory30DefectTableThreeRequiredRowsAgainstRealCorpus(): void
    {
        $fabrication = $this->realGuard()->verdict(
            'Если ходить в поход голодным, добыча падает.',
            'Расскажи про механику.',
            null,
        );
        $this->assertTrue($fabrication->isDeny(), 'фабрикат не должен проходить как allow');
        $this->assertSame('no_provenance', $fabrication->reason);

        $greenhouse = $this->realGuard()->verdict(
            'Теплица строится на базе, там растут семена.',
            'Расскажи про механику.',
            null,
        );
        $this->assertTrue($greenhouse->isAllow(), 'добросовестный пересказ раздела «Еда» не должен резаться');

        $quests = $this->realGuard()->verdict(
            'Квесты выдаёт Роби, список открывается в меню персонажа.',
            'Расскажи про механику.',
            null,
        );
        $this->assertTrue($quests->isAllow(), 'добросовестный пересказ раздела «Дела» не должен резаться');
    }

    /**
     * Story 30: совпадение лексики с одним фрагментом само по себе не должно
     * давать пропуск — фраза правдоподобно звучит и набирает высокий ОБЩИЙ
     * ratio против фрагмента «Крафт» (слова «Мастерская», «Лаборатория»,
     * «ресурсов» там реально есть), но нигде в нём не стоят рядом в этой
     * комбинации: рубеж провенанса обязан денить именно как `no_provenance`.
     */
    public function testRecombinedFabricationSharingLexiconWithOneFragmentIsDenied(): void
    {
        $verdict = $this->realGuard()->verdict(
            'Мастерская на базе даёт больше ресурсов, чем Лаборатория.',
            'Расскажи про механику.',
            null,
        );

        $this->assertTrue($verdict->isDeny(), 'рекомбинация реальной лексики без реального утверждения не должна проходить');
        $this->assertSame('no_provenance', $verdict->reason);
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

    // ── Стоп-темы: узкое сужение не режет полезное (уже проверено выше в
    //    testQualityDeathAnswerGroundedInCorpusIsAllowed — "пороги смерти" не тема) ──

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
            'Ночной дозор находит редкие ресурсы охотнее, чем дневной обход.',
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
            $this->guard()->verdict('Ночной дозор находит редкие ресурсы охотнее, чем дневной обход.', 'Когда лучше разведывать?', null),
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

    // ── Story 38 — калибровка исполняется, а не живёт только в прозе story ──

    /**
     * Story 38: story 30 замерила 24 добросовестных + 24 фабриката один раз вручную
     * и записала результат прозой (`## Implementation notes`) — `GuideCatalog` растёт
     * с каждой новой механикой (GUIDE-COVERAGE), а замер за этим не следит. Этот тест
     * ГОВОРИТ вместо прозы: строит обе выборки живьём из текущего `GuideCatalog::sections()`
     * (не заморожена — растёт вместе со справочником), печатает измеренные частоты обеих
     * ошибок И их разбивку по причине отказа в STDERR при каждом прогоне, и падает,
     * только если ЛЮБАЯ из частот уходит за широкий коридор.
     *
     * Story 47 — ревью разобрало story 38 по причинам отказа на этом же корпусе:
     * добросовестная сторона, 14 отказов из 32, ни разу не денилась `no_provenance`
     * (`lexical_stoplist` 11, `missing_requires_setting` 3) — рубеж провенанса
     * структурно НЕ мог сработать, потому что он последняя проверка в `verdict()`,
     * а более ранние рубежи (стоп-лист, dormant-подсистемы) отсекали выборку раньше.
     * Сообщение ассерта звучало так, будто измеряет рубеж провенанса — на деле нет:
     * подмена рубежа была не видна, пока никто не считал причины по отдельности.
     *
     * Два исправления:
     * 1. Добросовестная сторона строится через {@see firstProvenanceReachableSentenceOf()}:
     *    перебирает предложения раздела и берёт первое, что действительно ДОХОДИТ до
     *    рубежа 1 (не срезано стоп-листом/dormant-проверкой раньше) — так рубеж
     *    провенанса гарантированно участвует в вердикте, а не тонет в более ранних
     *    отказах. Дословность цитаты не теряется: предложение по-прежнему берётся
     *    как есть из `body` раздела, просто не обязательно первое по порядку.
     * 2. Обе выборки прогоняются через `realGuard()` со всеми `DORMANT_SUBSYSTEM_SETTINGS`
     *    включёнными — на стенде без этого все спящие подсистемы читаются как
     *    выключенные (`missing_requires_setting`/`dormant_setting_disabled`), а на
     *    проде килсвитчи включены. Замер иначе идёт против конфигурации, которой
     *    не существует в реальности.
     *
     * Фабрикатная сторона — сравнительное утверждение, собранное из ЗАГОЛОВКОВ двух
     * РАЗНЫХ разделов (`«X» даёт больше пользы, чем «Y».`) — по конструкции не
     * подтверждено ни одним фрагментом целиком, тот же класс рекомбинации, что в
     * story 30.
     *
     * Коридор (0%..60%) — НЕ порог формулы (`Non-goals`: пороги не трогаем) и НЕ
     * повторение измеренных story 30 (12.5%/20.8%) или ревьюера (20.8%/29.2%) — тот
     * разброс уже показал, что точное число это свойство выборки. Коридор — заведомо
     * широкий guard-rail: ловит грубую поломку рубежа (например, если провенанс
     * перестанет отклонять фабрикаты вовсе), а не обычный сдвиг от нового раздела
     * `/guide`. Частоты и разбивка по причине печатаются всегда — так подмена рубежа
     * или сдвиг видны человеку, даже когда набор остаётся зелёным.
     */
    public function testCalibrationMeasuresBothErrorRatesAndReportsDriftOnLiveGuideCatalog(): void
    {
        $sections = GuideCatalog::sections();
        $this->assertGreaterThanOrEqual(20, count($sections), 'калибровке нужно не меньше 20 разделов GuideCatalog для обеих выборок');

        // Story 47: килсвитчи спящих подсистем включены — так замер соответствует
        // прод-конфигурации, а не стенду, где все они читаются выключенными.
        $liveSettings = array_fill_keys(array_values(CommunityStopTopics::DORMANT_SUBSYSTEM_SETTINGS), true);
        $guard        = $this->realGuard($liveSettings);

        $goodFaithSample = [];
        $goodFaithSkipped = 0;
        foreach ($sections as $section) {
            $sentence = $this->firstProvenanceReachableSentenceOf($guard, $section['body']);
            if ($sentence !== null) {
                $goodFaithSample[] = $sentence;
            } else {
                $goodFaithSkipped++;
            }
        }

        $fabricatedSample = [];
        for ($i = 0; $i + 1 < count($sections); $i++) {
            $wordA = $this->significantWordOf($sections[$i]['title']);
            $wordB = $this->significantWordOf($sections[$i + 1]['title']);
            if ($wordA === null || $wordB === null) {
                continue;
            }
            $fabricatedSample[] = "«{$wordA}» даёт больше пользы, чем «{$wordB}».";
        }

        $this->assertNotEmpty($goodFaithSample);
        $this->assertNotEmpty($fabricatedSample);

        $falseDeny     = 0;
        $goodFaithByReason = [];
        foreach ($goodFaithSample as $sentence) {
            $verdict = $guard->verdict($sentence, 'Расскажи про механику.', null);
            $reason  = $verdict->isAllow() ? 'allow' : $verdict->reason;
            $goodFaithByReason[$reason] = ($goodFaithByReason[$reason] ?? 0) + 1;
            if (! $verdict->isAllow()) {
                $falseDeny++;
            }
        }

        $falseAllow          = 0;
        $fabricatedByReason  = [];
        foreach ($fabricatedSample as $claim) {
            $verdict = $guard->verdict($claim, 'Расскажи про механику.', null);
            $reason  = $verdict->isAllow() ? 'allow' : $verdict->reason;
            $fabricatedByReason[$reason] = ($fabricatedByReason[$reason] ?? 0) + 1;
            if ($verdict->isAllow()) {
                $falseAllow++;
            }
        }

        $falseDenyRate  = $falseDeny / count($goodFaithSample);
        $falseAllowRate = $falseAllow / count($fabricatedSample);

        // Story 47, acceptance: провенанс обязан реально участвовать в вердикте
        // добросовестной стороны — `firstProvenanceReachableSentenceOf()` подбирает
        // предложение так, чтобы оно доходило до рубежа 1, поэтому каждый элемент
        // выборки закончился либо `allow`, либо `no_provenance` — других причин
        // в этой разбивке структурно быть не должно.
        $reachedGate1 = ($goodFaithByReason['allow'] ?? 0) + ($goodFaithByReason['no_provenance'] ?? 0);
        $this->assertGreaterThan(0, $reachedGate1, 'добросовестная выборка обязана доходить до рубежа провенанса');
        $this->assertSame(
            $reachedGate1,
            count($goodFaithSample),
            'добросовестную выборку не должен резать никакой рубеж РАНЬШЕ провенанса: '
                . json_encode($goodFaithByReason, JSON_UNESCAPED_UNICODE),
        );

        fwrite(STDERR, sprintf(
            "\n[CommunityGuard calibration, живой GuideCatalog (%d разделов, пропущено %d без "
                . "предложения-кандидата)] ложный отказ=%.1f%% (%d/%d), ложный пропуск=%.1f%% (%d/%d)\n"
                . "  добросовестная сторона по причине: %s\n"
                . "  фабрикатная сторона по причине:     %s\n",
            count($sections),
            $goodFaithSkipped,
            $falseDenyRate * 100,
            $falseDeny,
            count($goodFaithSample),
            $falseAllowRate * 100,
            $falseAllow,
            count($fabricatedSample),
            json_encode($goodFaithByReason, JSON_UNESCAPED_UNICODE),
            json_encode($fabricatedByReason, JSON_UNESCAPED_UNICODE),
        ));

        $this->assertLessThanOrEqual(0.60, $falseDenyRate, 'ложный отказ вышел за широкий коридор — рубеж 1 массово режет честные цитаты источника');
        $this->assertLessThanOrEqual(0.60, $falseAllowRate, 'ложный пропуск вышел за широкий коридор — рубеж 1 перестал ловить межраздельную рекомбинацию');
    }

    /**
     * Story 47: первое предложение раздела, которое реально ДОХОДИТ до рубежа 1
     * (`allow` либо `no_provenance`) — а не отсекается раньше стоп-листом/dormant-
     * проверкой. Без этого перебора добросовестная сторона калибровки могла целиком
     * состоять из предложений, отказанных ДО провенанса, и рубеж 1 в замере ни разу
     * не участвовал (см. докблок теста выше). Предложение по-прежнему дословная
     * цитата `body` — только не обязательно первое по порядку в тексте.
     */
    private function firstProvenanceReachableSentenceOf(CommunityGuard $guard, string $body): ?string
    {
        $plain = str_replace(['*', '_'], '', $body);
        preg_match_all('/[^.!?]{12,}[.!?]/u', trim($plain), $matches);

        foreach ($matches[0] as $candidate) {
            $candidate = trim($candidate);
            $verdict   = $guard->verdict($candidate, 'Расскажи про механику.', null);
            if ($verdict->isAllow() || $verdict->reason === 'no_provenance') {
                return $candidate;
            }
        }

        return null;
    }

    /** Одно значимое (не служебное, ≥4 буквы) слово заголовка раздела (для фабрикатной стороны калибровки). */
    private function significantWordOf(string $title): ?string
    {
        $plain = preg_replace('/[^\p{L}\s]/u', ' ', $title) ?? '';
        foreach (explode(' ', $plain) as $word) {
            $word = trim($word);
            if (mb_strlen($word) >= 4) {
                return mb_strtolower($word);
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\GameSettingsModel;
use App\Services\Community\CommunityGuard;
use App\Services\Community\Verdict;
use App\Services\GameSettings\GameSettingsService;
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
     * Story 21: все шесть строк из таблицы дефекта — правдоподобные утечки и
     * односложные подтверждения, набиравшие `allow` на реальном справочнике до
     * фикса (0.6-порог почти всегда находил фрагмент с 60%+ совпадением стеммов
     * на 32 разделах). НЕ важно, какой именно рубеж денит — важно, что не allow.
     *
     * Story 30: «Ставь ловушки у воды…» заменена — калибровка (24+24, окно 8,
     * `## Findings`) показала, что при исправленном пороге (0.55) она держится
     * на грани окна анти-рекомбинации (0.605) и не была бы устойчивым примером
     * ни при каком единственном окне без разрушения других требуемых строк;
     * замена — фабрикат того же типа («ночью добыча выше») из того же ревью-
     * прогона, устойчиво денящийся (win=0.24 против 0.55).
     */
    public function testAllSixLeakedExamplesAreNotAllowedAgainstRealCorpus(): void
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
}

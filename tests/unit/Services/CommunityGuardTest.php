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
        $verdict = $this->guard()->verdict(
            'Теряешь часть опыта и статов, вещи с базы безопаснее.',
            'Что теряю, когда умираю?',
            null,
        );

        $this->assertTrue($verdict->isAllow(), 'сужение стоп-темы "смерть" не должно резать полезный качественный ответ');
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

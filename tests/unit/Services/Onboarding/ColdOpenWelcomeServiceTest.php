<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\ColdOpenGreetingService;
use App\Services\Onboarding\ColdOpenWelcomeService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * web-onboarding-01: веб-модель ядра первого экрана. Паритет бота — в StartCommandColdOpenParityTest.
 *
 * @internal
 */
final class ColdOpenWelcomeServiceTest extends CIUnitTestCase
{
    private const KIT    = "🎒 *Аварийный паёк.*\n\nВ рюкзаке:\n• 💧 Вода ×10";
    private const SIGNAL = '📡 *Сигнал в эфире.* Радио на волне.';
    private const GREET  = '🤝 *Встречающий.* Кто-то ждёт у дороги.';

    /** @return array{spawned: bool, singleScreen: bool, starterKit: ?string, signal: ?string, greeter: ?string} */
    private function texts(bool $spawned = true): array
    {
        return ['spawned' => $spawned, 'singleScreen' => false, 'starterKit' => self::KIT, 'signal' => self::SIGNAL, 'greeter' => self::GREET];
    }

    /** @return list<array{role: string, text: string, parse_mode: ?string, inline_keyboard: list<list<array{text: string, callback_data: string}>>}> */
    private function web(array $texts): array
    {
        return (new ColdOpenWelcomeService())->build(ColdOpenWelcomeService::CLIENT_WEB, $texts);
    }

    public function testWebCarriesKitSignalGreeterAndClosingInBotOrder(): void
    {
        $model = $this->web($this->texts());
        $all   = implode("\n", array_column($model, 'text'));

        $this->assertNotFalse(strpos($all, self::KIT));
        $this->assertNotFalse(strpos($all, self::SIGNAL));
        $this->assertNotFalse(strpos($all, self::GREET));
        $this->assertLessThan(strpos($all, self::SIGNAL), strpos($all, self::KIT));
        $this->assertLessThan(strpos($all, self::GREET), strpos($all, self::SIGNAL));
        $this->assertLessThan(strpos($all, 'Сделать первый шаг'), strpos($all, self::GREET));
        $this->assertStringContainsString('Роби', $all, 'приветствие Роби в веб-модели');
        $this->assertSame('welcome', $model[array_key_last($model)]['role']);
    }

    public function testWebHasExactlyOneFirstStepButtonAndNothingElse(): void
    {
        $buttons = [];
        foreach ($this->web($this->texts()) as $msg) {
            $this->assertNotSame('menu_carrier', $msg['role']);
            foreach ($msg['inline_keyboard'] as $row) {
                foreach ($row as $btn) {
                    $buttons[] = $btn;
                }
            }
        }

        $this->assertSame([['text' => '🧭 Сделать первый шаг', 'callback_data' => 'move']], $buttons);

        $all = json_encode($this->web($this->texts()), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Назвать героя', (string) $all);
        $this->assertStringNotContainsString('Задать имя', (string) $all);
    }

    public function testWebWithoutSpawnIsTheBotError(): void
    {
        $model = $this->web($this->texts(false));

        $this->assertCount(1, $model);
        $this->assertSame('error', $model[0]['role']);
        $this->assertStringStartsWith('Извините, произошла ошибка при попытке определить локацию для спавна.', $model[0]['text']);
        $this->assertSame([], $model[0]['inline_keyboard']);
    }

    public function testWebTextsHaveNoBotLexicon(): void
    {
        $variants = [
            $this->texts(),
            ['spawned' => true, 'singleScreen' => true, 'starterKit' => null, 'signal' => null, 'greeter' => null],
            ['spawned' => true, 'singleScreen' => false, 'starterKit' => str_repeat('Длинный паёк. ', 400), 'signal' => self::SIGNAL, 'greeter' => null],
        ];

        foreach ($variants as $texts) {
            foreach ($this->web($texts) as $msg) {
                $this->assertDoesNotMatchRegularExpression('/ниже|нижн\w* меню|команд\w* \/|\/start|\/menu|клавиатур/iu', $msg['text']);
            }
        }
    }

    public function testWebDropsBotNavTailAddedWhenBotSingleScreenIsOff(): void
    {
        // Форма ColdOpenSignalService / NewbieGreeterService при withNavHint = !single_screen (флаг по умолчанию 0).
        $texts = [
            'spawned'    => true,
            'singleScreen' => false,
            'starterKit' => self::KIT,
            'signal'     => self::SIGNAL . "\n\n_Жми «🌍 Мир» внизу экрана и иди к метке 🎯._",
            'greeter'    => self::GREET . "\n\n_Жми «🌍 Мир» внизу экрана, дойди до выжившего и нажми «👤 Незнакомец»._",
        ];

        foreach ([$texts, ['starterKit' => str_repeat('Длинный паёк. ', 400)] + $texts] as $variant) {
            $all = implode("\n", array_column($this->web($variant), 'text'));
            $this->assertDoesNotMatchRegularExpression('/ниже|нижн\w* меню/iu', $all);
            $this->assertStringContainsString(self::SIGNAL, $all);
            $this->assertStringContainsString(self::GREET, $all);
        }
    }

    public function testWebIsMediaOffAndMarkdownSafe(): void
    {
        $variants = [
            $this->texts(),
            ['spawned' => true, 'singleScreen' => false, 'starterKit' => str_repeat('Длинный паёк. ', 150), 'signal' => str_repeat('Длинный сигнал. ', 150), 'greeter' => self::GREET],
        ];

        foreach ($variants as $texts) {
            foreach ($this->web($texts) as $msg) {
                $this->assertSame(['role', 'text', 'parse_mode', 'inline_keyboard'], array_keys($msg), 'в модели нет фото/картинок');
                $this->assertSame(0, substr_count($msg['text'], '*') % 2, 'непарные *');
                $this->assertSame(0, substr_count($msg['text'], '_') % 2, 'непарные _');
                $this->assertLessThanOrEqual(3900, mb_strlen($msg['text']), 'сообщение влезает в Telegram-лимит');
            }
        }
    }

    public function testWebOversizedContentFallsBackToSeparateMessagesWithButtonLast(): void
    {
        $texts               = $this->texts();
        $texts['starterKit'] = '🎒 *Паёк.* ' . str_repeat('Очень длинный текст. ', 200);
        $model               = $this->web($texts);

        $this->assertSame(['kit', 'signal', 'greeter', 'welcome'], array_column($model, 'role'));
        $this->assertSame('move', $model[3]['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame([], $model[0]['inline_keyboard']);
    }

    // ── e-first-01: одна нижняя кнопка первого шага ──────────────────────

    /** @return list<array<string, mixed>> */
    private function bot(bool $firstStep, bool $singleScreen = true): array
    {
        $texts                 = $this->texts();
        $texts['singleScreen'] = $singleScreen;

        return (new ColdOpenWelcomeService(new FirstStepFlagGreeting($firstStep)))->build(ColdOpenWelcomeService::CLIENT_BOT, $texts);
    }

    /** @param list<array<string, mixed>> $model */
    private static function carrier(array $model): array
    {
        foreach ($model as $msg) {
            if ($msg['role'] === 'menu_carrier') {
                return $msg;
            }
        }
        self::fail('в модели бота нет носителя нижней клавиатуры');
    }

    public function testBotCarrierHoldsOnlyFirstStepButtonWhenFlagOn(): void
    {
        foreach ([true, false] as $singleScreen) {
            $carrier = self::carrier($this->bot(true, $singleScreen));

            $this->assertSame(
                [['🧭 Сделать первый шаг']],
                $carrier['reply_keyboard'] ?? null,
                'при ON носитель снова несёт полное меню'
            );
            $this->assertDoesNotMatchRegularExpression('/ниже|внизу|нижн\w* меню|клавиатур/iu', (string) $carrier['text']);
            $this->assertNotSame('', trim((string) $carrier['text']), 'Telegram не вешает клавиатуру на пустой текст');
        }
    }

    public function testBotCarrierKeepsMainMenuWhenFlagOff(): void
    {
        $carrier = self::carrier($this->bot(false));

        $this->assertArrayNotHasKey('reply_keyboard', $carrier, 'при OFF носитель несёт полное меню, как раньше');
        $this->assertSame((new FirstStepFlagGreeting(false))->menuAttachText(), $carrier['text']);
    }

    public function testRobiScreenUnchangedByFirstStepFlag(): void
    {
        $on  = $this->bot(true);
        $off = $this->bot(false);

        $this->assertSame(array_slice($off, 1), array_slice($on, 1), 'экран Роби и его inline-кнопки не меняются');
    }

    public function testFirstStepTextIsRecognisedInAnyCase(): void
    {
        $this->assertTrue(ColdOpenWelcomeService::isFirstStepText('🧭 Сделать первый шаг'));
        $this->assertTrue(ColdOpenWelcomeService::isFirstStepText('🧭 сделать первый шаг'));
        $this->assertTrue(ColdOpenWelcomeService::isFirstStepText('  🧭 СДЕЛАТЬ ПЕРВЫЙ ШАГ '));
        $this->assertFalse(ColdOpenWelcomeService::isFirstStepText('сделать первый шаг'), 'без значка — не нажатие кнопки');
        $this->assertFalse(ColdOpenWelcomeService::isFirstStepText('🌍 Мир'));
    }
}

/** @internal */
final class FirstStepFlagGreeting extends ColdOpenGreetingService
{
    public function __construct(private bool $firstStep)
    {
    }

    protected function gsBool(string $key, bool $default): bool
    {
        return match ($key) {
            'onboarding.cold_open_v2.first_step_keyboard' => $this->firstStep,
            'onboarding.cold_open_v2.start_greeting', 'onboarding.cold_open_v2.menu_defer' => true,
            default => throw new \LogicException("Неожиданный флаг {$key}"),
        };
    }
}

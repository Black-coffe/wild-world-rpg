<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\ColdOpenGreetingService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\CallbackRoutes;

/**
 * Слайс «Первые 3 минуты» (замер 2026-07-24) — первый контакт одним окном.
 *
 * Замер когорты Хабра 07–08.07 (45 человек): живы через две недели 3, 42 из 45 на L1,
 * лишь 40% нажали единственную кнопку welcome, 18 из 26 ушедших уложились в ≤3 минуты.
 * Сокращение копии (слайс 1b) метрику не сдвинуло → чиним ФОРМУ первого контакта.
 *
 * Центральные тесты здесь — не про текст, а про два обещания, которые легко сломать
 * молча: (1) killswitch по умолчанию OFF (иначе копия уедет на прод без одобрения),
 * (2) кнопка «первый шаг» ведёт в ЖИВОЙ роут и называется в копии ровно так же, как
 * на кнопке (иначе экран врёт игроку — урок мёртвых кнопок / lying-контента).
 *
 * @internal
 */
final class ColdOpenSingleScreenTest extends CIUnitTestCase
{
    /** Метка кнопки первого CTA — единый источник для копии, кнопки и этого теста. */
    private const FIRST_STEP_LABEL = '🧭 Сделать первый шаг';

    private const START_COMMAND = APPPATH . 'Controllers/Telegram/Commands/StartCommand.php';

    /** Флаг читается СВОИМ ключом и по умолчанию выключен (dormant-обещание). */
    public function testSingleScreenReadsOwnKeyAndDefaultsOff(): void
    {
        $svc = new SingleScreenFlagSpy();

        $this->assertFalse($svc->singleScreenEnabled(), 'default обязан быть OFF');
        $this->assertSame('onboarding.cold_open_v2.single_screen', $svc->lastKey);
        $this->assertFalse($svc->lastDefault, 'default-аргумент обязан быть false');

        $svc->value = true;
        $this->assertTrue($svc->singleScreenEnabled());
    }

    /** Секции склеиваются через пустую строку; отсутствующие (dormant) — выпадают. */
    public function testComposeWelcomeSkipsMissingSections(): void
    {
        $svc = new ColdOpenGreetingService();

        $this->assertSame(
            "Вступление\n\nПаёк\n\nЗамыкание",
            $svc->composeWelcome(['Вступление', null, 'Паёк', '   ', 'Замыкание'])
        );
        $this->assertSame('', $svc->composeWelcome([null, '', '  ']));
    }

    /** Собранный экран markdown-safe: непарные `*`/`_` → Telegram 400 → сообщение не дойдёт. */
    public function testComposedScreenMarkdownBalanced(): void
    {
        $svc = new ColdOpenGreetingService();

        $composed = $svc->composeWelcome([
            $svc->greetingSingleScreen(),
            "🎒 *Аварийный паёк.*\n\nВ рюкзаке:\n• 💧 Вода ×10",
            '📡 *Сигнал в эфире.*',
            '🤝 *Встречающий.*',
            $svc->closingSingleScreen(),
        ]);

        $this->assertSame(0, substr_count($composed, '*') % 2, 'непарные *');
        $this->assertSame(0, substr_count($composed, '_') % 2, 'непарные _');
        $this->assertTrue($svc->fitsSingleMessage($composed), 'реальный экран обязан влезать в одно сообщение');
    }

    /**
     * В режиме одного окна вступление НЕ зовёт называть героя: иначе копия противоречит
     * первому CTA, который ведёт в мир, и игрок снова упирается в форму имени.
     */
    public function testSingleScreenGreetingDoesNotDemandName(): void
    {
        $g = (new ColdOpenGreetingService())->greetingSingleScreen();

        $this->assertStringContainsString('Роби', $g);
        $this->assertStringNotContainsString('назови', mb_strtolower($g));
    }

    /** Замыкание честно называет первый шаг и снимает с имени статус ворот. */
    public function testClosingNamesFirstStepAndFreesName(): void
    {
        $closing = (new ColdOpenGreetingService())->closingSingleScreen();

        $this->assertStringContainsString(self::FIRST_STEP_LABEL, $closing);
        $this->assertStringContainsString('когда захочешь', $closing);
    }

    /** Порог одного сообщения: 3900 влезает, 3901 — нет (запас к лимиту Telegram 4096). */
    public function testFitsSingleMessageBoundary(): void
    {
        $svc = new ColdOpenGreetingService();

        $this->assertTrue($svc->fitsSingleMessage(str_repeat('я', 3900)));
        $this->assertFalse($svc->fitsSingleMessage(str_repeat('я', 3901)));
    }

    /**
     * 🔴 Анти-дрейф: кнопка первого CTA обязана называться в StartCommand ровно так же,
     * как её называет копия, и вести в ЖИВОЙ callback-роут. Разъезд любой из двух
     * половин = экран врёт («жми X», а кнопки X нет) или кнопка мёртвая.
     */
    public function testFirstStepButtonIsWiredAndLabelMatchesCopy(): void
    {
        $source = (string) file_get_contents(self::START_COMMAND);

        $this->assertStringContainsString(self::FIRST_STEP_LABEL, $source, 'метка кнопки разъехалась с копией');
        $this->assertMatchesRegularExpression(
            "/'text'\s*=>\s*'" . preg_quote(self::FIRST_STEP_LABEL, '/') . "'\s*,\s*'callback_data'\s*=>\s*'move'/u",
            $source,
            'кнопка «первый шаг» обязана вести в callback `move`'
        );
        $this->assertArrayHasKey('move', (new CallbackRoutes())->exactRoutes, 'роут `move` мёртв');
    }

    /**
     * Секции, въехавшие в одно окно, НЕ должны уехать ещё и отдельными сообщениями:
     * StartCommand обязан обнулить их перед блоками отправки (иначе игрок получит дубль).
     */
    public function testSectionsAreClearedAfterInlining(): void
    {
        $source = (string) file_get_contents(self::START_COMMAND);

        foreach (['starterKitText', 'signalText', 'greeterText'] as $var) {
            $this->assertMatchesRegularExpression(
                '/\$' . $var . '\s*=\s*null;/',
                $source,
                "секция \${$var} должна обнуляться после встраивания в одно окно"
            );
        }
    }
}

/**
 * Test-double: перехватывает killswitch-seam и запоминает, каким ключом его звали.
 *
 * @internal
 */
final class SingleScreenFlagSpy extends ColdOpenGreetingService
{
    public bool $value = false;

    public string $lastKey = '';

    public bool $lastDefault = true;

    protected function gsBool(string $key, bool $default): bool
    {
        $this->lastKey     = $key;
        $this->lastDefault = $default;

        return $this->value;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\Commands\StartCommand;
use App\Services\Logging\ActionOrigin;
use App\Services\Onboarding\ColdOpenGreetingService;
use App\Services\Onboarding\ColdOpenWelcomeService;
use CodeIgniter\Test\CIUnitTestCase;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Telegram;

/**
 * web-onboarding-01, ask 5: бот после переноса первого экрана в ядро выглядит для новичка так же.
 *
 * Фикстура `StartCommandColdOpenSnapshots.php` снята на коде StartCommand ДО переноса и больше
 * не перегенерируется: для каждого сочетания флагов (start_greeting × menu_defer × режим «одно
 * окно» × метка источника) × {спавн есть, нет} × {паёк/сигнал/встречающий: все, часть, ни одного,
 * не влезает в окно} она хранит полный исходящий поток. Двойник пишет payload, который ушёл бы
 * в `Request::sendMessage`, а не вызов-заглушку.
 *
 * @internal
 */
final class StartCommandColdOpenParityTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        ActionOrigin::overrideEnabled(null);
        parent::tearDown();
    }

    public function testSnapshotsCoverTheWholeMatrix(): void
    {
        $snapshots = require __DIR__ . '/StartCommandColdOpenSnapshots.php';

        // метка × greeting × defer × single × спавн (32) × 3 набора + «не влезает» только при single (16)
        $this->assertCount(32 * 3 + 16, $snapshots);
    }

    /** @dataProvider snapshotProvider */
    public function testBotRenderMatchesSnapshotByteForByte(string $name): void
    {
        $snapshots = require __DIR__ . '/StartCommandColdOpenSnapshots.php';
        $case      = $snapshots[$name];

        ActionOrigin::overrideEnabled($case['flags']['action_origin']);

        $greeting = new FlagGreeting($case['flags']['start_greeting'], $case['flags']['menu_defer']);
        $command  = new RecordingStartCommand(new Telegram('123456:TEST_TOKEN', 'test_bot'));
        $command->greeting = $greeting;

        $command->render(777, $case['created'], 'REPLY_KB');

        $this->assertSame(
            json_encode($case['calls'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($command->calls, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            "исходящий поток бота разошёлся со снимком: {$name}"
        );
    }

    /** @return iterable<string, array{string}> */
    public static function snapshotProvider(): iterable
    {
        $snapshots = require __DIR__ . '/StartCommandColdOpenSnapshots.php';
        foreach (array_keys($snapshots) as $name) {
            yield $name => [$name];
        }
    }

    /** В StartCommand не осталось ни легаси-текста, ни клавиатуры cold open. */
    public function testStartCommandHoldsNoColdOpenCopy(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Controllers/Telegram/Commands/StartCommand.php');

        foreach (['Wild World — выживание', 'Придумай герою имя', 'Задать имя персонажа', 'Сделать первый шаг', 'Назвать героя', 'callback_data'] as $needle) {
            $this->assertStringNotContainsString($needle, $source, "в StartCommand остался кусок первого экрана: {$needle}");
        }
    }
}

/** @internal */
final class FlagGreeting extends ColdOpenGreetingService
{
    public function __construct(private bool $startGreeting, private bool $menuDefer)
    {
    }

    protected function gsBool(string $key, bool $default): bool
    {
        return match ($key) {
            'onboarding.cold_open_v2.start_greeting' => $this->startGreeting,
            'onboarding.cold_open_v2.menu_defer'     => $this->menuDefer,
            default                                  => throw new \LogicException("Неожиданный флаг {$key}"),
        };
    }
}

/** @internal */
final class RecordingStartCommand extends StartCommand
{
    public ?ColdOpenGreetingService $greeting = null;

    /** @var list<array{string, array<string,mixed>}> */
    public array $calls = [];

    /** @param array{spawned: bool, singleScreen: bool, starterKit: ?string, signal: ?string, greeter: ?string} $created */
    public function render(int $chatId, array $created, string $replyKeyboard): ServerResponse
    {
        return $this->renderNewcomer($chatId, $created, $replyKeyboard);
    }

    protected function welcomeService(): ColdOpenWelcomeService
    {
        return new ColdOpenWelcomeService($this->greeting);
    }

    protected function deliver(array $payload): ServerResponse
    {
        $this->calls[] = ['sendMessage', $payload];

        return new ServerResponse(['ok' => true, 'result' => true], '');
    }
}

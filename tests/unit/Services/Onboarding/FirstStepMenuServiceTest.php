<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\FirstStepMenuService;
use CodeIgniter\Test\CIUnitTestCase;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * e-first-02: полное меню один раз после первого шага новичка. Двойник держит маркеры в памяти
 * и пишет payload, который ушёл бы в `Request::sendMessage`.
 *
 * @internal
 */
final class FirstStepMenuServiceTest extends CIUnitTestCase
{
    /**
     * @return FirstStepMenuService&object{sent: list<array<string,mixed>>, marks: list<int>, markers: array<int,bool>, flag: bool, origin: ?string, deliverOk: bool, throwOnSend: bool}
     */
    private function service(bool $flag = true, ?string $origin = 'cold', bool $deliverOk = true): FirstStepMenuService
    {
        $svc = new class () extends FirstStepMenuService {
            /** @var list<array<string,mixed>> */
            public array $sent = [];
            /** @var list<int> */
            public array $marks = [];
            /** @var array<int,bool> */
            public array $markers = [];
            public bool $flag = true;
            public ?string $origin = 'cold';
            public bool $deliverOk = true;
            public bool $throwOnSend = false;

            protected function flagEnabled(): bool
            {
                return $this->flag;
            }

            protected function currentOrigin(): ?string
            {
                return $this->origin;
            }

            protected function alreadyAttached(int $charId): bool
            {
                return isset($this->markers[$charId]);
            }

            protected function mark(int $charId): void
            {
                $this->marks[]          = $charId;
                $this->markers[$charId] = true;
            }

            protected function send(array $payload): ServerResponse
            {
                if ($this->throwOnSend) {
                    throw new \RuntimeException('telegram down');
                }
                $this->sent[] = $payload;

                return new ServerResponse(['ok' => $this->deliverOk, 'result' => $this->deliverOk ? true : null, 'description' => $this->deliverOk ? '' : 'Forbidden: bot was blocked by the user'], '');
            }
        };
        $svc->flag      = $flag;
        $svc->origin    = $origin;
        $svc->deliverOk = $deliverOk;

        return $svc;
    }

    public function testFirstColdStepSendsFullMenuOnceAndSecondStepDoesNot(): void
    {
        $svc = $this->service();

        $svc->maybeAttach(42, 777);
        $svc->maybeAttach(42, 777);
        $svc->maybeAttach(42, 777);

        $this->assertCount(1, $svc->sent, 'второй шаг шлёт меню повторно');
        $this->assertSame([42], $svc->marks);
        $this->assertSame(777, $svc->sent[0]['chat_id']);
    }

    public function testMarkerIsNotSetWhenDeliveryFails(): void
    {
        $svc = $this->service(deliverOk: false);

        $svc->maybeAttach(42, 777);

        $this->assertCount(1, $svc->sent);
        $this->assertSame([], $svc->marks, 'маркер поставлен при неуспешной доставке');

        // Доставка починилась — следующий шаг меню всё-таки довозит.
        $svc->deliverOk = true;
        $svc->maybeAttach(42, 777);
        $this->assertCount(2, $svc->sent);
        $this->assertSame([42], $svc->marks);
    }

    public function testNothingIsSentWhenGateIsClosed(): void
    {
        $cases = [
            'флаг OFF'          => [$this->service(flag: false), 42, 777],
            'шаг не с cold'     => [$this->service(origin: 'cmp'), 42, 777],
            'шаг без метки'     => [$this->service(origin: null), 42, 777],
            'нет персонажа'     => [$this->service(), 0, 777],
            'нет чата'          => [$this->service(), 42, 0],
        ];

        foreach ($cases as $name => [$svc, $charId, $chatId]) {
            $svc->maybeAttach($charId, $chatId);
            $this->assertSame([], $svc->sent, "меню ушло при закрытом гейте: {$name}");
            $this->assertSame([], $svc->marks, "маркер при закрытом гейте: {$name}");
        }

        $marked              = $this->service();
        $marked->markers[42] = true;
        $marked->maybeAttach(42, 777);
        $this->assertSame([], $marked->sent, 'маркер уже есть — меню не шлётся');
    }

    public function testExceptionsDoNotEscape(): void
    {
        $svc              = $this->service();
        $svc->throwOnSend = true;

        $svc->maybeAttach(42, 777);

        $this->assertSame([], $svc->marks);
    }

    public function testMessageCarriesFullKeyboardAndWhereIsWhatLine(): void
    {
        $svc = $this->service();
        $svc->maybeAttach(42, 777);
        $payload = $svc->sent[0];

        $this->assertSame((string) \App\Services\Telegram\BotMenuService::mainReplyKeyboard(), (string) $payload['reply_markup'], 'полная нижняя клавиатура');
        $this->assertSame('Markdown', $payload['parse_mode']);

        $text = (string) $payload['text'];
        $this->assertStringContainsString(\App\Services\Telegram\BotMenuService::replyButtonsLine(), $text, 'кнопки перечислены из BotMenuService, не хардкодом');
        $this->assertStringContainsString(\App\Services\Telegram\BotMenuService::menuLabel('world'), $text, 'строка «что где» называет, куда идти');
        $this->assertSame(0, substr_count($text, '*') % 2, 'непарные *');
        $this->assertSame(0, substr_count($text, '_') % 2, 'непарные _');
        $this->assertDoesNotMatchRegularExpression('/[Нн]ижн\w* меню|[Нн]ижн\w* панел|(?:[Шш]ест|[Сс]ем|[Вв]осем)\w* кноп|☰/u', $text);
        $this->assertLessThanOrEqual(4096, mb_strlen($text));
    }

    /** repair-1: настоящая отправка поднимает мост Telegram (TelegramSenderBridgeCoverageTest). */
    public function testRealSendRaisesTelegramBridgeFirst(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Services/Onboarding/FirstStepMenuService.php');

        $this->assertMatchesRegularExpression('/TelegramBridge::ensure\s*\(/', $source, 'repair-1: мост не поднят');
        $this->assertLessThan(
            strpos($source, 'Request::sendMessage('),
            strpos($source, 'TelegramBridge::ensure('),
            'repair-1: мост поднимается до отправки'
        );
    }
}

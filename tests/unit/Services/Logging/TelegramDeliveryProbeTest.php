<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Logging;

use App\Services\Logging\TelegramDeliveryProbe;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-148 (расширение) — чистые части пробы доставки {@see TelegramDeliveryProbe}.
 *
 * Сам middleware в PHPUnit не срабатывает: `Request::send()` при `PHPUNIT_TESTSUITE` отдаёт
 * фейковый ответ, не доходя до Guzzle-клиента. Поэтому здесь заперты разбор пути, разбор тела
 * Bot API и классификация методов — то, от чего зависит, не будет ли ложных тревог.
 * Сквозная проверка — Tier-3 смоуком на testbot.
 *
 * @internal
 */
final class TelegramDeliveryProbeTest extends CIUnitTestCase
{
    // ── имя метода из пути /bot<TOKEN>/<method> ───────────────────────────────

    public function testMethodExtractedFromBotPath(): void
    {
        $this->assertSame('sendPhoto', TelegramDeliveryProbe::methodFromPath('/bot123456:AAF-token/sendPhoto'));
        $this->assertSame('editMessageMedia', TelegramDeliveryProbe::methodFromPath('/bot1:x/editMessageMedia'));
    }

    public function testMethodFromPathIsNullWhenNothingToRead(): void
    {
        $this->assertNull(TelegramDeliveryProbe::methodFromPath('/'));
        $this->assertNull(TelegramDeliveryProbe::methodFromPath(''));
    }

    // ── что считаем доставкой игроку ──────────────────────────────────────────

    /**
     * @dataProvider deliveringMethods
     */
    public function testDeliveringMethodsCount(string $method): void
    {
        $this->assertTrue(TelegramDeliveryProbe::isDelivering($method));
    }

    /** @return list<array{0: string}> */
    public static function deliveringMethods(): array
    {
        return [
            ['sendMessage'], ['sendPhoto'], ['sendMediaGroup'], ['sendSticker'],
            ['editMessageText'], ['editMessageCaption'], ['editMessageMedia'], ['editMessageReplyMarkup'],
            ['copyMessage'], ['forwardMessage'],
        ];
    }

    /**
     * Служебные вызовы не доставляют сообщение: «часики» на устаревшем callback падают
     * штатно и не должны поднимать ложную тревогу.
     *
     * @dataProvider serviceMethods
     */
    public function testServiceMethodsDoNotCount(string $method): void
    {
        $this->assertFalse(TelegramDeliveryProbe::isDelivering($method));
    }

    /** @return list<array{0: string}> */
    public static function serviceMethods(): array
    {
        return [
            ['answerCallbackQuery'], ['deleteMessage'], ['sendChatAction'], ['getMe'],
            ['setWebhook'], ['setMyCommands'], ['getUpdates'], ['answerInlineQuery'],
        ];
    }

    // ── разбор тела Bot API ───────────────────────────────────────────────────

    public function testOkBodyIsDelivered(): void
    {
        [$ok, $desc] = TelegramDeliveryProbe::parseBody('{"ok":true,"result":{"message_id":42}}');

        $this->assertTrue($ok);
        $this->assertSame('', $desc);
    }

    public function testBusinessErrorCarriesDescription(): void
    {
        // Ровно тот ответ, из-за которого экраны лавки уходили в пустоту.
        [$ok, $desc] = TelegramDeliveryProbe::parseBody(
            '{"ok":false,"error_code":400,"description":"Bad Request: there is no photo in the request"}'
        );

        $this->assertFalse($ok);
        $this->assertSame('Bad Request: there is no photo in the request', $desc);
    }

    public function testOkFalseWithoutDescriptionStillFails(): void
    {
        [$ok, $desc] = TelegramDeliveryProbe::parseBody('{"ok":false}');

        $this->assertFalse($ok);
        $this->assertSame('ok=false', $desc);
    }

    public function testGarbageBodyCountsAsFailureNotSuccess(): void
    {
        [$ok, $desc] = TelegramDeliveryProbe::parseBody('<html>502 Bad Gateway</html>');

        $this->assertFalse($ok, 'нечитаемый ответ — не повод считать доставленным');
        $this->assertSame('invalid response body', $desc);
    }

    public function testTransportErrorSurvivesEmptyBody(): void
    {
        [$ok, $desc] = TelegramDeliveryProbe::parseBody('', 'cURL error 28: timeout');

        $this->assertFalse($ok);
        $this->assertSame('cURL error 28: timeout', $desc);
    }

    public function testOkMustBeStrictlyTrue(): void
    {
        // «ok»:1 — не наш формат; лучше ложная тревога, чем пропущенная потеря.
        [$ok] = TelegramDeliveryProbe::parseBody('{"ok":1}');

        $this->assertFalse($ok);
    }
}

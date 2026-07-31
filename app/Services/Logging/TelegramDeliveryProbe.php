<?php

declare(strict_types=1);

namespace App\Services\Logging;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use Longman\TelegramBot\Request as TelegramRequest;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * ADR-148 (расширение) — сигнал ДОСТАВКИ для firehose.
 *
 * Повод (31.07.2026): экраны лавки крафта были мертвы 2.5 месяца, и никто не заметил.
 * Firehose писал `status='ok'`, потому что знал только про РОУТИНГ: обработчик найден,
 * исключений нет. А сообщение при этом не уходило (см. {@see \App\Services\Notifications\MediaSender}
 * §Тихая потеря). Не хватало ровно одного факта: «кнопка нажата — ответа игрок не получил».
 *
 * Как ловим: подменяем Guzzle-клиент Longman'а своим — с middleware, который смотрит ответ
 * Bot API на КАЖДУЮ отправку, включая сотни прямых `Request::sendMessage(...)` мимо
 * MediaSender. Перехват на транспорте — единственный способ не пропустить ни один callsite.
 *
 * 🔴 Провал ОДНОЙ отправки ≠ провал доставки. `MediaSender::editOrSend` штатно роняет правку
 * (`editMessageMedia` не умеет править текстовое сообщение) и вытягивает фолбэком. Поэтому
 * считаем ДВА счётчика, а вердикт выносит {@see PlayerActionLogger::commit()}:
 * «не доставлено» = за апдейт **не прошла ни одна** доставка, а провалы были.
 *
 * Служебные вызовы (`answerCallbackQuery`, `deleteMessage`, `sendChatAction`, `getMe`…) не
 * доставляют игроку сообщение и в счётчики не идут — иначе «часики» на устаревшем callback
 * давали бы ложную тревогу.
 *
 * 🔴 Defensive: установка обёрнута в try/catch, middleware никогда не бросает и не меняет
 * ответ. Если что-то пойдёт не так — молчим, транспорт бота важнее телеметрии.
 * Аварийный выключатель — общий с firehose ({@see PlayerActionLogger::KILLSWITCH}): выключён
 * firehose → клиент вообще не подменяется.
 *
 * ⚠️ В PHPUnit не срабатывает: `Request::send()` при `PHPUNIT_TESTSUITE` отдаёт фейковый ответ,
 * не доходя до клиента. Поэтому тестами покрыты чистые части (разбор пути/тела/классификация
 * метода), а сквозная проверка — Tier-3 смоуком на testbot.
 */
final class TelegramDeliveryProbe
{
    /**
     * Методы Bot API, которые реально доставляют игроку сообщение.
     *
     * @var list<string>
     */
    private const DELIVERING = [
        'sendMessage', 'sendPhoto', 'sendAudio', 'sendDocument', 'sendVideo', 'sendAnimation',
        'sendVoice', 'sendVideoNote', 'sendMediaGroup', 'sendLocation', 'sendVenue', 'sendContact',
        'sendPoll', 'sendDice', 'sendSticker', 'sendInvoice', 'copyMessage', 'forwardMessage',
        'editMessageText', 'editMessageCaption', 'editMessageMedia', 'editMessageReplyMarkup',
    ];

    private static bool $installed = false;

    /** Сброс для тестов/гигиены (клиент Longman'а при этом не восстанавливается). */
    public static function reset(): void
    {
        self::$installed = false;
    }

    /**
     * Подменить Guzzle-клиент Longman'а на клиент с middleware-пробой. Идемпотентно.
     * Вызывать ПОСЛЕ инициализации {@see \Longman\TelegramBot\Telegram} (она сама ставит
     * клиент по умолчанию, если своего нет).
     */
    public static function install(): void
    {
        if (self::$installed) {
            return;
        }
        self::$installed = true;

        try {
            if (! PlayerActionLogger::current()->firehoseEnabled()) {
                return;
            }

            $stack = HandlerStack::create();
            $stack->push(self::middleware(), 'wildworld_delivery_probe');

            // base_uri повторяет дефолт Longman (Request::$api_base_uri — private static,
            // геттера нет). Проект `setCustomBotApiUri()` не использует — проверено грепом.
            TelegramRequest::setClient(new Client([
                'base_uri' => 'https://api.telegram.org',
                'handler'  => $stack,
            ]));
        } catch (\Throwable $e) {
            log_message('error', '[TelegramDeliveryProbe] install failed: ' . $e->getMessage());
        }
    }

    /**
     * Middleware: смотрит и успешный ответ, и отказ (Telegram на бизнес-ошибку отвечает
     * HTTP 400 → Guzzle отклоняет промис, тело лежит в исключении).
     */
    private static function middleware(): callable
    {
        return static fn (callable $handler): callable =>
            static function (RequestInterface $request, array $options) use ($handler) {
                return $handler($request, $options)->then(
                    static function (ResponseInterface $response) use ($request) {
                        self::observe($request, $response, null);

                        return $response;
                    },
                    static function ($reason) use ($request) {
                        $response = ($reason instanceof RequestException) ? $reason->getResponse() : null;
                        $fallback = ($reason instanceof \Throwable) ? $reason->getMessage() : 'transport error';
                        self::observe($request, $response, $fallback);

                        return Create::rejectionFor($reason);
                    }
                );
            };
    }

    /** 🔴 Никогда не бросает и не трогает тело ответа для вызывающего. */
    private static function observe(RequestInterface $request, ?ResponseInterface $response, ?string $transportError): void
    {
        try {
            $method = self::methodFromPath($request->getUri()->getPath());
            if ($method === null || ! self::isDelivering($method)) {
                return;
            }

            [$ok, $description] = self::readOutcome($response, $transportError);
            PlayerActionLogger::current()->noteDelivery($ok, $method, $ok ? null : $description);
        } catch (\Throwable $e) {
            log_message('error', '[TelegramDeliveryProbe] observe failed: ' . $e->getMessage());
        }
    }

    /**
     * Прочитать исход из тела ответа, НЕ ломая его для Longman: поток вычитываем и
     * перематываем обратно.
     *
     * @return array{0: bool, 1: string}
     */
    private static function readOutcome(?ResponseInterface $response, ?string $transportError): array
    {
        if ($response === null) {
            return [false, $transportError ?? 'no response'];
        }

        $body = $response->getBody();
        $raw  = (string) $body;
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return self::parseBody($raw, $transportError);
    }

    // ── чистые части (покрыты тестами) ───────────────────────────────────

    /**
     * Имя метода Bot API из пути `/bot<TOKEN>/<method>`.
     */
    public static function methodFromPath(string $path): ?string
    {
        // Пустые сегменты уже отфильтрованы, поэтому последний непустой — это метод.
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        return $segments === [] ? null : $segments[count($segments) - 1];
    }

    public static function isDelivering(string $method): bool
    {
        return in_array($method, self::DELIVERING, true);
    }

    /**
     * Разбор тела ответа Bot API.
     *
     * @return array{0: bool, 1: string} [доставлено?, описание ошибки]
     */
    public static function parseBody(string $raw, ?string $transportError = null): array
    {
        $json = json_decode($raw, true);
        if (! is_array($json)) {
            return [false, $transportError ?? 'invalid response body'];
        }

        if (($json['ok'] ?? null) === true) {
            return [true, ''];
        }

        $description = is_string($json['description'] ?? null) ? $json['description'] : 'ok=false';

        return [false, $description];
    }
}

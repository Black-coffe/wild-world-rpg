<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Services\Logging\ActionOrigin;
use App\Services\Logging\PlayerActionLogger;
use App\Services\Logging\TelegramDeliveryProbe;
use App\Services\Player\LastSeenService;
use App\Services\Player\LoginStreakService;
use App\Services\Player\ReturnDigestService;
use App\Services\Quest\DailyTaskService;
use App\Services\Web\DeliveryContext;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Telegram;

/**
 * web-bridge-p1-05 (ADR-189 §1) — один конвейер игрового апдейта для вебхука и для `/play`.
 *
 * Тело `BotController::webhook()` ПОСЛЕ секрета, разбора JSON, дедупа ADR-181 и community-gate
 * перенесено сюда без смены порядка шагов: LastSeen → ADR-168 strip/origin → firehose ADR-148 +
 * probe доставки → E6/E8-хуки → диспетч → finally (stamp, commit, reset). Сверху — актёр
 * {@see DeliveryContext} на время апдейта (ADR-189 §5).
 *
 * Исключения: источник `telegram` ведёт себя как прежний вебхук — `TelegramException` глотается,
 * прочий `Throwable` пробрасывается (HTTP 500). Источник `web` не бросает никогда: ошибка → строка
 * firehose `status=error` и `false`.
 */
final class UpdatePipeline
{
    public const SOURCE_TELEGRAM = 'telegram';
    public const SOURCE_WEB      = 'web';

    /** @var (callable(array<array-key, mixed>|null): void)|null */
    private $dispatch;

    /**
     * @param (callable(array<array-key, mixed>|null): void)|null $dispatch диспетч апдейта; null —
     *        Longman напрямую ({@see defaultDispatch()}). `BotController` отдаёт свой seam
     *        `dispatchToTelegram()`, чтобы тест-спаи контроллера работали как раньше.
     */
    public function __construct(private readonly ?Telegram $telegram, ?callable $dispatch = null)
    {
        $this->dispatch = $dispatch;
    }

    /**
     * `Telegram` ровно как его строил `BotController` (ключ и имя из env, пути команд). Null — если
     * Longman отказал (ошибка в лог), как и раньше.
     */
    public static function telegram(): ?Telegram
    {
        $apiKey      = getenv('telegram.API_KEY');
        $botUsername = getenv('telegram.BOT_USERNAME');
        try {
            $telegram = new Telegram(is_string($apiKey) ? $apiKey : '', is_string($botUsername) ? $botUsername : '');
            $telegram->addCommandsPath(APPPATH . 'Controllers/Telegram/Commands');

            return $telegram;
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());

            return null;
        }
    }

    /**
     * @param array<array-key, mixed>|null $update декодированный апдейт; null — тело не разобралось
     *        (как прежде: только диспетч, Longman сам сообщит об ошибке ввода)
     * @param string $source `telegram` | `web`
     *
     * @return bool false — обработка упала (только для `web`; `telegram` в этом случае бросает)
     */
    public function run(?array $update, string $source): bool
    {
        $isWeb = $source === self::SOURCE_WEB;
        DeliveryContext::setActor(is_array($update) ? LastSeenService::extractChatId($update) : null);

        try {
            return $this->process($update, $isWeb);
        } catch (\Throwable $e) {
            if (! $isWeb) {
                throw $e;
            }
            log_message('error', '[UpdatePipeline] web: ' . $e->getMessage());

            return false;
        } finally {
            DeliveryContext::reset();
        }
    }

    /** @param array<array-key, mixed>|null $update */
    private function process(?array $update, bool $isWeb): bool
    {
        // E6 (ADR-108) Фаза 1 — telegram_id ДО обработки, last_seen ПОСЛЕ (в finally): во время
        // обработки код видит ПРЕДЫДУЩЕЕ значение (основа digest «пока тебя не было»).
        $telegramUserId = is_array($update) ? LastSeenService::extractTelegramId($update) : null;

        // ADR-168 — снять метку источника с callback_data ДО всего остального: и firehose, и
        // Longman видят очищенную строку. Снятие безусловно, простановка — под killswitch.
        $actionOrigin = null;
        if (is_array($update)) {
            [$update, $actionOrigin, $originStripped] = ActionOrigin::stripUpdate($update);
            ActionOrigin::set($actionOrigin);

            // Longman в вебхуке читает php://input САМ — очищенную строку отдаём явно, и только
            // когда апдейт реально очищен (Tier-3 14.08: условие на ФАКТЕ очистки).
            if ($originStripped && $this->telegram !== null) {
                $reencoded = json_encode($update, JSON_UNESCAPED_UNICODE);
                if (is_string($reencoded)) {
                    $this->telegram->setCustomInput($reencoded);
                }
            }
        }

        // ADR-148 — firehose прямых действий; setOrigin ПОСЛЕ begin (begin сбрасывает захват);
        // probe доставки ПОСЛЕ begin (счётчики живут внутри захвата). `web` — канал из аргумента.
        if (is_array($update)) {
            PlayerActionLogger::current()->begin($update, $isWeb ? self::SOURCE_WEB : null);
            PlayerActionLogger::current()->setOrigin($actionOrigin);
            TelegramDeliveryProbe::install();
        }

        // E6 (ADR-108) Ф2/Ф3 и E8 (ADR-109) Ф2 — ДО обработки; каждый defensive.
        if ($telegramUserId !== null && is_array($update)) {
            $chatId = LastSeenService::extractChatId($update) ?? $telegramUserId;
            try {
                (new ReturnDigestService())->maybeSendDigest($telegramUserId, $chatId);
            } catch (\Throwable $e) {
                log_message('error', '[Bot.webhook] returnDigest: ' . $e->getMessage());
            }
            try {
                (new LoginStreakService())->maybeReward($telegramUserId, $chatId);
            } catch (\Throwable $e) {
                log_message('error', '[Bot.webhook] loginStreak: ' . $e->getMessage());
            }
            try {
                (new DailyTaskService())->ensureForTelegramUser($telegramUserId, $chatId);
            } catch (\Throwable $e) {
                log_message('error', '[Bot.webhook] dailyTasks: ' . $e->getMessage());
            }
        }

        $ok = true;
        try {
            $this->dispatch($update, $isWeb);
        } catch (TelegramException $e) {
            // Как раньше: логируем и глотаем TelegramException.
            log_message('error', $e->getMessage());
            PlayerActionLogger::current()->markError($e->getMessage());
            $ok = false;
        } catch (\Throwable $e) {
            // ADR-148 — прочие исключения помечаем 'error'; вебхук пробрасывает их фреймворку
            // (как раньше), `web` — глотает.
            PlayerActionLogger::current()->markError($e->getMessage());
            if (! $isWeb) {
                throw $e;
            }
            log_message('error', '[UpdatePipeline] web dispatch: ' . $e->getMessage());
            $ok = false;
        } finally {
            if ($telegramUserId !== null) {
                (new LastSeenService())->stampByTelegramId($telegramUserId);
            }
            // ADR-148 — одна строка firehose, и при исключении тоже (defensive, no-op без begin).
            PlayerActionLogger::current()->commit();
            // ADR-168 — метка живёт ровно один апдейт.
            ActionOrigin::reset();
        }

        return $ok;
    }

    /** @param array<array-key, mixed>|null $update */
    private function dispatch(?array $update, bool $isWeb): void
    {
        if ($this->dispatch !== null) {
            ($this->dispatch)($update);

            return;
        }
        $this->defaultDispatch($update, $isWeb);
    }

    /**
     * Longman напрямую: `web` — синтетический апдейт через `processUpdate()` (не php://input);
     * `telegram` — `handle()`, как вебхук.
     *
     * @param array<array-key, mixed>|null $update
     */
    private function defaultDispatch(?array $update, bool $isWeb): void
    {
        if ($this->telegram === null) {
            throw new \RuntimeException('UpdatePipeline: Telegram client unavailable');
        }
        if ($isWeb && is_array($update)) {
            $this->telegram->processUpdate(new Update($update, $this->telegram->getBotUsername()));

            return;
        }
        $this->telegram->handle();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\TelegramUserModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Throwable;

/**
 * BroadcastService — рассылка Telegram-сообщений игрокам с проверкой доставки (isOk).
 *
 * Извлечено из {@see \App\Controllers\Admin\MessageController} (2026-06-01, ADR-087),
 * чтобы и админ-рассылка, и пост-вайп уведомление использовали один код:
 * isOk-aware подсчёт, self-heal заблокировавших (markBlocked/clearBlocked).
 *
 * 🔑 Longman НЕ бросает на 403/429 — проверяем $resp->isOk(), иначе счётчик «sent»
 * завышен (memory feedback_broadcast_isok_real_reachable_audience).
 */
final class BroadcastService
{
    private const TELEGRAM_CAPTION_LIMIT = 1024;
    private const TELEGRAM_MESSAGE_LIMIT = 4096;

    private TelegramUserModel $userModel;

    public function __construct(?TelegramUserModel $userModel = null)
    {
        $this->userModel = $userModel ?? new TelegramUserModel();
    }

    /**
     * Разослать ВСЕМ игрокам без исключения (каждой строке telegram_users).
     *
     * @return array{sent:int, blocked:int, failed:int, total:int}
     */
    public function broadcastToAll(string $fullMessage, ?string $imagePath = null): array
    {
        /** @var list<string> $ids */
        $ids = [];
        foreach ($this->userModel->findAll() as $u) {
            if (is_array($u) && isset($u['telegram_id']) && is_scalar($u['telegram_id'])) {
                $id = (string) $u['telegram_id'];
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return $this->dispatch($fullMessage, $imagePath, $ids);
    }

    /**
     * Разослать конкретным telegram_id.
     *
     * @param list<string> $telegramIds
     * @return array{sent:int, blocked:int, failed:int, total:int}
     */
    public function broadcastTo(array $telegramIds, string $fullMessage, ?string $imagePath = null): array
    {
        return $this->dispatch($fullMessage, $imagePath, $telegramIds);
    }

    /**
     * @param list<string> $recipients
     * @return array{sent:int, blocked:int, failed:int, total:int}
     */
    private function dispatch(string $fullMessage, ?string $imagePath, array $recipients): array
    {
        $this->initTelegram();

        $sent    = 0;
        $blocked = 0;
        $failed  = 0;

        foreach ($recipients as $telegramId) {
            try {
                $resp = $this->sendOne($telegramId, $fullMessage, $imagePath);
                if ($resp->isOk()) {
                    $sent++;
                    $this->userModel->clearBlocked($telegramId);
                    continue;
                }
                $desc = $resp->getDescription();
                if (BroadcastDeliveryClassifier::isBlocked($desc)) {
                    $blocked++;
                    $this->userModel->markBlocked($telegramId);
                } else {
                    $failed++;
                }
                log_message('error', "Broadcast to {$telegramId} not delivered (isOk=false): {$desc}");
            } catch (TelegramException $e) {
                $failed++;
                log_message('error', 'Telegram send to ' . $telegramId . ': ' . $e->getMessage());
            } catch (Throwable $e) {
                $failed++;
                log_message('error', 'Send failure to ' . $telegramId . ': ' . $e->getMessage());
            }
        }

        return ['sent' => $sent, 'blocked' => $blocked, 'failed' => $failed, 'total' => count($recipients)];
    }

    private function initTelegram(): void
    {
        $apiKeyEnv      = getenv('telegram.API_KEY');
        $botUsernameEnv = getenv('telegram.BOT_USERNAME');
        $apiKey         = is_string($apiKeyEnv) ? $apiKeyEnv : '';
        $botUsername    = is_string($botUsernameEnv) ? $botUsernameEnv : '';
        $telegram       = new Telegram($apiKey, $botUsername);
        Request::initialize($telegram);
    }

    private function sendOne(string $chatId, string $fullMessage, ?string $imagePath): ServerResponse
    {
        if ($imagePath === null) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => mb_substr($fullMessage, 0, self::TELEGRAM_MESSAGE_LIMIT),
                'parse_mode' => 'Markdown',
            ]);
        }

        if (mb_strlen($fullMessage) <= self::TELEGRAM_CAPTION_LIMIT) {
            return Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $fullMessage,
                'parse_mode' => 'Markdown',
            ]);
        }

        $photoResp = Request::sendPhoto([
            'chat_id' => $chatId,
            'photo'   => Request::encodeFile($imagePath),
        ]);
        if (! $photoResp->isOk()) {
            return $photoResp;
        }

        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => mb_substr($fullMessage, 0, self::TELEGRAM_MESSAGE_LIMIT),
            'parse_mode' => 'Markdown',
        ]);
    }
}

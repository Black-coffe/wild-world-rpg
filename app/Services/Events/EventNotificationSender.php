<?php

declare(strict_types=1);

namespace App\Services\Events;

use Longman\TelegramBot\Exception\TelegramException;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Telegram;

/**
 * v0.51.71 (NotificationPolicy decomp Step 4) — extract Telegram I/O
 * (єдина точка sendPhoto / sendMessage + lazy Telegram init) у dedicated sender.
 *
 * Public API:
 *   send(chatId, text, imgPath, keyboard): bool
 *
 * Behavior:
 *   - Якщо $imgPath != null + is_readable($photoFs) → sendPhoto з caption.
 *   - Якщо photo missing → log warning + fallback до sendMessage без image.
 *   - TelegramException → log error + return false (resilient).
 */
final class EventNotificationSender
{
    private ?Telegram $telegram = null;

    /**
     * Універсальний send: photo якщо img_path, інакше text.
     *
     * @param array<string, mixed> $keyboard
     */
    public function send(int $chatId, string $text, ?string $imgPath, array $keyboard): bool
    {
        $this->ensureTelegramInitialized();

        try {
            if ($imgPath !== null && $imgPath !== '') {
                $photoFs = FCPATH . $imgPath;
                if (is_readable($photoFs)) {
                    \App\Services\Notifications\MediaSender::sendPhotoOrText([
                        'chat_id'      => $chatId,
                        'photo'        => Request::encodeFile($photoFs),
                        'caption'      => $text,
                        'parse_mode'   => 'Markdown',
                        'reply_markup' => json_encode($keyboard),
                    ]);
                    return true;
                }
                // Photo missing → fallback на text без зображення (resilient)
                log_message('warning', "[EventNotificationSender] photo missing: {$photoFs}");
            }

            Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
            return true;
        } catch (TelegramException $e) {
            log_message('error', "[EventNotificationSender] send chat={$chatId}: " . $e->getMessage());
            return false;
        }
    }

    private function ensureTelegramInitialized(): void
    {
        if ($this->telegram !== null) {
            return;
        }
        try {
            $this->telegram = new Telegram(getenv('telegram.API_KEY'), getenv('telegram.BOT_USERNAME'));
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', "[EventNotificationSender] Telegram init failed: " . $e->getMessage());
        }
    }
}

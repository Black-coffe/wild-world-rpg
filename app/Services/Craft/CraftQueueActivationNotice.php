<?php

declare(strict_types=1);

namespace App\Services\Craft;

use App\Models\TelegramUserModel;
use App\Services\Telegram\Request;
use Config\CraftRecipes;
use Throwable;

/**
 * craft-queue-promote-1125 — одно сообщение «▶️ Очередь активирована!» для всех, кто продвигает очередь
 * крафта: `GenericCraftCompletionHandler` (тот же рецепт), Worker после любой задачи
 * ({@see \App\TaskHandlers\BaseTaskHandler::promoteQueuedCrafts()}), прерывание и отмена игроком
 * ({@see promoteAndSend()}). Только текст — полон при выключенных картинках.
 *
 * @phpstan-import-type Promoted from CraftQueueService
 */
final class CraftQueueActivationNotice
{
    /**
     * Сообщение о старте продвинутой строки. Null — некому слать (нет `telegram_id`) или рецепт
     * неизвестен конфигу; продвижение от этого не откатывается.
     *
     * @param Promoted $promoted
     *
     * @return array{chat_id:int, text:string, parse_mode:string}|null
     */
    public static function message(array $promoted): ?array
    {
        $chatId = self::chatId($promoted['telegram_user_id']);
        if ($chatId === null || $promoted['recipe'] === null) {
            return null;
        }

        /** @var CraftRecipes $cfg */
        $cfg    = config('CraftRecipes');
        $recipe = $cfg->get($promoted['recipe']);
        $name   = is_array($recipe) && is_string($recipe['start_caption_name'] ?? null) ? $recipe['start_caption_name'] : null;
        if ($name === null) {
            return null;
        }

        return [
            'chat_id'    => $chatId,
            'text'       => self::text($name, $promoted['qty'], $promoted['ends_at']->format('H:i')),
            'parse_mode' => 'Markdown',
        ];
    }

    public static function text(string $craftName, int $quantity, string $endsAt): string
    {
        return "▶️ *Очередь активирована!*\n\n"
            . "Запущен крафт: {$craftName} x{$quantity} шт.\n\n"
            . "Завершится в *{$endsAt}*.";
    }

    /**
     * Путь игрока (вебхук, `/play`): продвинуть всё, что очередь персонажа может запустить сейчас, и
     * сообщить через `Request` приложения (доходит и до Telegram, и до веба, ADR-189). Никогда не
     * бросает: сбой продвижения или отправки не ломает ответ на действие игрока.
     *
     * @return int сколько строк очереди запущено
     */
    public static function promoteAndSend(int $characterId): int
    {
        $promoted = self::promote($characterId);
        self::send($promoted);

        return count($promoted);
    }

    /**
     * Только продвижение, без отправки — для вызывающего, который сначала шлёт свой итог, а
     * сообщение об очереди — после ({@see send()}). Никогда не бросает.
     *
     * @return list<Promoted>
     */
    public static function promote(int $characterId): array
    {
        if ($characterId < 1) {
            return [];
        }
        try {
            return (new CraftQueueService())->promoteEligible($characterId);
        } catch (Throwable $e) {
            log_message('error', "[CraftQueueActivationNotice] promote failed for character {$characterId}: " . $e->getMessage());

            return [];
        }
    }

    /**
     * Сообщения о запущенных строках через `Request` приложения. Никогда не бросает.
     *
     * @param list<Promoted> $promoted
     */
    public static function send(array $promoted): void
    {
        foreach ($promoted as $row) {
            try {
                $message = self::message($row);
                if ($message !== null) {
                    Request::sendMessage($message);
                }
            } catch (Throwable $e) {
                log_message('error', "[CraftQueueActivationNotice] notify failed for task #{$row['charTaskId']}: " . $e->getMessage());
            }
        }
    }

    private static function chatId(int $telegramUserId): ?int
    {
        if ($telegramUserId < 1) {
            return null;
        }
        $row = (new TelegramUserModel())->where('id', $telegramUserId)->first();
        $raw = is_array($row) ? ($row['telegram_id'] ?? null) : (is_object($row) ? ($row->telegram_id ?? null) : null);
        if (! is_numeric($raw) || (int) $raw === 0) {
            return null;
        }

        return (int) $raw;
    }
}

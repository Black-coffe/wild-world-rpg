<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Faction;

use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * V20 (ADR-051) — внести вклад в фракц-проект (фикс. золото). При достижении
 * порога — активация баффа + уведомление ВСЕХ членов фракции. callback `factionDeposit`.
 */
class FactionProjectDepositConfirmAction extends FactionProjectBaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->reply($chatId, 'Пользователь не найден или персонаж не определён.');
        }

        $result = $this->projectService->deposit((int) $character['id']);
        if ($result['ok'] !== true) {
            return $this->reply($chatId, $result['message']);
        }

        $factionId = $result['factionId'];
        $deposit   = $result['deposit'];
        $prefix    = "✅ Вклад внесён: *{$deposit}* 🪙 в общий проект.";

        if ($result['completed'] === true) {
            $pct   = (int) round((1.0 - $this->projectService->craftTimeMultiplier()) * 100);
            $hours = $this->projectService->buffDurationHours();
            $label = self::FACTION_LABELS[$factionId] ?? 'Фракции';
            $prefix .= "\n🎉 *Цель достигнута!* Бафф −{$pct}% к крафту на {$hours} ч активирован для всей фракции.";
            $this->broadcastCompletion($factionId, $label, $pct, $hours);
        }

        $screen = $this->buildProjectScreen($factionId, $prefix);

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $result['completed'] === true ? '🎉 Цель достигнута!' : 'Вклад внесён',
        ]);

        return \App\Services\Notifications\MediaSender::editTextOrSend([
            'chat_id'      => $chatId,
            'message_id'   => $this->callbackQuery->getMessage()->getMessageId(),
            'text'         => $screen['text'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($screen['keyboard']),
        ]);
    }

    /** Уведомить всех членов фракции о завершении цикла проекта. */
    private function broadcastCompletion(int $factionId, string $label, int $pct, int $hours): void
    {
        $text = "🎉 *Проект фракции {$label} завершён!*\n\n"
            . "Совместными усилиями цель достигнута — вся фракция получает *−{$pct}% к времени крафта* на {$hours} ч. "
            . "Самое время крафтить! 🛠️";

        foreach ($this->projectService->factionMemberTelegramIds($factionId) as $tgId) {
            try {
                Request::sendMessage(['chat_id' => $tgId, 'text' => $text, 'parse_mode' => 'Markdown']);
            } catch (\Throwable $e) {
                log_message('error', "[FactionProject] broadcast fail tg={$tgId}: " . $e->getMessage());
            }
        }
    }

    private function reply(int $chatId, string $text): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage(['chat_id' => $chatId, 'text' => $text]);
    }
}

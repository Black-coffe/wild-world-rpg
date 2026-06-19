<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\PVP;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\PVE\BountyService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-135 Ф3b — экран «🎯 Доска розыска» (callback `bountyBoard`).
 *
 * Показывает текущих доминаторов в розыске (живо из активных податей) + личный счётчик
 * охотничьих трофеев и прогресс к титулу. Текстовый (media-off, ADR-020), edit-in-place
 * (ADR-018). Killswitch tribute.enabled+bounty_enabled → при dormant «недоступно»
 * (вход в Развлечениях и так скрыт).
 */
final class BountyBoardAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        if (! $user || ! $character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }

        $svc = new BountyService();
        if (! $svc->enabled()) {
            return MediaSender::editTextOrSend($this->navTarget() + [
                'text'       => '🎯 *Доска розыска* временно недоступна.',
                'parse_mode' => 'Markdown',
            ]);
        }

        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $text   = $this->build($svc, $charId);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    private function build(BountyService $svc, int $charId): string
    {
        $board     = $svc->wantedBoard(10);
        $myClaims  = $svc->claimsBy($charId);
        $threshold = $svc->titleThreshold();

        $text = "🎯 *Доска розыска*\n\n"
            . "_На острове закон один: кто сильнее — берёт долю. Но за каждым, кто гнёт других "
            . "под трофейную подать, идёт охота. Срази угнетателя в полевом бою — и заберёшь трофей охотника._\n\n";

        if ($board === []) {
            $text .= "Сейчас в розыске никого нет — никто не держит трофейную подать над другими.\n\n";
        } else {
            $text .= "*В розыске* (по числу данников):\n";
            $rank = 0;
            foreach ($board as $row) {
                $rank++;
                $self  = $row['master_id'] === $charId ? ' — _это ты_' : '';
                $text .= "{$rank}. *{$row['name']}* — данников: {$row['wanted']}{$self}\n";
            }
            $text .= "\n";
        }

        $text .= "🏹 Твои трофеи охотника: *{$myClaims}*";
        if ($threshold > 0) {
            if ($myClaims >= $threshold) {
                $text .= "\n🎖 Ты заслужил титул *«Охотник за головами»*.";
            } else {
                $left = $threshold - $myClaims;
                $text .= "\n_До титула «Охотник за головами» — ещё {$left}._";
            }
        }

        return $text;
    }
}

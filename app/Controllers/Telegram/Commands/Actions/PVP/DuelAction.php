<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\PVP;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\PVE\ArenaScreenService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * W17 (ADR-071) — PvP-дуэль: opt-in честный бой (stat-equalize), БЕЗ летальных последствий.
 *
 * Callback `duel_<defenderId>` (поле: соседняя клетка) и `arenaDuel_<defenderId>` (E25, ADR-124: с
 * арены, локация не важна). Здоровье, опыт и ресурсы бойцов не меняются.
 *
 * w2-n7-combat-02 (ADR-190): вся операция — `ArenaScreenService::challenge()` (гейты, атомарный
 * анти-спам кулдаун, уравнивание, неизменный `simulateFight`, тай-брейк ADR-073, очки рейтинга, запись
 * `DUEL` в журнал боёв, итог защитнику). Этот handler разбирает колбэк и рисует итог в Telegram;
 * под итогом — «📜 Разбор боя» (карточка журнала).
 */
final class DuelAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();

        $parts      = explode('_', (string) $this->callbackQuery->getData());
        $defenderId = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 0;
        $isArena    = $parts[0] === 'arenaDuel';

        [$user, $attacker] = $this->getUserAndCharacter();
        if (! $user || ! $attacker) {
            return $this->alert('Персонаж не найден.');
        }

        $core = new ArenaScreenService();
        $duel = $core->challenge($attacker, $defenderId, $isArena);
        if (! $duel['ok']) {
            return $this->alert($duel['message']);
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $row = [
            ['text' => '◀️ Я', 'callback_data' => 'character'],
            ['text' => \App\Services\Telegram\BotMenuService::menuLabel('world'), 'callback_data' => 'move'],
        ];
        // W18 (ADR-072): «🏆 Рейтинг» только при активном ладдере (dormant — скрыта).
        if ($core->ladderEnabled()) {
            $row[] = ['text' => '🏆 Рейтинг PvP', 'callback_data' => 'pvpLadder'];
        }
        $rows = [$row];
        if ($duel['battle_id'] !== null) {
            $rows[] = [['text' => '📜 Разбор боя', 'callback_data' => 'battleLog_' . $duel['battle_id']]];
        }

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $duel['text'],
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }

    private function alert(string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => true,
        ]);
        return Request::emptyResponse();
    }
}

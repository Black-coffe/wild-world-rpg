<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\NPC;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\NpcSpawnModel;
use App\Services\NPC\NpcInteractionService;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-089 Фаза 1 — исполнение выбора в экране встречи с NPC.
 *
 * Callback prefix `npcAct_<action>_<spawnId>` (action ∈ attack/kill/rob/trade/talk/ask).
 * Боевые опции через {@see NpcInteractionService::fight} (PvEService сам шлёт детальный
 * результат отдельным сообщением). Не-боевые (talk/ask/trade) — рендерят реплику + меню
 * (NPC остаётся). Killswitch-aware. Caption самодостаточен (media-off).
 */
final class NpcActionChoiceAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->alert('Персонаж не найден.');
        }

        $svc = new NpcInteractionService();
        if (! $svc->enabled()) {
            return $this->alert('Встреча недоступна.');
        }

        // npcAct_<action>_<spawnId>
        $data    = (string) $this->callbackQuery->getData();
        $rest    = substr($data, strlen('npcAct_'));
        $pos     = strrpos($rest, '_');
        if ($pos === false) {
            return $this->alert('Некорректное действие.');
        }
        $action  = substr($rest, 0, $pos);
        $spawnId = (int) substr($rest, $pos + 1);
        $pidRaw  = $character['id'] ?? null;
        $playerId = is_numeric($pidRaw) ? (int) $pidRaw : 0;

        $spawn = (new NpcSpawnModel())->find($spawnId);
        if (! is_array($spawn)) {
            return $this->alert('Незнакомец ушёл.');
        }
        $sCellRaw = $spawn['cell_number'] ?? null;
        $cCellRaw = $character['cell_number'] ?? null;
        $sCell    = is_numeric($sCellRaw) ? (int) $sCellRaw : -1;
        $cCell    = is_numeric($cCellRaw) ? (int) $cCellRaw : -2;
        if ($sCell !== $cCell) {
            return $this->alert('Незнакомец ушёл.');
        }
        $npcIdRaw = $spawn['npc_id'] ?? null;
        $npcId    = is_numeric($npcIdRaw) ? (int) $npcIdRaw : 0;

        switch ($action) {
            case 'attack':
            case 'kill':
                $r    = $svc->fight($playerId, $spawnId);
                $won  = ($r['won'] ?? false) === true;
                $text = $won
                    ? '⚔️ Ты одолел противника. Пустошь стала на одного тише.'
                    : '⚔️ Схватка кончилась не в твою пользу — ты отступил, едва держась на ногах.';
                return $this->finish($text);

            case 'rob':
                $r = $svc->rob($playerId, $spawnId);
                if ($r['outcome'] === 'success') {
                    $gold = $r['gold'] ?? 0;
                    return $this->finish("💰 {$r['line']}\n\nДобыча: *+{$gold}* золота.");
                }
                if ($r['outcome'] === 'fail') {
                    $won  = ($r['fight']['won'] ?? false) === true;
                    $tail = $won ? 'Ты всё же одолел его в драке.' : 'Драка обернулась против тебя — ты отступил.';
                    return $this->finish("💢 {$r['line']}\n\n{$tail}");
                }
                return $this->finish($r['line']);

            case 'talk':
                return $this->reShowMenu($svc, $spawnId, $npcId, '💬 ' . $svc->line($npcId, 'talk'));

            case 'ask':
                return $this->reShowMenu($svc, $spawnId, $npcId, '❓ ' . $svc->line($npcId, 'ask'));

            case 'trade':
                return $this->reShowMenu($svc, $spawnId, $npcId, '🤝 ' . $svc->line($npcId, 'trade'));

            default:
                return $this->alert('Неизвестное действие.');
        }
    }

    /** Терминальный исход (NPC ушёл/повержен): текст + кнопка на карту. */
    private function finish(string $text): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $keyboard = ['inline_keyboard' => [[
            ['text' => '🗺 Карта', 'callback_data' => 'inlineMap'],
            ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
        ]]];

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /** Не-боевой исход: реплика + меню встречи (NPC остаётся). */
    private function reShowMenu(NpcInteractionService $svc, int $spawnId, int $npcId, string $line): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $text = "{$line}\n\nЧто дальше?";
        $keyboard = ['inline_keyboard' => [
            [
                ['text' => '⚔️ Напасть', 'callback_data' => "npcAct_attack_{$spawnId}"],
                ['text' => '🗡 Убить',    'callback_data' => "npcAct_kill_{$spawnId}"],
            ],
            [
                ['text' => '💰 Ограбить', 'callback_data' => "npcAct_rob_{$spawnId}"],
                ['text' => '🤝 Торговать', 'callback_data' => "npcAct_trade_{$spawnId}"],
            ],
            [
                ['text' => '💬 Поговорить', 'callback_data' => "npcAct_talk_{$spawnId}"],
                ['text' => '❓ Спросить',   'callback_data' => "npcAct_ask_{$spawnId}"],
            ],
            [
                ['text' => '🚶 Уйти', 'callback_data' => 'inlineMap'],
            ],
        ]];

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
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

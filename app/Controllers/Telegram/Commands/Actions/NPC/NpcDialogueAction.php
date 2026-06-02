<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\NPC;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\NpcSpawnModel;
use App\Services\NPC\NpcDialogueTreeService;
use App\Services\NPC\NpcInteractionService;
use App\Services\NPC\NpcRelationService;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-089 Фаза 5+ — ветвящийся диалог с именным NPC.
 *
 * Callback `npcDlg_<spawnId>_<nodeKey>_<rel>`: применяет rel (сдвиг отношения за выбранную
 * реплику) и показывает узел nodeKey (текст + кнопки-варианты). node_key='end' / отсутствие
 * узла = конец беседы (возврат к экрану встречи). Старт — `npcDlg_<spawnId>_root_0`.
 * node_key без подчёркиваний (callback парсится по '_'). Caption самодостаточен (media-off).
 */
final class NpcDialogueAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->alert('Персонаж не найден.');
        }
        $svc = new NpcInteractionService();
        if (! $svc->enabled()) {
            return $this->alert('Беседа недоступна.');
        }

        // npcDlg_<spawnId>_<nodeKey>_<rel>
        $parts = explode('_', (string) $this->callbackQuery->getData());
        if (count($parts) < 4) {
            return $this->alert('Некорректная реплика.');
        }
        $spawnId = is_numeric($parts[1]) ? (int) $parts[1] : 0;
        $nodeKey = $parts[2];
        $rel     = is_numeric($parts[3]) ? (int) $parts[3] : 0;

        $cidRaw = $character['id'] ?? null;
        $charId = is_numeric($cidRaw) ? (int) $cidRaw : 0;
        $spawn  = (new NpcSpawnModel())->find($spawnId);
        if (! is_array($spawn)) {
            return $this->alert('Собеседник ушёл.');
        }
        $sCellRaw = $spawn['cell_number'] ?? null;
        $cCellRaw = $character['cell_number'] ?? null;
        $sCell    = is_numeric($sCellRaw) ? (int) $sCellRaw : -1;
        $cCell    = is_numeric($cCellRaw) ? (int) $cCellRaw : -2;
        if ($sCell !== $cCell) {
            return $this->alert('Собеседник ушёл.');
        }
        $npcId = is_numeric($spawn['npc_id'] ?? null) ? (int) $spawn['npc_id'] : 0;

        // Применяем эффект выбранной реплики (rel) на отношение.
        if ($rel !== 0) {
            (new NpcRelationService())->adjustBy($charId, $npcId, $rel);
        }

        $node = (new NpcDialogueTreeService())->node($npcId, $nodeKey);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Конец беседы (узел 'end' или отсутствует) → возврат к экрану встречи.
        if ($nodeKey === 'end' || $node === null) {
            $keyboard = ['inline_keyboard' => [[
                ['text' => '↩️ Вернуться', 'callback_data' => 'npcEncounter'],
            ]]];

            return MediaSender::editTextOrSend($this->navTarget() + [
                'text'         => '_Разговор окончен._',
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        $rows = [];
        foreach ($node['options'] as $opt) {
            $next = $opt['next'] !== '' ? $opt['next'] : 'end';
            $rows[] = [['text' => $opt['label'], 'callback_data' => "npcDlg_{$spawnId}_{$next}_{$opt['rel']}"]];
        }
        $rows[] = [['text' => '↩️ Закончить', 'callback_data' => 'npcEncounter']];

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $node['text'],
            'parse_mode'   => 'Markdown',
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

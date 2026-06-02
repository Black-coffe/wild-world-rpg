<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\NPC;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\NPC\NpcDialogueTreeService;
use App\Services\NPC\NpcInteractionService;
use App\Services\NPC\NpcRelationService;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-089 Фаза 1 — экран встречи с нейтральным NPC.
 *
 * Callback `npcEncounter` (без аргумента): по клетке персонажа находит живого passive-NPC
 * и рендерит меню действий. spawn_id вшивается в callback'и опций. Killswitch
 * `npc.interaction_enabled` — при OFF опция недоступна.
 *
 * Caption самодостаточен (media-off, ADR-«MEDIA-OFF»): имя+описание+вопрос в тексте.
 */
final class NpcEncounterAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->alert('Персонаж не найден.');
        }

        $svc = new NpcInteractionService();
        if (! $svc->enabled()) {
            return $this->alert('Сейчас тут никого нет.');
        }

        $cell = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : 0;
        $npc  = $svc->passiveSpawnOnCell($cell);
        if ($npc === null) {
            return $this->alert('Незнакомец ушёл.');
        }

        $spawnRaw = $npc['spawn_id'] ?? null;
        $npcRaw   = $npc['npc_id'] ?? null;
        $spawnId  = is_numeric($spawnRaw) ? (int) $spawnRaw : 0;
        $npcId    = is_numeric($npcRaw) ? (int) $npcRaw : 0;
        $nameRaw  = $npc['npc_name_ru'] ?? null;
        $nameRu   = is_string($nameRaw) && $nameRaw !== '' ? $nameRaw : 'Незнакомец';

        // ADR-089 Фаза 2: приветствие и доступные действия зависят от отношения NPC к игроку.
        $charId   = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $rel      = new NpcRelationService();
        $attitude = $rel->attitude($charId, $npcId);
        $greet    = $svc->greetingFor($npcId, $attitude);

        // ADR-089 Фаза 3: если встреча произошла в Походе (поход на паузе) — выход возобновляет поход.
        $exitRow = $svc->pausedMarchExists($charId)
            ? [['text' => '🚜 Продолжить поход', 'callback_data' => 'march_resume']]
            : [['text' => '🚶 Уйти', 'callback_data' => 'inlineMap']];

        // ADR-089 Phase 6: диалого-центричная встреча — если включён rich-диалог И у NPC есть
        // дерево, рендерим корневой узел (приветствие + реплики-выборы, действия = act-* исходы
        // внутри диалога). Иначе — легаси-меню 6 кнопок ниже (dormant-совместимо).
        $tree = new NpcDialogueTreeService();
        if ($svc->richDialogueEnabled() && $tree->hasTree($npcId)) {
            $root = $tree->node($npcId, 'root');
            if ($root !== null) {
                $head = "👤 *{$nameRu}*\n\n";
                if ($rel->enabled()) {
                    $st = $rel->standing($charId, $npcId);
                    $head .= "📊 Отношение: _{$st['label']}_\n\n";
                }
                $head .= $root['text'];

                $rows = [];
                foreach ($root['options'] as $opt) {
                    if ($opt['gate'] !== '' && ! $rel->meetsStanding($charId, $npcId, $opt['gate'])) {
                        continue;
                    }
                    $next   = $opt['next'] !== '' ? $opt['next'] : 'end';
                    $rows[] = [['text' => $opt['label'], 'callback_data' => "npcDlg_{$spawnId}_{$next}_{$opt['rel']}"]];
                }
                $rows[] = $exitRow;

                Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

                return MediaSender::editTextOrSend($this->navTarget() + [
                    'text'         => $head,
                    'parse_mode'   => 'Markdown',
                    'reply_markup' => json_encode(['inline_keyboard' => $rows]),
                ]);
            }
        }

        // ADR-089 Фаза 4: NPC-квестгивер — если предлагает доступный квест, показываем «📜 Задание».
        $hasQuest = $svc->offeredQuestFor($charId, $npcId) !== null;

        // ADR-089 Phase 6: rich-диалог рендерится диалого-центрично ВЫШЕ (early return при
        // rich_dialogue_enabled ON). Сюда попадаем только при rich OFF → «Поговорить» = легаси
        // одиночная реплика, чтобы Phase 6 (засеянные деревья) был полностью dormant до активации.
        $talkCb = "npcAct_talk_{$spawnId}";

        $text  = "👤 *{$nameRu}*\n\n";
        // ADR-089 Фаза 5: показываем ступень репутации (только при включённой реактивности).
        if ($rel->enabled()) {
            $standing = $rel->standing($charId, $npcId);
            $text .= "📊 Отношение: _{$standing['label']}_\n\n";
        }
        $text .= "{$greet}\n\n";
        $text .= 'Что будешь делать?';

        if ($attitude === NpcRelationService::HOSTILE) {
            // Враждебный NPC не желает говорить/торговать — только бой или уход.
            $keyboard = ['inline_keyboard' => [
                [
                    ['text' => '⚔️ Напасть', 'callback_data' => "npcAct_attack_{$spawnId}"],
                    ['text' => '🗡 Убить',    'callback_data' => "npcAct_kill_{$spawnId}"],
                ],
                $exitRow,
            ]];
        } else {
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
                    ['text' => '💬 Поговорить', 'callback_data' => $talkCb],
                    ['text' => '❓ Спросить',   'callback_data' => "npcAct_ask_{$spawnId}"],
                ],
            ]];
            if ($hasQuest) {
                $keyboard['inline_keyboard'][] = [['text' => '📜 Задание', 'callback_data' => "npcAct_quest_{$spawnId}"]];
            }
            $keyboard['inline_keyboard'][] = $exitRow;
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

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

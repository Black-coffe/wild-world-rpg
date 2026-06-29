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
 * ADR-089 Фаза 5+ / Phase 6 — ветвящийся диалог с NPC.
 *
 * Callback `npcDlg_<spawnId>_<nodeKey>_<rel>`: применяет rel (сдвиг отношения за выбранную
 * реплику) и показывает узел nodeKey (текст + кнопки-варианты, отфильтрованные по gate/standing).
 * node_key='end'/отсутствие узла = конец беседы. Старт — `npcDlg_<spawnId>_root_0`.
 *
 * Phase 6 — реплики-ИСХОДЫ (node_key через дефис, не ломает парс по '_'):
 *   act-quest  → принять квест (хук ADR-088)
 *   act-rob    → ограбить · act-fight → напасть · act-trade → торговля
 *   act-leave  → закончить
 *   act-provoke→ применить rel, затем РЕАКЦИЯ NPC: hostile + слабее → побег; иначе → NPC нападает.
 *
 * Caption самодостаточен (media-off). node_key без подчёркиваний.
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

        $cidRaw  = $character['id'] ?? null;
        $charId  = is_numeric($cidRaw) ? (int) $cidRaw : 0;
        $spawn   = (new NpcSpawnModel())->find($spawnId);
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
        $npcId   = is_numeric($spawn['npc_id'] ?? null) ? (int) $spawn['npc_id'] : 0;
        $inMarch = $svc->pausedMarchExists($charId);

        // Применяем эффект выбранной реплики (rel) на отношение ДО исхода/ветки.
        if ($rel !== 0) {
            (new NpcRelationService())->adjustBy($charId, $npcId, $rel);
        }

        // Phase 6 — реплика-исход (act-*).
        if (str_starts_with($nodeKey, 'act-')) {
            // ADR-101 safe-zone guard: боевые исходы (грабёж/драка/провокация) внутри безопасной
            // зоны поселения запрещены, даже если узел дерева их предлагает — пакт «здесь не
            // нападают». Легаси-меню (NpcEncounterAction) скрывает их в safe-зоне, но rich-ветка
            // диалога шла мимо этого гейта → теперь блок построчно в dispatchOutcome.
            $safeZone = (new \App\Services\Settlement\SettlementZoneService())->isSafeZone(
                $cCell,
                is_numeric($character['coordinate_x'] ?? null) ? (int) $character['coordinate_x'] : null,
                is_numeric($character['coordinate_y'] ?? null) ? (int) $character['coordinate_y'] : null
            );

            return $this->dispatchOutcome(substr($nodeKey, 4), $svc, $charId, $npcId, $spawnId, $inMarch, $safeZone);
        }

        $node = (new NpcDialogueTreeService())->node($npcId, $nodeKey);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Конец беседы (узел 'end' или отсутствует) → возврат к экрану встречи.
        if ($nodeKey === 'end' || $node === null) {
            return $this->endTalk($inMarch);
        }

        return $this->renderNode($node, $spawnId, $charId, $npcId, $inMarch);
    }

    /**
     * Phase 6 — исход реплики act-*.
     */
    private function dispatchOutcome(string $outcome, NpcInteractionService $svc, int $charId, int $npcId, int $spawnId, bool $inMarch, bool $safeZone = false): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // ADR-101 — безопасная зона поселения: грабёж/драка/провокация запрещены (пакт). Возврат
        // к разговору, NPC остаётся. Не-боевые исходы (квест/торговля/уход) проходят как обычно.
        if ($safeZone && in_array($outcome, ['rob', 'fight', 'provoke'], true)) {
            return $this->backToDialogue(
                '🕊 Это *безопасная зона* поселения — здесь не нападают и не грабят. Таков уговор.',
                $spawnId
            );
        }

        switch ($outcome) {
            case 'leave':
                return $this->endTalk($inMarch);

            case 'quest':
                $q = $svc->acceptOfferedQuest($charId, $npcId);
                if ($q['ok'] !== true) {
                    return $this->backToDialogue('У него сейчас нет работы для тебя.', $spawnId);
                }
                $title  = is_string($q['title'] ?? null) && $q['title'] !== '' ? $q['title'] : 'Задание';
                $reward = is_numeric($q['reward'] ?? null) ? (int) $q['reward'] : 0;
                $descr  = is_string($q['description'] ?? null) ? $q['description'] : '';
                $t = "📜 *Задание принято: {$title}*\n\n";
                if ($descr !== '') {
                    $t .= "{$descr}\n\n";
                }
                if ($reward > 0) {
                    $t .= "🏆 Награда: *{$reward}* золота\n\n";
                }
                $t .= 'Отслеживай в «🚀 Активные квесты».';
                return $this->finish($t, $inMarch);

            case 'rob':
                $r = $svc->rob($charId, $spawnId);
                if ($r['outcome'] === 'success') {
                    $gold = is_numeric($r['gold'] ?? null) ? (int) $r['gold'] : 0;
                    return $this->finish("💰 {$r['line']}\n\nДобыча: *+{$gold}* золота.", $inMarch);
                }
                if ($r['outcome'] === 'fail') {
                    $won  = (($r['fight']['won'] ?? false) === true);
                    $tail = $won ? 'Ты всё же одолел его в драке.' : 'Драка обернулась против тебя — ты отступил.';
                    return $this->finish("💢 {$r['line']}\n\n{$tail}", $inMarch);
                }
                return $this->finish($r['line'], $inMarch);

            case 'fight':
                $f   = $svc->fight($charId, $spawnId);
                $won = ($f['won'] ?? false) === true;
                return $this->finish(
                    $won
                        ? '⚔️ Ты одолел противника. Пустошь стала на одного тише.'
                        : '⚔️ Схватка кончилась не в твою пользу — ты отступил, едва держась на ногах.',
                    $inMarch
                );

            case 'trade':
                return $this->backToDialogue('🤝 ' . $svc->line($npcId, 'trade'), $spawnId);

            case 'provoke':
                return $this->provoke($svc, $charId, $npcId, $spawnId, $inMarch);

            default:
                return $this->backToDialogue('…', $spawnId);
        }
    }

    /**
     * Phase 6 «тонкая грань» — провокация. rel уже применён выше; смотрим итоговый attitude.
     * hostile + NPC слабее (и flee включён) → побег; hostile иначе → NPC нападает; не hostile → напряжение.
     */
    private function provoke(NpcInteractionService $svc, int $charId, int $npcId, int $spawnId, bool $inMarch): ServerResponse
    {
        $rel      = new NpcRelationService();
        $attitude = $rel->attitude($charId, $npcId);

        if ($attitude !== NpcRelationService::HOSTILE) {
            // Ещё не перешёл черту — только напряжение, NPC остаётся.
            return $this->backToDialogue('😠 Лицо собеседника каменеет. «Выбирай слова, выживший.»', $spawnId);
        }

        // Перешёл черту во враждебность.
        if ($svc->fleeEnabled() && $svc->npcWeakerThanPlayer($charId, $npcId)) {
            $svc->npcFlees($spawnId);
            return $this->finish('🏃 Он оценивает тебя — и не находит, чем ответить. Пятится, отворачивается и растворяется в пыли. Ты упустил его.', $inMarch);
        }

        // NPC сильнее/равный — бросается в бой по своей инициативе.
        $f   = $svc->fight($charId, $spawnId);
        $won = ($f['won'] ?? false) === true;
        return $this->finish(
            $won
                ? '⚔️ NPC бросился на тебя первым — но ты оказался сильнее. Он повержен.'
                : '⚔️ Ты переступил черту — NPC напал первым. Схватка обернулась против тебя, ты отступил.',
            $inMarch
        );
    }

    /**
     * Рендер узла: текст + кнопки-реплики (с фильтром по gate/standing) + «Закончить».
     *
     * @param array{text:string, options:list<array{label:string, next:string, rel:int, gate:string}>} $node
     */
    private function renderNode(array $node, int $spawnId, int $charId, int $npcId, bool $inMarch): ServerResponse
    {
        $rel  = new NpcRelationService();
        $rows = [];
        foreach ($node['options'] as $opt) {
            if ($opt['gate'] !== '' && ! $rel->meetsStanding($charId, $npcId, $opt['gate'])) {
                continue; // ступень отношения не достигнута — реплика скрыта
            }
            $next   = $opt['next'] !== '' ? $opt['next'] : 'end';
            $rows[] = [['text' => $opt['label'], 'callback_data' => "npcDlg_{$spawnId}_{$next}_{$opt['rel']}"]];
        }
        $rows[] = $inMarch
            ? [['text' => '🚜 Продолжить поход', 'callback_data' => 'march_resume']]
            : [['text' => '🚶 Уйти', 'callback_data' => 'move']];

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $node['text'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }

    /** Не-терминальный исход: реплика + кнопка вернуться к разговору (NPC остаётся). */
    private function backToDialogue(string $text, int $spawnId): ServerResponse
    {
        $keyboard = ['inline_keyboard' => [
            [['text' => '💬 Продолжить разговор', 'callback_data' => "npcDlg_{$spawnId}_root_0"]],
            [['text' => '🚶 Уйти', 'callback_data' => 'move']],
        ]];

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /** Конец беседы → возврат к экрану встречи. */
    private function endTalk(bool $inMarch): ServerResponse
    {
        $keyboard = ['inline_keyboard' => [[
            ['text' => '↩️ Вернуться', 'callback_data' => 'npcEncounter'],
        ]]];
        if ($inMarch) {
            $keyboard['inline_keyboard'][] = [['text' => '🚜 Продолжить поход', 'callback_data' => 'march_resume']];
        }

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => '_Разговор окончен._',
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /** Терминальный исход (квест/грабёж/бой/побег): текст + выход (карта/персонаж или поход). */
    private function finish(string $text, bool $inMarch): ServerResponse
    {
        $keyboard = ['inline_keyboard' => [
            $inMarch
                ? [['text' => '🚜 Продолжить поход', 'callback_data' => 'march_resume']]
                : [
                    ['text' => '🗺 Карта', 'callback_data' => 'move'],
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
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

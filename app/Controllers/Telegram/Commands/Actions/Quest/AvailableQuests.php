<?php

namespace App\Controllers\Telegram\Commands\Actions\Quest;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;
use App\Models\QuestModel;
use App\Models\CharacterModel;
use App\Models\CharacterFactionModel;

class AvailableQuests extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $questModel = new QuestModel();
        $characterModel = new CharacterModel();

        $characterId = $characterModel->getCharacterIdByTelegramId($chatId);
        $character = $characterModel->find($characterId);

        if (!$character) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Персонаж не найден.',
                'parse_mode' => 'Markdown',
            ]);
        }

        // E27 (ADR-126): классификация доступно/заблокировано вынесена в QuestOverviewService —
        // единый источник для этого экрана и дашборда «📜 Задания» (без дрейфа двух копий
        // фильтрации + без per-quest builder-state-quirk: шаги префетчатся одним запросом).
        $chain         = new \App\Services\Quest\QuestChainService();
        // ADR-088 Фаза 3: фракция персонажа для гейтинга фракционных квестов (0/5 = нет).
        $charFactionId = (new CharacterFactionModel())->getFactionId((int) $characterId);

        $levelRaw        = $character['level'] ?? null;
        $level           = is_numeric($levelRaw) ? (int) $levelRaw : 1;
        $classified      = (new \App\Services\Quest\QuestOverviewService())
            ->classifyQuests($level, (int) $characterId, $charFactionId);
        $availableQuests = $classified['available'];
        $lockedQuests    = $classified['locked'];

        // W11 (ADR-067): pending-развилки (branching включён) — выбор пути приоритетно сверху.
        $pendingBranches = $chain->pendingBranchesForCharacter((int) $characterId);

        if (empty($availableQuests) && empty($lockedQuests) && empty($pendingBranches)) {
            $text = "На данный момент нет доступных квестов. Проверьте позже!";
        } else {
            $text = "*📜 Доступные квесты:*\n\n";
            // W11: развилки цепочек — выбор необратим, показываем первыми.
            if (! empty($pendingBranches)) {
                $text .= "*🔀 Развилка цепочки!* Выбери путь — решение необратимо:\n";
                foreach ($pendingBranches as $pb) {
                    $bp = $pb['branch_point_ru'] !== '' ? $pb['branch_point_ru'] : 'развилки';
                    $text .= "\n_После «{$bp}»:_\n";
                    foreach ($pb['options'] as $opt) {
                        $text .= "• {$opt['label']}\n";
                    }
                }
                $text .= "\n";
            }
            if (empty($availableQuests) && empty($lockedQuests)) {
                $text .= "_Других открытых квестов сейчас нет._\n";
            } elseif (empty($availableQuests)) {
                $text .= "_Сейчас открытых квестов нет._\n";
            }
            foreach ($availableQuests as $quest) {
                $rewardType = $this->translateRewardType($quest['reward_type']);
                $titleRu    = is_string($quest['title_ru'] ?? null) ? $quest['title_ru'] : '';
                $reward     = is_numeric($quest['reward'] ?? null) ? (string) $quest['reward'] : '0';
                $text .= "🔹 *{$titleRu}* || Награда: *{$reward}* (_{$rewardType}_)\n";
            }
            // V11: заблокированные звенья цепочки — видно цель, но без кнопки.
            if (! empty($lockedQuests)) {
                $text .= "\n*🔒 Откроются позже (цепочка):*\n";
                foreach ($lockedQuests as $lq) {
                    $prereqTitle = $this->prerequisiteTitleRu($questModel, $lq['prereq']);
                    $lqTitle     = is_string($lq['quest']['title_ru'] ?? null) ? $lq['quest']['title_ru'] : '';
                    $text .= "🔒 *{$lqTitle}* — после квеста «{$prereqTitle}»\n";
                }
            }
            if (! empty($availableQuests)) {
                $text .= "\nВыбери квест и отправляйся к приключениям!";
            }
        }

        $keyboard = $this->generateQuestKeyboard($availableQuests, $pendingBranches);
        //log_message('debug', "keyboard: " . print_r($keyboard, true));
        // Ответ на callback запрос, чтобы убрать часики на кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // #12 edit-in-place (ADR-018): список доступных квестов — навигация → редактируем
        // сообщение, на котором нажата кнопка (fallback на новое при ошибке/клике с photo-экрана).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function translateRewardType($type)
    {
        $translations = [
            'gold' => 'золото',
            'experience' => 'опыт',
            'items' => 'предметы',  // Пример добавления другого типа награды
        ];

        return $translations[$type] ?? $type;  // Возвращаем перевод или оригинальное значение, если перевод отсутствует
    }

    /**
     * V11 — русское название квеста-предусловия (по title_en) для тизера цепочки.
     */
    private function prerequisiteTitleRu(QuestModel $questModel, ?string $prereqTitleEn): string
    {
        if ($prereqTitleEn === null || $prereqTitleEn === '') {
            return '???';
        }
        $row = $questModel->where('title_en', $prereqTitleEn)->first();
        return is_array($row) && isset($row['title_ru']) && is_string($row['title_ru']) && $row['title_ru'] !== ''
            ? $row['title_ru']
            : $prereqTitleEn;
    }

    /**
     * @param list<array{branch_point_ru:string,options:list<array{quest_id:int,title_en:string,title_ru:string,label:string}>}> $pendingBranches
     */
    private function generateQuestKeyboard($quests, array $pendingBranches = [])
    {
        $keyboard = ['inline_keyboard' => []];

        // W11 (ADR-067): кнопки выбора веток развилки — первыми, паковка 2/строку.
        foreach ($pendingBranches as $pb) {
            $branchRow = [];
            foreach ($pb['options'] as $opt) {
                $branchRow[] = ['text' => $opt['label'], 'callback_data' => 'questBranch_' . $opt['quest_id']];
                if (count($branchRow) === 2) {
                    $keyboard['inline_keyboard'][] = $branchRow;
                    $branchRow = [];
                }
            }
            if (! empty($branchRow)) {
                $keyboard['inline_keyboard'][] = $branchRow;
            }
        }

        $row = [];
        foreach ($quests as $quest) {
            $row[] = ['text' => $quest['title_ru'], 'callback_data' => 'questStart' . $quest['title_en']];
            if (count($row) == 2) {
                $keyboard['inline_keyboard'][] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $keyboard['inline_keyboard'][] = $row;
        }

        // Add standard buttons
        $keyboard['inline_keyboard'][] = [
            ['text' => '📜 Квесты и задания', 'callback_data' => 'questAndTask'],
            ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
        ];

        return $keyboard;
    }
}

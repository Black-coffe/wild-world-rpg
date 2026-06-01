<?php

namespace App\Controllers\Telegram\Commands\Actions\Quest;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use App\Models\CharacterModel;

class AvailableQuests extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $questModel = new QuestModel();
        $questStepModel = new QuestStepsModel();
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

        $quests = $questModel->getAvailableQuests($character['level'], $characterId); // Передача characterId
        // Используем:
        $activeQuestSteps = $questStepModel->getActiveQuestStepsForCharacter($characterId, $quests);

        // V11 (ADR-036): цепочки квестов — квест с prerequisite_quest доступен только
        // после завершения предусловия. Собираем завершённые квесты персонажа один раз.
        $chain           = new \App\Services\Quest\QuestChainService();
        $completedTitles = $questModel->getCompletedQuestTitles((int) $characterId);

        $availableQuests = [];
        $lockedQuests    = [];
        foreach ($quests as $quest) {
            // Проверяем наличие соответствующей записи в таблице quest_steps
            $questStep = $questStepModel->where('quest_id', $quest['id'])
                ->where('character_id', $characterId)
                ->first();
            // Если запись найдена, то пропускаем текущий квест
            if ($questStep) {
                continue;
            }
            if (in_array($quest['id'], $activeQuestSteps)) {
                continue;
            }

            // V12 (ADR-037): objective-квесты (strategic capture + этапы цепочек) —
            // авто-управляемые (стартуют/завершаются через handler'ы, не вручную).
            // ADR-088: ИСКЛЮЧЕНИЕ — расширенные STANDALONE «корни» (collect_resource /
            // building_level / npc_kills / char_level / craft_item без prerequisite,
            // killswitch quests.extended_enabled ON) показываем как startable.
            if (!empty($quest['objective_type'])) {
                if ($chain->isExtendedStartableRoot($quest)) {
                    $availableQuests[] = $quest;
                }
                continue;
            }

            // V11: предусловие не выполнено → квест заблокирован (показываем тизер цепочки).
            $prereq = $chain->prerequisiteOf($quest);
            if (! $chain->prerequisiteMet($prereq, $completedTitles)) {
                $lockedQuests[] = ['quest' => $quest, 'prereq' => $prereq];
                continue;
            }

            $availableQuests[] = $quest;
        }

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
                $text .= "🔹 *{$quest['title_ru']}* || Награда: *{$quest['reward']}* (_{$rewardType}_)\n";
            }
            // V11: заблокированные звенья цепочки — видно цель, но без кнопки.
            if (! empty($lockedQuests)) {
                $text .= "\n*🔒 Откроются позже (цепочка):*\n";
                foreach ($lockedQuests as $lq) {
                    $prereqTitle = $this->prerequisiteTitleRu($questModel, $lq['prereq']);
                    $text .= "🔒 *{$lq['quest']['title_ru']}* — после квеста «{$prereqTitle}»\n";
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

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
            // Не показываем их с manual start-кнопкой (раньше были dead-кнопки).
            if (!empty($quest['objective_type'])) {
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

        if (empty($availableQuests) && empty($lockedQuests)) {
            $text = "На данный момент нет доступных квестов. Проверьте позже!";
        } else {
            $text = "*📜 Доступные квесты:*\n\n";
            if (empty($availableQuests)) {
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

        $keyboard = $this->generateQuestKeyboard($availableQuests);
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

    private function generateQuestKeyboard($quests)
    {
        $keyboard = ['inline_keyboard' => []];
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

<?php

namespace App\Controllers\Telegram\Commands\Actions\Quest;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Quest\QuestListService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class AvailableQuests extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // w2-n5-deeds-02: персонаж — по отправителю (BaseAction), не по chat_id (у веб-моста чат
        // виртуальный).
        [, $character] = $this->getUserAndCharacter();
        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        if ($characterId <= 0) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Персонаж не найден.',
                'parse_mode' => 'Markdown',
            ]);
        }

        // E27 (ADR-126) / ADR-088 / W11 (ADR-067): классификация доступно/заблокировано, фракционный
        // гейт и развилки — в QuestListService (ядро, общее с вебом, w2-n5-deeds-02); здесь только рендер.
        $lists           = new QuestListService();
        $rows            = $lists->available($characterId);
        $availableQuests = array_values(array_filter($rows, static fn (array $q): bool => ! $q['locked']));
        $lockedQuests    = array_values(array_filter($rows, static fn (array $q): bool => $q['locked']));
        $pendingBranches = $lists->branches($characterId);

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
                $text .= "🔹 *{$quest['title_ru']}* || Награда: *{$quest['reward']}* (_{$quest['reward_type_ru']}_)\n";
            }
            // V11: заблокированные звенья цепочки — видно цель, но без кнопки.
            if (! empty($lockedQuests)) {
                $text .= "\n*🔒 Откроются позже (цепочка):*\n";
                foreach ($lockedQuests as $lq) {
                    $text .= "🔒 *{$lq['title_ru']}* — {$lq['lock_reason']}\n";
                }
            }
            if (! empty($availableQuests)) {
                $text .= "\nВыбери квест и отправляйся к приключениям!";
            }
        }

        $keyboard = $this->generateQuestKeyboard($availableQuests, $pendingBranches);
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

    /**
     * @param list<array{title_en: string, title_ru: string}> $quests
     * @param list<array{branch_point_ru:string,options:list<array{quest_id:int,title_en:string,title_ru:string,label:string}>}> $pendingBranches
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function generateQuestKeyboard(array $quests, array $pendingBranches = []): array
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
            ['text' => '◀️ Я', 'callback_data' => 'character'],
        ];

        return $keyboard;
    }
}

<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Player\TeleportCostService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

// <-- Импортируем сервис, отвечающий за проверку золота

class TeleportAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => '🤖 Это снова я – *Роби*!\n\nПользователь не найден в базе данных или персонаж не определён.',
                'parse_mode' => 'Markdown'
            ]);
        }

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse(); // Переезд есть, сервис уже отписался
        }

        $craftedItemModel   = new CraftedItemsModel();
        $craftedItemLogModel= new CraftedItemsLogModel();
        $characterModel     = new CharacterModel();

        // (1) Проверяем наличие портативного телепорта
        $portableTeleport = $craftedItemModel->where('name_eng', 'PortableTeleport')->first();
        $hasPortableTeleport = false;
        if ($portableTeleport) {
            $hasPortableTeleport = $craftedItemLogModel
                    ->where('crafted_item_id', $portableTeleport['id'])
                    ->where('character_id', $character['id'])
                    ->countAllResults() > 0;
        }

        // (2) Проверяем опыт
        $character  = $characterModel->find($character['id']);
        $hasEnoughExperience = ($character['experience'] ?? 0) > 1.01;

        // (3) Проверяем золото через сервис
        $teleportCostService = new TeleportCostService();
        $canPayGold = $teleportCostService->canPayTeleport($character);
        // если true — значит золота достаточно для оплаты телепорта

        // (4) Формируем различные сценарии
        // Чтоб было удобнее, будем последовательно собирать текст и кнопки.
        $text = "🤖 Это снова я – *Роби*!\n\n";
        $keyboard = ['inline_keyboard' => []];

        // Логика определения доступных способов телепорта
        // -----------------------------------------------
        // 1) У нас может быть портативный телепорт
        // 2) Может быть достаточно опыта
        // 3) Может быть достаточно золота

        $availableButtons = [];  // массив кнопок, которые покажем

        // Портативный телепорт
        if ($hasPortableTeleport) {
            // Добавим кнопку "Портативный телепорт"
            $availableButtons[] = [
                'text' => '📡 Портативный телепорт',
                'callback_data' => 'TeleportUse_Portable'
            ];
        }

        // Телепорт за опыт
        if ($hasEnoughExperience) {
            $availableButtons[] = [
                'text' => '🚜 Телепорт за опыт',
                'callback_data' => 'TeleportUse_WithExperience'
            ];
        }

        // Телепорт за золото
        if ($canPayGold) {
            $availableButtons[] = [
                'text' => '🪙 Телепорт за золото',
                'callback_data' => 'TeleportUse_WithGold'
            ];
        }

        // Если вообще хоть что-то доступно
        if (!empty($availableButtons)) {
            // Формируем текст, описывающий доступные варианты
            $text .= "Ты можешь телепортироваться несколькими способами:\n\n";

            if ($hasPortableTeleport) {
                $text .= "*Портативный телепорт*\n_отнимет 1 единицу у твоего устройства_\n\n";
            }
            if ($hasEnoughExperience) {
                $text .= "*Телепорт за опыт*\n_отнимет 1 единицу опыта у твоего персонажа_\n\n";
            }
            if ($canPayGold) {
                // Узнаем стоимость
                $cost = $teleportCostService->calculateTeleportCost($character['level']);
                // Форматируем с пробелами
                $formattedCost = number_format($cost, 0, '.', ' ');
                $text .= "*Телепорт за золото*\n_снимет {$formattedCost} золота с твоего баланса_\n\n";
            }

            $text .= "Что выбираешь?";
            // Вставляем наши доступные кнопки в одну строку или в несколько
            // Для наглядности создаём одну "строку"
            $keyboard['inline_keyboard'][] = $availableButtons;

        } else {
            // Если нет ни портативного, ни достаточно опыта, ни достаточно золота
            $text .= "К сожалению, у тебя нет ни портативного телепорта, ни достаточно опыта, ни достаточно золота для телепортации.";

            // Предложим альтернативные действия
            $keyboard['inline_keyboard'][] = [
                ['text' => '🚜 Переехать', 'callback_data' => 'move'],
                ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
            ];
        }

        // (5) Возвращаем ответ
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}

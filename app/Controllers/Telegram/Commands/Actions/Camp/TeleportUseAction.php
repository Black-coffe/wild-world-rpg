<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\MapModel;
use App\Services\Player\TeleportCostService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class TeleportUseAction extends BaseAction
{
    protected $teleportCostService;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->teleportCostService = new TeleportCostService();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'       => "🤖 Это снова я – *Роби*!\n\nПользователь или персонаж не найден.",
                'parse_mode' => 'Markdown'
            ]);
        }

        // Блокируем, если идёт переезд (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse();
        }

        // Смотрим, какую кнопку нажали
        $callbackData = $this->callbackQuery->getData();

        switch ($callbackData) {
            case 'TeleportUse_Portable':
                return $this->usePortableTeleport($character);

            case 'TeleportUse_WithExperience':
                return $this->useExperienceTeleport($character);

            case 'TeleportUse_WithGold':
                return $this->useGoldTeleport($character);

            // === Новый кейс для рюкзака ===
            case 'TeleportUse_Backpack':
                return $this->useBackpackTeleport($character);

            default:
                Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
                return Request::sendMessage([
                    'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text'       => "🤖 Это снова я – *Роби*!\n\nНеизвестная команда телепортации.",
                    'parse_mode' => 'Markdown'
                ]);
        }
    }

    /**
     * Телепорт рюкзаком (TeleportBackpack), раз в 60 минут.
     */
    private function useBackpackTeleport(array $character): ServerResponse
    {
        $craftedItemModel    = new CraftedItemsModel();
        $craftedItemLogModel = new CraftedItemsLogModel();
        $characterModel      = new CharacterModel();
        $claimedCellModel    = new ClaimedCellModel();
        $mapModel            = new MapModel();

        // 1) Ищем запись о рюкзаке в crafted_items
        $backpackItem = $craftedItemModel->where('name_eng', 'TeleportBackpack')->first();
        if (!$backpackItem) {
            // Нет вообще такого предмета в БД
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Ошибка: предмет 'TeleportBackpack' не найден в базе.",
            ]);
        }

        // 2) Ищем запись у игрока в crafted_items_log (кол-во, durability_count и custom_setting)
        $backpackLog = $craftedItemLogModel
            ->where('crafted_item_id', $backpackItem['id'])
            ->where('character_id', $character['id'])
            ->first();

        if (!$backpackLog) {
            // У игрока нет рюкзака
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "У тебя нет рюкзака для телепорта!",
                'parse_mode' => 'Markdown',
            ]);
        }

        // 3) Проверяем прочность (durability_count) и количество (quantity)
        if (($backpackLog['quantity'] < 1) || ($backpackLog['durability_count'] < 1)) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Рюкзак больше не пригоден для телепортации (нет зарядов).",
            ]);
        }

        // 4) Парсим custom_setting (JSON)
        $customData = [];
        if (!empty($backpackLog['custom_setting'])) {
            // Преобразуем JSON -> ассоциативный массив
            $customData = json_decode($backpackLog['custom_setting'], true);
            if (!is_array($customData)) {
                $customData = [];
            }
        }
        $lastUsedAtStr = $customData['lastUsedAt'] ?? null;

        // Проверяем, был ли телепорт в прошлом
        if ($lastUsedAtStr) {
            $lastUsedTime = new \DateTime($lastUsedAtStr);
            $now          = new \DateTime();

            // Считаем разницу
            $diff = $now->diff($lastUsedTime);
            // Получаем общее кол-во минут
            $minutesPassed = $diff->days * 24 * 60
                + $diff->h * 60
                + $diff->i; // i — минуты

            // Если прошло меньше 60 минут, запрещаем
            if ($minutesPassed < 60) {
                $remaining = 60 - $minutesPassed;
                Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text'    => "Ты уже использовал рюкзак! Повторный телепорт будет доступен через ~{$remaining} мин.",
                ]);
            }
        }

        // 5) Если мы здесь — значит можно телепортировать
        //    Ставим новое время использования
        $customData['lastUsedAt'] = (new \DateTime())->format('Y-m-d H:i:s');

        // Обновляем запись: уменьшаем durability_count на 1, либо quantity
        $newDurability = $backpackLog['durability_count'] - 1;

        // Если прочность теперь 0, проверяем qty. Если qty>1, можно списать одну штуку...
        if ($newDurability <= 0) {
            if ($backpackLog['quantity'] > 1) {
                // У игрока было несколько таких рюкзаков. Уменьшаем количество на 1,
                // а durability_count для «нового» рюкзака можно вернуть к дефолту,
                // либо хранить 0 — зависит от вашей игровой логики.
                $craftedItemLogModel->update($backpackLog['id'], [
                    'quantity'       => $backpackLog['quantity'] - 1,
                    // «Обнуляем» настройки? Либо можно перенести customData
                    'durability_count' => 0,
                    'custom_setting'   => null,
                ]);
            } else {
                // Если это был последний экземпляр и прочность ушла в 0, удаляем
                $craftedItemLogModel->delete($backpackLog['id']);
            }
        } else {
            // Иначе просто сохраняем новое значение durability_count
            $craftedItemLogModel->update($backpackLog['id'], [
                'durability_count' => $newDurability,
                'custom_setting'   => json_encode($customData),
            ]);
        }

        // 6) Собственно телепортируем на базу
        $claimedCell = $claimedCellModel->where('character_id', $character['id'])->first();
        if (!$claimedCell) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "У тебя нет базы, куда телепортироваться!",
            ]);
        }
        $mapRow = $mapModel->where('cell_number', $claimedCell['map_cell_id'])->first();
        if (!$mapRow) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Ошибка: не найдена ячейка базы на карте.",
            ]);
        }

        // Обновляем координаты персонажа
        $characterModel->update($character['id'], [
            'cell_number' => $claimedCell['map_cell_id'],
            'biome_id'    => $mapRow['biome_id'],
        ]);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // 7) Сообщаем об успехе
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🏠 База',         'callback_data' => 'Base'],
                    ['text' => '🧑‍🌾 Действия 🛠️','callback_data' => 'characterActions'],
                ],
            ]
        ];

        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => "Ты успешно использовал *Рюкзак телепорт*!\nТеперь у тебя осталось зарядов: *{$newDurability}*.\n\nСледующий телепорт будет доступен через 60 минут.",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Телепорт за золото
     */
    private function useGoldTeleport(array $character): ServerResponse
    {
        $characterModel  = new CharacterModel();
        $claimedCellModel = new ClaimedCellModel();
        $mapModel        = new MapModel();

        // Актуализируем персонажа из БД (вдруг gold изменился)
        $charRow = $characterModel->find($character['id']);
        if (!$charRow) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Ошибка! Персонаж не найден.",
            ]);
        }

        // Получаем стоимость телепорта
        $cost = $this->teleportCostService->calculateTeleportCost((int)$charRow['level']);

        // Проверяем, достаточно ли золота
        if ((int)$charRow['gold'] < $cost) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Недостаточно золота! Нужно {$cost}, а у тебя всего {$charRow['gold']}.",
            ]);
        }

        // Находим базу
        $claimedCell = $claimedCellModel->where('character_id', $charRow['id'])->first();
        if (!$claimedCell) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "У тебя нет базы для телепорта!",
                'parse_mode' => 'Markdown'
            ]);
        }

        $mapRow = $mapModel->where('cell_number', $claimedCell['map_cell_id'])->first();
        if (!$mapRow) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Ошибка: не найдена ячейка базы на карте.",
            ]);
        }

        // Списываем золото
        $newGold = (int)$charRow['gold'] - $cost;

        // Обновляем координаты персонажа → телепорт
        $characterModel->update($charRow['id'], [
            'gold'       => $newGold,
            'cell_number'=> $claimedCell['map_cell_id'],
            'biome_id'   => $mapRow['biome_id'],
        ]);

        // Форматируем цифры с разделителями по 3
        $formattedCost = number_format($cost, 0, '.', ' ');
        $formattedGold = number_format($newGold, 0, '.', ' ');

        $text = "Ты успешно телепортировался за золото!\n\n"
            . "Списано: {$formattedCost} 💰\n"
            . "Остаток золота: {$formattedGold} 💰";

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Телепорт с помощью портативного устройства
     */
    private function usePortableTeleport($character): ServerResponse
    {
        $craftedItemModel = new CraftedItemsModel();
        $craftedItemLogModel = new CraftedItemsLogModel();
        $characterModel = new CharacterModel();
        $claimedCellModel = new ClaimedCellModel();
        $mapModel = new MapModel();

        // Проверяем наличие портативного телепорта
        $portableTeleport = $craftedItemModel->where('name_eng', 'PortableTeleport')->first();

        if ($portableTeleport) {
            $hasPortableTeleport = $craftedItemLogModel
                ->where('crafted_item_id', $portableTeleport['id'])
                ->where('character_id', $character['id'])
                ->first();

            if ($hasPortableTeleport) {
                // Получаем данные о местоположении базы
                $claimedCell = $claimedCellModel->where('character_id', $character['id'])->first();
                if ($claimedCell) {
                    // Получаем данные о ячейке на карте
                    $mapRow = $mapModel->where('cell_number', $claimedCell['map_cell_id'])->first();

                    if ($mapRow) {
                        // Обновляем местоположение персонажа
                        $characterModel->update($character['id'], [
                            'cell_number' => $claimedCell['map_cell_id'],
                            'biome_id' => $mapRow['biome_id']
                        ]);

                        // Логика использования портативного телепорта
                        if ($hasPortableTeleport['durability_count'] > 1) {
                            // Уменьшаем счетчик прочности на 1
                            $craftedItemLogModel->update($hasPortableTeleport['id'], [
                                'durability_count' => $hasPortableTeleport['durability_count'] - 1
                            ]);
                        } else {
                            if ($hasPortableTeleport['quantity'] > 1) {
                                // Уменьшаем количество на 1 и обновляем счетчик прочности
                                $craftedItemLogModel->update($hasPortableTeleport['id'], [
                                    'quantity' => $hasPortableTeleport['quantity'] - 1,
                                    'durability_count' => $portableTeleport['durability_count']
                                ]);
                            } else {
                                // Удаляем запись о телепорте, так как он полностью использован
                                $craftedItemLogModel->delete($hasPortableTeleport['id']);
                            }
                        }

                        $keyboard = [
                            'inline_keyboard' => [
                                [
                                    ['text' => '🏠 База', 'callback_data' => 'Base'],
                                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                                ],
                            ]
                        ];
                        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
                        return Request::sendMessage([
                            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                            'text'    => "🤖 Это снова я – *Роби*!\n\nТы успешно использовал портативный телепорт и телепортировался на базу.",
                            'parse_mode' => 'Markdown',
                            'reply_markup' => json_encode($keyboard),
                        ]);
                    }
                }
            }
        }
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => "🤖 Это снова я – *Роби*!\n\nУ тебя нет портативного телепорта.",
            'parse_mode' => 'Markdown'
        ]);
    }

    /**
     * Телепорт за опыт
     */

    private function useExperienceTeleport($character): ServerResponse
    {
        $characterModel = new CharacterModel();
        $claimedCellModel = new ClaimedCellModel();
        $mapModel = new MapModel();

        $character = $characterModel->find($character['id']);

        if ($character['experience'] > 1.01) {
            // Получаем данные о местоположении базы
            $claimedCell = $claimedCellModel->where('character_id', $character['id'])->first();
            if ($claimedCell) {
                // Получаем данные о ячейке на карте
                $mapRow = $mapModel->where('cell_number', $claimedCell['map_cell_id'])->first();

                if ($mapRow) {
                    // Обновляем местоположение персонажа
                    $characterModel->update($character['id'], [
                        'cell_number' => $claimedCell['map_cell_id'],
                        'biome_id' => $mapRow['biome_id']
                    ]);

                    // Списываем 1 единицу опыта
                    $characterModel->update($character['id'], [
                        'experience' => $character['experience'] - 1
                    ]);

                    Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
                    return Request::sendMessage([
                        'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                        'text'    => "🤖 Это снова я – *Роби*!\n\nТы успешно использовал опыт для телепортации и телепортировался на базу.",
                        'parse_mode' => 'Markdown',
                    ]);
                }
            }
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => "🤖 Это снова я – *Роби*!\n\nУ тебя недостаточно опыта для телепортации.",
            'parse_mode' => 'Markdown'
        ]);
    }
}

<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;

class CraftedResourcesAction extends BaseAction
{
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->craftedItemsModel    = new CraftedItemsModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // Загружаем все предметы, у которых quantity > 0
        $craftedItemsLogs = $this->craftedItemsLogModel
            ->select('crafted_items_log.quantity, crafted_items.name_rus, crafted_items.type, crafted_items.durability_count')
            ->join('crafted_items', 'crafted_items.id = crafted_items_log.crafted_item_id')
            ->where('crafted_items_log.character_id', $character['id'])
            ->where('crafted_items_log.quantity >', 0)
            ->findAll();

        if (empty($craftedItemsLogs)) {
            // Если ничего нет
            $text = "🤷‍♂️ *Не переживай, друг!* Твои усилия в крафтинге всё ещё впереди.\n\n"
                . "Просто выбери рецепт и начни создавать что-то великолепное! 🗝️💎\n\n"
                . "И помни, каждый великий мастер начинал с малого! 🌟";

        } else {
            // ---------------------------------------------------------
            // 1. Определяем группы и иконки (type -> название группы).
            // ---------------------------------------------------------
            $groupMap = [
                'component' => ['emoji' => '🧩', 'groupName' => 'Компоненты'],
                'drug'      => ['emoji' => '💊', 'groupName' => 'Лекарства'],
                'tool'      => ['emoji' => '🛠️', 'groupName' => 'Инструменты'],
                'robots'    => ['emoji' => '🤖', 'groupName' => 'Роботы'],
                'workbench' => ['emoji' => '🔬', 'groupName' => 'Верстаки'],
                'transport' => ['emoji' => '🛤', 'groupName' => 'Транспорт'],
                'teleport'  => ['emoji' => '🌀', 'groupName' => 'Телепорт-маяки'], // <-- НОВАЯ ГРУППА
            ];

            // Группа для незнакомых type
            $fallbackGroupKey = 'unknown';

            // ---------------------------------------------------------
            // 2. Распихиваем предметы по группам
            // ---------------------------------------------------------
            $groups = [];

            foreach ($craftedItemsLogs as $item) {
                $type = $item['type'] ?? '';
                if (!isset($groupMap[$type])) {
                    $type = $fallbackGroupKey;
                }
                if (!isset($groups[$type])) {
                    $groups[$type] = [];
                }
                $groups[$type][] = $item;
            }

            // Если хотим явно отображать неизвестные предметы
            if (!isset($groupMap[$fallbackGroupKey])) {
                $groupMap[$fallbackGroupKey] = ['emoji' => '❓', 'groupName' => 'Прочее'];
            }

            // ---------------------------------------------------------
            // 3. Формируем общий текст, пробегаясь по $groupMap
            // ---------------------------------------------------------
            $text = "*Склад крафтовых предметов:*\n\n";

            foreach ($groupMap as $typeKey => $groupInfo) {
                if (empty($groups[$typeKey])) {
                    // Нет предметов такой группы
                    continue;
                }

                $emoji      = $groupInfo['emoji'];
                $groupName  = $groupInfo['groupName'];
                $itemsInGroup = $groups[$typeKey];

                // Подсчёт суммарного количества
                $groupTotal = 0;
                foreach ($itemsInGroup as $it) {
                    $groupTotal += $it['quantity'];
                }

                $text .= "{$emoji}Группа *{$groupName}* _(всего " . number_format($groupTotal) . " шт.)_ {$emoji}\n";
                $text .= "```"; // открываем блок кода

                foreach ($itemsInGroup as $it) {
                    $name   = $it['name_rus'];
                    $qty    = (int)$it['quantity'];
                    $dur    = (int)$it['durability_count'];
                    $qtyStr = number_format($qty);
                    $useStr = number_format($qty * $dur);
                    $text .= "\n-{$name} | {$qtyStr} шт. {$useStr} исп.";
                }
                $text .= "\n```\n";
            }

            $text .= "\nПродолжай в том же духе и твои навыки станут легендарными!";
        }

        // Клавиатура
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']
                ],
                [
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop']
                ],
            ]
        ];

        // Убираем часики
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправляем результат
        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}

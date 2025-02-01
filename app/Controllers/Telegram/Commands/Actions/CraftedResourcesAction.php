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
            //    При желании можно переименовать/добавить/упорядочить.
            // ---------------------------------------------------------
            $groupMap = [
                'component' => ['emoji' => '🧩', 'groupName' => 'Компоненты'],
                'drug'      => ['emoji' => '💊', 'groupName' => 'Лекарства'],
                'tool'      => ['emoji' => '🛠️', 'groupName' => 'Инструменты'],
                'robots'    => ['emoji' => '🤖', 'groupName' => 'Роботы'],
                'workbench' => ['emoji' => '🔬', 'groupName' => 'Верстаки'],
                'transport' => ['emoji' => '🛤', 'groupName' => 'Транспорт'],
                // Если есть ещё типы — добавьте сюда
            ];

            // Предметы, которые не имеют type или он неизвестен
            $fallbackGroupKey = 'unknown';

            // ---------------------------------------------------------
            // 2. Распихиваем предметы по группам (словарь groupKey -> массив предметов)
            // ---------------------------------------------------------
            $groups = [];   // [ 'component' => [...], 'drug' => [...], ... , 'unknown' => [...] ]
            foreach ($craftedItemsLogs as $item) {
                $type = $item['type'] ?? '';
                if (!isset($groupMap[$type])) {
                    $type = $fallbackGroupKey; // неизвестная группа
                }
                if (!isset($groups[$type])) {
                    $groups[$type] = [];
                }
                $groups[$type][] = $item;
            }

            // ---------------------------------------------------------
            // 3. Генерируем общий текст, группируя
            // ---------------------------------------------------------
            $text = "*Склад крафтовых предметов:*\n\n";

            // Если хотим явно показывать «неизвестную группу»
            // можно добавить её в конец или в начало.
            // Или же не показывать совсем, если не нужно.
            if (!isset($groupMap[$fallbackGroupKey])) {
                // Можем прописать иконку / заголовок для неизвестных
                $groupMap[$fallbackGroupKey] = ['emoji' => '🔧', 'groupName' => 'Прочее'];
            }

            // Проходим по имеющимся группам, в порядке описания в $groupMap
            foreach ($groupMap as $typeKey => $groupInfo) {
                // Есть ли вообще предметы этой группы у игрока?
                if (empty($groups[$typeKey])) {
                    // Нет предметов в этой группе — пропускаем
                    continue;
                }

                $emoji   = $groupInfo['emoji'];
                $gName   = $groupInfo['groupName'];
                $itemsInGroup = $groups[$typeKey]; // массив предметов

                // Считаем суммарное кол-во предметов в группе
                $groupTotal = 0;
                foreach ($itemsInGroup as $it) {
                    $groupTotal += $it['quantity'];
                }

                // Заголовок группы
                $text .= "{$emoji}Группа *{$gName}* _(всего " . number_format($groupTotal) . " шт.)_ {$emoji}\n";
                // Начинаем блок кода (моноширинный) — тройные бэктики
                $text .= "```";

                // Список предметов
                foreach ($itemsInGroup as $it) {
                    $name   = $it['name_rus'];
                    $qty    = (int)$it['quantity'];
                    $dur    = (int)$it['durability_count'];
                    // Для удобства форматируем qty и qty*dur:
                    $qtyStr = number_format($qty);
                    $useStr = number_format($qty * $dur);

                    // Пример строчки:
                    // -Антисептик | 12,587,531 шт. 62,937,655 исп.
                    // (можно на ваш вкус оформить пробелы)
                    $text .= "\n-{$name} | {$qtyStr} шт. {$useStr} исп.";
                }
                // Заканчиваем блок кода
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

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}

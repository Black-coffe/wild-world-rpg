<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Notifications\MediaSender;

class CraftedResourcesAction extends BaseAction
{
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->craftedItemsModel = new CraftedItemsModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // 1) Получаем логи созданных предметов
        $craftedItemsLogs = $this->craftedItemsLogModel
            ->select('crafted_items_log.quantity, crafted_items.name_rus, crafted_items.type')
            ->join('crafted_items', 'crafted_items.id = crafted_items_log.crafted_item_id')
            ->where('crafted_items_log.character_id', $character['id'])
            ->orderBy('crafted_items_log.id', 'DESC') // при желании сортируем по дате или ID
            ->findAll();

        // Если у персонажа ничего не скрафчено
        if (empty($craftedItemsLogs)) {
            $text = "🤷‍♂️ *Не переживай, друг!* Твои усилия в крафтинге всё ещё впереди.\n\n"
                . "Просто выбери рецепт и начни создавать что-то великолепное! 🗝️💎\n\n"
                . "И помни, каждый великий мастер начинал с малого! 🌟";
        } else {
            // 2) Группируем предметы по типам
            // Пример: ['component' => [...], 'drug' => [...]]
            $groupedItems = [];
            foreach ($craftedItemsLogs as $item) {
                $type = $item['type'] ?? 'other'; // Если вдруг type = null, считаем 'other'
                $groupedItems[$type][] = $item;
            }

            // 3) Определяем заголовки для каждой категории (type)
            //    Ключ массива = значение из поля "type", значение = текст-заголовок
            $typeHeadings = [
                'component'   => "📐 *Компоненты* 📐",
                'drug'        => "💊 *Лекарства* 💊",
                'tool'        => "🛠️ *Инструменты* 🛠️",
                'weapon'      => "⚔️ *Оружие* ⚔️",
                'clothing'    => "👕 *Одежда* 👕",
                'building'    => "🏠 *Постройки* 🏠",
                'workbench'   => "🔬 *Верстаки* 🔬",
                'robots'      => "🤖 *Роботы* 🤖",
                'transport'   => "🚚 *Транспорт* 🚚",
                'teleport'    => "🌀 *Телепорты* 🌀",
                'utility'     => "🔧 *Полезные штуки* 🔧",
                'decorative'  => "🎨 *Декор* 🎨",
                'magical item'=> "🔮 *Магические предметы* 🔮",
                'military'    => "🛡️ *Военное* 🛡️",
                // при желании добавьте всё, что у вас есть.
                // По умолчанию 'other' - будет подпадать в «прочее»
            ];

            // Для всего, чего не оказалось в typeHeadings, создадим заголовок «Прочее»
            $defaultHeading = "🔸 *Прочие предметы* 🔸";

            // 4) Строим финальный текст
            $textParts = [];
            // - пробежимся по словарю $typeHeadings в фиксированном порядке
            foreach ($typeHeadings as $type => $heading) {
                if (!empty($groupedItems[$type])) {
                    // Собираем список предметов в этой группе
                    $textGroup = $heading . "\n";
                    foreach ($groupedItems[$type] as $item) {
                        $textGroup .= "📦 *{$item['name_rus']}* | "
                            . number_format($item['quantity'])
                            . " шт.\n";
                    }
                    $textParts[] = $textGroup . "\n";
                    // Удалим группу из массива, чтобы не дублировать
                    unset($groupedItems[$type]);
                }
            }
            // - если остались какие-то группы, которых нет в $typeHeadings (e.g. 'other')
            //   выведем их под заголовком «Прочее»
            if (!empty($groupedItems)) {
                $textGroup = $defaultHeading . "\n";
                foreach ($groupedItems as $type => $items) {
                    foreach ($items as $item) {
                        $textGroup .= "📦 *{$item['name_rus']}* | "
                            . number_format($item['quantity'])
                            . " шт.\n";
                    }
                }
                $textParts[] = $textGroup . "\n";
            }

            // Склеиваем все куски в одно сообщение
            $text = "*Твои созданные предметы:*\n\n" . implode("\n", $textParts);
            $text .= "\n*Продолжай в том же духе и твои навыки станут легендарными!*";
        }

        // 5) Формируем клавиатуру
        // S5b (v0.51.188+): кнопка ремонта изношенных инструментов.
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔧 Ремонт инструментов', 'callback_data' => 'repairToolsList'],
                ],
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop']
                ],
            ]
        ];

        // Telegram-ответ
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // #12 edit-in-place (ADR-018): просмотр склада крафта — навигация → редактируем
        // сообщение, на котором нажата кнопка (fallback на новое при ошибке/клике с photo-экрана).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}

<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Notifications\MediaSender;
use App\Services\Player\InventorySortService;

class CraftedResourcesAction extends BaseAction
{
    /**
     * E28 Ф2: паритет с экраном «Добытые ресурсы» — переключатель сортировки.
     * `type` — псевдо-режим (группировка по типу, дефолт); name/qty/value делегируют
     * общий {@see InventorySortService}. Режим приходит callback'ом
     * `resourcesCrafting_sort_<mode>` (резолв по первому сегменту → exact `resourcesCrafting`).
     */
    private const MODE_TYPE = 'type';

    /** @var list<string> */
    private const MODES = [
        self::MODE_TYPE,
        InventorySortService::MODE_NAME,
        InventorySortService::MODE_QTY,
        InventorySortService::MODE_VALUE,
    ];

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

        $mode = $this->parseSortMode((string) $this->callbackQuery->getData());

        // price нужен для сортировки «по стоимости» (value = price * quantity).
        $craftedItemsLogs = $this->craftedItemsLogModel
            ->select('crafted_items_log.quantity, crafted_items.name_rus, crafted_items.type, crafted_items.price')
            ->join('crafted_items', 'crafted_items.id = crafted_items_log.crafted_item_id')
            ->where('crafted_items_log.character_id', $character['id'])
            ->orderBy('crafted_items_log.id', 'DESC') // базовый порядок для группировки (по свежести)
            ->findAll();

        if (empty($craftedItemsLogs)) {
            $text = "🤷‍♂️ *Не переживай, друг!* Твои усилия в крафтинге всё ещё впереди.\n\n"
                . "Просто выбери рецепт и начни создавать что-то великолепное! 🗝️💎\n\n"
                . "И помни, каждый великий мастер начинал с малого! 🌟";
            return $this->reply($text, $mode);
        }

        // Нормализуем строки к массиву + добавляем ключ `name` (= name_rus) для InventorySortService.
        $rows = [];
        foreach ($craftedItemsLogs as $item) {
            $r = is_array($item) ? $item : (array) $item;
            $r['name'] = $r['name_rus'] ?? '';
            $rows[] = $r;
        }

        $text = ($mode === self::MODE_TYPE)
            ? $this->renderGrouped($rows)
            : $this->renderFlat($rows, $mode);

        return $this->reply($text, $mode);
    }

    private function parseSortMode(string $callbackData): string
    {
        $mode = null;
        if (str_starts_with($callbackData, 'resourcesCrafting_sort_')) {
            $mode = substr($callbackData, strlen('resourcesCrafting_sort_'));
        }
        return InventorySortService::normalizeMode($mode, self::MODES, self::MODE_TYPE);
    }

    /**
     * Группировка по типу (дефолт) — историческая раскладка с заголовками категорий.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function renderGrouped(array $rows): string
    {
        $groupedItems = [];
        foreach ($rows as $item) {
            $type = is_string($item['type'] ?? null) ? $item['type'] : 'other';
            $groupedItems[$type][] = $item;
        }

        // 🔴 Карта обязана покрывать ВСЕ значения `crafted_items.type`, иначе предмет
        // молча уезжает в «Прочие» и читается как недоделка. Аудит 12.08.2026 (повод —
        // «Метеоритное укрытие»): в проде 18 типов против 14 в карте. Без полки жили
        // defense, drones, food, accessory — 9 предметов, 20 владельцев. Добавили все 4.
        // Если заводишь новый `type` в crafted_items — заводи и заголовок здесь.
        $typeHeadings = [
            'component'    => "📐 *Компоненты* 📐",
            'drug'         => "💊 *Лекарства* 💊",
            'food'         => "🍲 *Еда* 🍲",
            'tool'         => "🛠️ *Инструменты* 🛠️",
            'weapon'       => "⚔️ *Оружие* ⚔️",
            'clothing'     => "👕 *Одежда* 👕",
            'accessory'    => "💍 *Украшения* 💍",
            'defense'      => "🛡 *Защита от событий* 🛡",
            'building'     => "🏠 *Постройки* 🏠",
            'workbench'    => "🔬 *Верстаки* 🔬",
            'robots'       => "🤖 *Роботы* 🤖",
            'drones'       => "🛸 *Дроны* 🛸",
            'transport'    => "🚚 *Транспорт* 🚚",
            'teleport'     => "🌀 *Телепорты* 🌀",
            'utility'      => "🔧 *Полезные штуки* 🔧",
            'decorative'   => "🎨 *Декор* 🎨",
            'magical item' => "🔮 *Магические предметы* 🔮",
            'military'     => "🛡️ *Военное* 🛡️",
        ];
        $defaultHeading = "🔸 *Прочие предметы* 🔸";

        $textParts = [];
        foreach ($typeHeadings as $type => $heading) {
            if (!empty($groupedItems[$type])) {
                $textGroup = $heading . "\n";
                foreach ($groupedItems[$type] as $item) {
                    $textGroup .= $this->line($item);
                }
                $textParts[] = $textGroup . "\n";
                unset($groupedItems[$type]);
            }
        }
        if (!empty($groupedItems)) {
            $textGroup = $defaultHeading . "\n";
            foreach ($groupedItems as $items) {
                foreach ($items as $item) {
                    $textGroup .= $this->line($item);
                }
            }
            $textParts[] = $textGroup . "\n";
        }

        return "*Твои созданные предметы* (по типу):\n\n" . implode("\n", $textParts)
            . "\n*Продолжай в том же духе и твои навыки станут легендарными!*";
    }

    /**
     * Плоский отсортированный список (name/qty/value) через общий сортировщик.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function renderFlat(array $rows, string $mode): string
    {
        $sorted = InventorySortService::sortRows($rows, $mode);

        $text = "*Твои созданные предметы* (" . $this->modeLabel($mode) . "):\n\n";
        foreach ($sorted as $item) {
            $text .= $this->line($item);
        }

        return $text . "\n*Продолжай в том же духе и твои навыки станут легендарными!*";
    }

    /**
     * Строка одного предмета: «📦 *Название* | N шт.» (безопасное чтение полей).
     *
     * @param array<string,mixed> $item
     */
    private function line(array $item): string
    {
        $name = is_string($item['name_rus'] ?? null) ? $item['name_rus'] : '';
        $qty  = is_numeric($item['quantity'] ?? null) ? (int) $item['quantity'] : 0;

        return "📦 *{$name}* | " . number_format($qty) . " шт.\n";
    }

    private function modeLabel(string $mode): string
    {
        return match ($mode) {
            InventorySortService::MODE_NAME  => 'по названию',
            InventorySortService::MODE_QTY   => 'по количеству',
            InventorySortService::MODE_VALUE => 'по стоимости',
            default                          => 'по типу',
        };
    }

    private function reply(string $text, string $mode): ServerResponse
    {
        $sortRow = [];
        foreach ([
            self::MODE_TYPE                  => '🗂 Тип',
            InventorySortService::MODE_NAME  => '🔤 Название',
            InventorySortService::MODE_QTY   => '🔢 Кол-во',
            InventorySortService::MODE_VALUE => '💰 Стоимость',
        ] as $m => $label) {
            $sortRow[] = [
                'text'          => ($m === $mode ? '• ' : '') . $label,
                'callback_data' => "resourcesCrafting_sort_{$m}",
            ];
        }

        // S5b (v0.51.188+): кнопка ремонта изношенных инструментов.
        $keyboard = [
            'inline_keyboard' => [
                $sortRow,
                [
                    ['text' => '🔧 Ремонт инструментов', 'callback_data' => 'repairToolsList'],
                ],
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                ],
            ],
        ];

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

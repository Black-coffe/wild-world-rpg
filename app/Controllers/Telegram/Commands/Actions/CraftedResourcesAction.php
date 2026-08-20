<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Notifications\MediaSender;
use App\Services\Player\InventorySortService;
use App\Services\World\VehicleEffectsService;
use Config\Database;

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

    /**
     * transport-07 (ADR-174, docs/specs/transport-system/) -- dver v kraft pyati mashin
     * (story 06 dobavila recepty v Config\CraftRecipes, no bez vhoda v UI oni ostavalis'
     * BUILT-BUT-INVISIBLE -- tot zhe klass baga, chto u MeteorShelter/droneScout).
     * Literaly `genericCraft_<Key>_1` -- pryamye knopki krafta (u receptov transporta net
     * svoego info-ekrana, callback vedet srazu v GenericCraftActionStart).
     *
     * @var list<array{key:string,label:string,callback:string,required_level:int,required_faction:int}>
     */
    private const TRANSPORT_VEHICLES = [
        ['key' => 'LightCart',       'label' => '🛒 Лёгкая повозка',   'callback' => 'genericCraft_LightCart_1',       'required_level' => 6,  'required_faction' => 0],
        ['key' => 'MountainBike',    'label' => '🚲 Горный велосипед', 'callback' => 'genericCraft_MountainBike_1',    'required_level' => 12, 'required_faction' => 2],
        ['key' => 'Snowmobile',      'label' => '🛻 Снегоход',         'callback' => 'genericCraft_Snowmobile_1',      'required_level' => 14, 'required_faction' => 1],
        ['key' => 'DraftCart',       'label' => '🐎 Тягловая повозка', 'callback' => 'genericCraft_DraftCart_1',       'required_level' => 14, 'required_faction' => 4],
        ['key' => 'AutonomousDrone', 'label' => '🛸 Автономный дрон',  'callback' => 'genericCraft_AutonomousDrone_1', 'required_level' => 16, 'required_faction' => 3],
    ];

    /** faction_id -> nazvanie (kommentarii Config\CraftRecipes: 1 Militari / 2 Partizany / 3 Inzhenery / 4 Fermery). */
    private const FACTION_NAMES = [
        1 => 'Милитари',
        2 => 'Партизаны',
        3 => 'Инженеры',
        4 => 'Фермеры',
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
            return $this->reply($text, $mode, $character);
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

        return $this->reply($text, $mode, $character);
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

    /**
     * @param array<string,mixed> $character
     */
    private function reply(string $text, string $mode, array $character): ServerResponse
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

        // transport-07: витрина крафта пяти машин — экран показывает её ВСЕГДА (не
        // только когда у игрока уже что-то есть на полке «🚚 Транспорт»), иначе
        // рецепты остаются недостижимыми до первого владения (правило
        // UX-DISCOVERABILITY: скрытый вход без ADR — баг, а не дизайн).
        //
        // Ревью-находка (MAJOR): килсвитч `world.vehicle.enabled` гасит только эффект
        // в VehicleEffectsService::profileFor(), а витрина рисовалась безусловно —
        // игрок тратил ресурсы на крафт и читал обещание темпа, которое никогда не
        // сбывалось (килсвитч, не закрывающий вход, усиливает баг вместо страховки).
        // Тот же источник правды, что и у самого эффекта — не дублируем ключ настройки.
        $vehicleEnabled = (new VehicleEffectsService())->isEnabled();
        $text .= "\n" . $this->transportShowcaseText($character, $vehicleEnabled);

        // S5b (v0.51.188+): кнопка ремонта изношенных инструментов.
        // drone-discoverability #01: игрок видел «📦 Дрон-разведчик» на полке
        // «🛸 Дроны» и не находил двери к нему — рюкзак был тупиком (правило
        // UX-DISCOVERABILITY). Безусловная кнопка «🚁 Ангар» встаёт вторым
        // соседом в тот же ряд, чтобы не плодить строку-одиночку.
        $keyboard = [
            'inline_keyboard' => array_merge(
                [$sortRow],
                $this->transportShowcaseButtonRows($vehicleEnabled),
                [
                    [
                        ['text' => '🔧 Ремонт инструментов', 'callback_data' => 'repairToolsList'],
                        ['text' => '🤖 Ангар', 'callback_data' => 'hangar'],
                    ],
                    [
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                    ],
                ],
            ),
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

    /**
     * transport-07: текст витрины — пять машин показаны ЛЮБОМУ игроку, недоступные
     * несут 🔒 с причиной и путём («нужна фракция X — ты Y», «с N уровня — у тебя M»).
     *
     * Ревью-находка (MAJOR): при выключенном килсвитче `world.vehicle.enabled` эффект
     * машины всегда нейтральный ({@see VehicleEffectsService::profileFor()}) — витрина
     * обязана честно сказать «раздел ещё не открыт» и не сулить темп, который не
     * наступит. Названия машин остаются видимыми (UX-DISCOVERABILITY: скрытый вход —
     * баг), но без уровня/фракции — при выключенной системе это не имеет смысла.
     *
     * @param array<string,mixed> $character
     */
    private function transportShowcaseText(array $character, bool $vehicleEnabled): string
    {
        if (!$vehicleEnabled) {
            $lines = ["🚚 *Транспорт* — раздел ещё не открыт:\n"];
            foreach (self::TRANSPORT_VEHICLES as $vehicle) {
                $lines[] = "🔒 {$vehicle['label']} — крафт машин пока недоступен";
            }

            return implode("\n", $lines);
        }

        $level     = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 0;
        $charIdRaw = $character['id'] ?? null;
        $charId    = is_numeric($charIdRaw) ? (int) $charIdRaw : 0;
        $factionId = $this->characterFactionId($charId);

        $lines = ["🚚 *Транспорт* — крафт машин:\n"];
        foreach (self::TRANSPORT_VEHICLES as $vehicle) {
            $reasons = [];
            if ($level < $vehicle['required_level']) {
                $reasons[] = "с {$vehicle['required_level']} уровня — у тебя {$level}";
            }
            $requiredFaction = $vehicle['required_faction'];
            if ($requiredFaction > 0 && $factionId !== $requiredFaction) {
                $needName = self::FACTION_NAMES[$requiredFaction];
                $haveName = $factionId > 0 ? (self::FACTION_NAMES[$factionId] ?? "фракция #{$factionId}") : 'без фракции';
                $reasons[] = "нужна фракция {$needName} — ты {$haveName}";
            }

            $lines[] = $reasons === []
                ? "🔓 {$vehicle['label']}"
                : "🔒 {$vehicle['label']} — " . implode('; ', $reasons);
        }

        return implode("\n", $lines);
    }

    /**
     * transport-07: ряды кнопок крафта — по 2-3 в ряд (правило проекта: ноль
     * одиночных кнопок в строке). Кнопка живая для всех — недоступные отклонит
     * сам `GenericCraftActionStart` тем же гейтом, что читает статус выше.
     *
     * Ревью-находка (MAJOR): при выключенном килсвитче кнопки не выводятся вовсе —
     * живая кнопка вела бы к реальному крафту без единого шанса на эффект.
     *
     * @return list<list<array{text:string,callback_data:string}>>
     */
    private function transportShowcaseButtonRows(bool $vehicleEnabled): array
    {
        if (!$vehicleEnabled) {
            return [];
        }

        $buttons = [];
        foreach (self::TRANSPORT_VEHICLES as $vehicle) {
            $buttons[] = ['text' => $vehicle['label'], 'callback_data' => $vehicle['callback']];
        }

        $rows = [];
        foreach (array_chunk($buttons, 3) as $chunk) {
            $rows[] = $chunk;
        }

        return $rows;
    }

    /**
     * S25-паттерн (ADR-029), повторён из GenericCraftActionStart::characterFactionId —
     * faction_id персонажа (0, если нет записи / Нейтрал).
     */
    private function characterFactionId(int $charId): int
    {
        if ($charId <= 0) {
            return 0;
        }
        $db    = Database::connect();
        $query = $db->table('character_factions')
            ->where('character_id', $charId)
            ->get();
        $row = $query !== false ? $query->getFirstRow('array') : null;
        return is_array($row) && isset($row['faction_id']) && is_numeric($row['faction_id'])
            ? (int) $row['faction_id']
            : 0;
    }
}

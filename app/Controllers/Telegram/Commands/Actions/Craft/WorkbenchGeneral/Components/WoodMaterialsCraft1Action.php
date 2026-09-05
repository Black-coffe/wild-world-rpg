<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel; // <-- (1) Подключаем модель логов
use App\Services\Craft\CraftCardHelper;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Пример экшена, который показывает информацию о крафте «Древесные материалы» (Wood Materials)
 * и формирует кнопки для крафта сразу N штук (1, 5, 10, 25, 50, 100).
 */
class WoodMaterialsCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel; // <-- (2) Добавляем поле для логов
    private CraftCardHelper $craftCardHelper;

    /**
     * Список доступных «пакетов» для крафта.
     * Вы можете менять (добавлять/убирать) нужные вам варианты.
     */
    private array $craftQuantities = [1, 5, 10, 25, 50, 100];

    /**
     * Базовый «рецепт» на 1 штуку.
     * Если нужно 5 штук, мы умножаем каждое требование ресурсов на 5 и т.д.
     */
    private array $requiredResourcesBase = [
        'Древесина' => 50,
        'Вода'      => 5,
    ];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel(); // <-- (2) Инициализируем модель логов
        $this->craftCardHelper        = new CraftCardHelper();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь или персонаж не найден.',
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

        $characterId = $character['id'];

        // 1) Узнаём, сколько "Древесных материалов" (англ. name_eng = "WoodMaterials") у игрока уже есть
        $woodMaterialsNameEng = 'WoodMaterials'; // Проверьте соответствие вашей таблице crafted_items
        $woodQty              = $this->getCraftedItemQuantity($characterId, $woodMaterialsNameEng);

        // 2) Формируем заголовок
        // Если уже есть хоть 1, добавим "(в инв. – X шт.)"
        $title = '🪵 Древесные материалы!';
        if ($woodQty > 0) {
            $title .= " (в инв. – {$woodQty} шт.)";
        }

        // Считаем, сколько есть ресурсов (всего)
        $resourcesAvailable = $this->getResourcesInfo($characterId);

        // Генерируем основной текст описания, используя $title
        $text = "*{$title}*\n\n"
            . "Для крафта ОДНОЙ штуки нужны ресурсы:\n\n";

        foreach ($this->requiredResourcesBase as $resName => $resAmount) {
            $haveAmount = $resourcesAvailable[$resName]['quantity'] ?? 0;
            $rarity     = $resourcesAvailable[$resName]['rarity']   ?? '-';
            $text      .= ResourceIconHelper::for($resName) . " {$resName} — {$resAmount} ед. "
                . "(в наличии {$haveAmount} ед., редк: {$rarity})\n";
        }

        $text .= "\n*Стоимость на рынке:* _105_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта:* _~4–16 мин._\n\n"
            . "*Описание:* Компонент из дерева для постройки, станков и др.\n\n";

        // Формируем список кнопок (количественный крафт)
        // Показываем лишь те, на которые хватает ресурсов
        $keyboardButtons = $this->makeQuantityButtons($resourcesAvailable);

        // Если вообще нет ни одной кнопки (значит ресурсов не хватает даже на 1шт)
        if (empty($keyboardButtons)) {
            $text .= "__Недостаточно ресурсов даже на 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '◀️ Я',  'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать',     'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',     'callback_data' => 'buy'],
                    ],
                    [
                        $this->craftCardHelper->fallbackButton('WoodMaterials'),
                    ],
                    [
                        ['text' => '⬅️ Назад', 'callback_data' => 'componentsCraft'],
                    ],
                ]
            ];
        } else {
            // Добавим «служебные» кнопки ниже основных
            $keyboardButtons[] = [
                ['text' => '◀️ Я',  'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
            ];
            $keyboardButtons[] = [['text' => '⬅️ Назад', 'callback_data' => 'componentsCraft']];
            // Завершаем формирование клавиатуры
            $keyboard = ['inline_keyboard' => $keyboardButtons];
        }

        $imagePath = base_url('uploads/telegram/craft/components/craftWoodMaterials.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Возвращаем фото + текст
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Возвращает, сколько уже созданных "WoodMaterials" у игрока,
     * если есть запись в crafted_items_log.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        // Предположим, что в CraftedItemsLogModel есть метод getItemByNameEngAndCharacterId(...)
        $itemRow = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $itemRow ? (int) $itemRow['quantity'] : 0;
    }

    /**
     * Собираем информацию о ресурсах, имеющихся у игрока.
     * Возвращаем массив вида:
     *  [
     *    "Древесина" => ["quantity"=>..., "rarity"=>...],
     *    "Вода"      => [...],
     *    ...
     *  ]
     */
    private function getResourcesInfo(int $characterId): array
    {
        // Тем же пулом (рюкзак + склад базы, ADR-171), которым потом считает старт
        // крафта GenericCraftActionStart::checkResources().
        $allRes = [];
        foreach ($this->craftCardHelper->available($characterId, $this->requiredResourcesBase) as $res) {
            $allRes[$res['name']] = [
                'quantity' => $res['quantity'],
                'rarity'   => $res['rarity'],
            ];
        }
        return $allRes;
    }

    /**
     * Генерируем кнопки вида:
     *  [ [ "Крафт 1шт", "Крафт 5шт" ], [ "Крафт 10шт", "Крафт 25шт" ], ... ]
     * Но только если хватает ресурсов на каждый объём.
     */
    private function makeQuantityButtons(array $resourcesAvailable): array
    {
        $buttonsRow = [];
        foreach ($this->craftQuantities as $qty) {
            // Проверим, достаточно ли ресурсов на qty
            if ($this->canCraftQuantity($resourcesAvailable, $qty)) {
                // Пример callback_data: "craftWoodMaterials_5"
                $buttonsRow[] = [
                    'text'          => "🛠️ {$qty}шт.",
                    'callback_data' => "genericCraft_WoodMaterials_{$qty}",
                ];
            }
        }

        // Разбиваем по 3 в ряд для удобства
        $rows = [];
        $tmpRow = [];
        foreach ($buttonsRow as $btn) {
            $tmpRow[] = $btn;
            if (count($tmpRow) >= 3) {
                $rows[] = $tmpRow;
                $tmpRow = [];
            }
        }
        if (!empty($tmpRow)) {
            $rows[] = $tmpRow;
        }

        return $rows;
    }

    /**
     * Проверяем, достаточно ли ресурсов на крафт qty штук.
     */
    private function canCraftQuantity(array $resourcesAvailable, int $qty): bool
    {
        foreach ($this->requiredResourcesBase as $resName => $baseAmount) {
            $need   = $baseAmount * $qty;
            $have   = $resourcesAvailable[$resName]['quantity'] ?? 0;
            if ($have < $need) {
                return false;
            }
        }
        return true;
    }
}

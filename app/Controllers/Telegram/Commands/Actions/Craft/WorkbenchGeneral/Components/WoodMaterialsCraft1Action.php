<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Пример экшена, который показывает информацию о крафте «Древесные материалы» (Wood Materials)
 * и формирует кнопки для крафта сразу N штук (1, 5, 10, 25, 50, 100).
 */
class WoodMaterialsCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;

    /**
     * Список доступных «пакетов» для крафта.
     * Вы можете менять (добавлять/убирать) нужные вам варианты.
     */
    private $craftQuantities = [1, 5, 10, 25, 50, 100];

    /**
     * Базовый «рецепт» на 1 штуку.
     * Если нужно 5 штук, мы умножаем каждое требование ресурсов на 5 и т.д.
     */
    private $requiredResourcesBase = [
        'Древесина' => 50,
        'Вода'      => 5,
    ];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
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

        // Считаем, сколько есть ресурсов (всего)
        $resourcesAvailable = $this->getResourcesInfo($character['id']);

        // Генерируем основной текст описания
        $text = "*🪵 Древесные материалы!*\n\n"
            . "Для крафта ОДНОЙ штуки нужны ресурсы:\n\n";

        foreach ($this->requiredResourcesBase as $resName => $resAmount) {
            $haveAmount = $resourcesAvailable[$resName]['quantity'] ?? 0;
            $rarity     = $resourcesAvailable[$resName]['rarity']   ?? '-';
            $text      .= "📦 {$resName} — {$resAmount} ед. "
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
                        ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать',     'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',     'callback_data' => 'buy'],
                    ],
                ]
            ];
        } else {
            // Добавим «служебные» кнопки ниже основных
            $keyboardButtons[] = [
                ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
            ];
            // Завершаем формирование клавиатуры
            $keyboard = ['inline_keyboard' => $keyboardButtons];
        }

        $imagePath = base_url('uploads/telegram/craft/components/craftWoodMaterials.jpg');

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Возвращаем фото + текст
        return Request::sendPhoto([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
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
        $allRes = [];
        foreach ($this->requiredResourcesBase as $resName => $resAmount) {
            $resRow = $this->resourceModel->getResourceByName($resName);
            if ($resRow) {
                $charRes = $this->characterResourceModel
                    ->getResourceByNameAndCharacterId($resName, $characterId);

                $allRes[$resName] = [
                    'quantity' => $charRes ? (int)$charRes['quantity'] : 0,
                    'rarity'   => $resRow['rarity'],
                ];
            } else {
                // На случай, если ресурс не найден в справочнике
                $allRes[$resName] = ['quantity'=>0, 'rarity'=>'?'];
            }
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
                // В callback_data «craftWoodMaterials_5» (к примеру)
                $buttonsRow[] = [
                    'text'          => "🛠️ {$qty}шт.",
                    'callback_data' => "craftWoodMaterials_{$qty}",
                ];
            }
        }

        // Чтобы красиво разбивать по 3 кнопки в ряд (или 4) — можно группировать
        // Например, сделаем по 3 в ряд
        $rows = [];
        $tmpRow = [];
        foreach ($buttonsRow as $btn) {
            $tmpRow[] = $btn;
            if (count($tmpRow) >= 3) {
                $rows[] = $tmpRow;
                $tmpRow = [];
            }
        }
        // Если что-то осталось
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

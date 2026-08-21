<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use App\Services\Craft\CraftCardHelper;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Класс, показывающий информацию о "Базовой аптечке (BasicMedKit)"
 * и формирующий кнопки крафта разных количеств, включая учет крафтовых предметов (напр. Bandage).
 */
class BasicMedKitCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    private CraftCardHelper $craftCardHelper;

    /**
     * Варианты количественного крафта (1, 5, 10, 25, 50, 100).
     */
    private array $craftQuantities = [1, 5, 10, 25, 50, 100];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->craftCardHelper        = new CraftCardHelper();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден или персонаж не создан.',
            ]);
        }

        $characterId = $character['id'];

        // Допустим, англ. название аптечки: "FirstAidKit"
        $basicMedKitNameEng = 'FirstAidKit';
        $basicMedKitQuantity = $this->getCraftedItemQuantity($characterId, $basicMedKitNameEng);

        // Заголовок
        $medKitTitle = '🚑 Аптечка базовая!';
        if ($basicMedKitQuantity > 0) {
            $medKitTitle .= " (в инв. – {$basicMedKitQuantity} шт.)";
        }

        // Требуемые ресурсы: обычные и крафтовые
        $requiredResources = [
            'resources' => [
                'Грибы' => 4,
                'Мед'   => 2,
                'Алоэ'  => 4,
                'Вода'  => 11,
            ],
            'crafted_items' => [
                'Bandage' => 5, // например, нужно 5 повязок
            ],
        ];

        // Проверяем, сколько всего у игрока (обычных + крафтовых)
        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);

        // Считаем, на сколько шт. хватает ресурсов
        $maxCraftableItems = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // Формируем описание
        $text = "*{$medKitTitle}*\n\n"
            . "Для крафта *1 шт.* нужно:\n\n";

        // Выводим список требуемых ресурсов/крафтов
        foreach ($requiredResources['resources'] as $resName => $reqAmount) {
            $have     = $resourcesAvailable[$resName]['quantity'] ?? 0;
            $rarOrInfo = $resourcesAvailable[$resName]['rarity'] ?? 'неизвестно';
            $text    .= ResourceIconHelper::for($resName) . " {$resName} - {$reqAmount} ед. (в наличии {$have} ед., редк. {$rarOrInfo})\n";
        }
        foreach ($requiredResources['crafted_items'] as $itemNameEng => $reqAmount) {
            $have      = $resourcesAvailable[$itemNameEng]['quantity'] ?? 0;
            $itemType  = $resourcesAvailable[$itemNameEng]['type'] ?? 'неизвестно';
            $dispName  = $resourcesAvailable[$itemNameEng]['display_name'] ?? $itemNameEng;
            $text     .= ResourceIconHelper::for($dispName) . " {$dispName} - {$reqAmount} шт. (в наличии {$have} шт., тип: {$itemType})\n";
        }

        $text .= "\n*Стоимость на рынке:* _100_ 💰\n"
            . "*Одноразовый:* _Да_\n"
            . "*Время крафта (1 шт.):* _15 мин._\n\n"
            . "*Описание:* Базовая аптечка 1го уровня, восстанавливает +40 здоровья и +20 выносливости.\n\n";

        // Если ресурсов не хватает даже на 1 шт.
        if ($maxCraftableItems < 1) {
            $text .= "__Недостаточно ресурсов для крафта хотя бы 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать', 'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить', 'callback_data' => 'buy']
                    ],
                    [
                        $this->craftCardHelper->fallbackButton('BasicMedKit'),
                    ],
                    [
                        ['text' => '⬅️ Назад', 'callback_data' => 'medicinesCraft1']
                    ],
                ]
            ];
        } else {
            // Можно крафтить
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            // Разбиваем по 3 кнопки в строке
            $quantityRows    = array_chunk($quantityButtons, 3);

            // Добавим финальный ряд (Персонаж, Инвентарь, и т.д.)
            $quantityRows[] = [
                ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $quantityRows[] = [
                ['text' => '💰 Продать', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
            ];
            $quantityRows[] = [['text' => '⬅️ Назад', 'callback_data' => 'medicinesCraft1']];

            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        $imagePath = base_url('uploads/telegram/craft/simple_craft_kit.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }


    // ======== Вспомогательные методы ========

    /**
     * Узнаём, сколько already имеется "BasicMedKit" (англ. name_eng) у игрока.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        $row = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $row ? (int) $row['quantity'] : 0;
    }

    /**
     * Собираем инфу о наличие обычных ресурсов и крафтовых предметов.
     */
    private function checkResourcesAvailability(int $characterId, array $requiredResources): array
    {
        $results = [];

        // Обычные ресурсы — тем же пулом (рюкзак + склад базы, ADR-171), которым потом
        // считает старт крафта GenericCraftActionStart::checkResources().
        foreach ($this->craftCardHelper->available($characterId, $requiredResources['resources']) as $res) {
            $results[$res['name']] = [
                'quantity' => $res['quantity'],
                'rarity'   => $res['rarity'],
            ];
        }

        // Крафтовые предметы (через crafted_items_log)
        foreach ($requiredResources['crafted_items'] as $itemNameEng => $amount) {
            $logRow = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);

            if ($logRow) {
                $results[$itemNameEng] = [
                    'quantity'     => $logRow['quantity'],
                    'type'         => $logRow['type'] ?? 'crafted',
                    'display_name' => $logRow['name_rus'] ?? $itemNameEng,
                ];
            } else {
                $results[$itemNameEng] = [
                    'quantity'     => 0,
                    'type'         => 'crafted',
                    'display_name' => $itemNameEng
                ];
            }
        }

        return $results;
    }

    /**
     * Считаем, на сколько шт. хватает (берём минимум по всем ресурсам/предметам).
     */
    private function calculateMaxCraftableItems(array $resourcesAvailable, array $requiredResources): int
    {
        $maxCraftable = PHP_INT_MAX;

        // 1) Обычные ресурсы
        foreach ($requiredResources['resources'] as $resName => $reqAmount) {
            $have = $resourcesAvailable[$resName]['quantity'] ?? 0;
            $possible = (int) floor($have / $reqAmount);
            if ($possible < $maxCraftable) {
                $maxCraftable = $possible;
            }
        }
        // 2) Крафтовые предметы
        foreach ($requiredResources['crafted_items'] as $itemNameEng => $reqAmount) {
            $have = $resourcesAvailable[$itemNameEng]['quantity'] ?? 0;
            $possible = (int) floor($have / $reqAmount);
            if ($possible < $maxCraftable) {
                $maxCraftable = $possible;
            }
        }

        return $maxCraftable === PHP_INT_MAX ? 0 : $maxCraftable;
    }

    /**
     * Формируем кнопки "Крафт N шт." (1,5,10,25,50,100) — только если N <= maxCraftableItems.
     * Пример callback_data: "craftBasicMedKit_10"
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q}шт",
                    'callback_data' => "genericCraft_BasicMedKit_{$q}"
                ];
            }
        }
        return $buttons;
    }

    /**
     * Проверка на 1 шт. — не нужна, если используем calculateMaxCraftableItems.
     */
    private function areAllResourcesSufficient(array $resourcesAvailable, array $requiredResources): bool
    {
        // Обычные ресурсы
        foreach ($requiredResources['resources'] as $resName => $reqAmount) {
            if (!isset($resourcesAvailable[$resName]) || $resourcesAvailable[$resName]['quantity'] < $reqAmount) {
                return false;
            }
        }

        // Крафтовые предметы
        foreach ($requiredResources['crafted_items'] as $itemNameEng => $reqAmount) {
            if (!isset($resourcesAvailable[$itemNameEng]) || $resourcesAvailable[$itemNameEng]['quantity'] < $reqAmount) {
                return false;
            }
        }

        return true;
    }
}

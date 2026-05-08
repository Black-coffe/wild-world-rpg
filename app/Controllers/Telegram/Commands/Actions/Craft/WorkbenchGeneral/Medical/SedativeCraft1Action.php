<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс, показывающий информацию об "Успокоительном" (Sedative)
 * и формирующий кнопки для крафта (1, 5, 10, 25, 50, 100 штук).
 */
class SedativeCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel;

    /**
     * Варианты количественного крафта.
     */
    private array $craftQuantities = [1, 5, 10, 25, 50, 100];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $characterId = $character['id'];

        // Англ. название в базе (например, "Sedative").
        $itemNameEng = 'Sedative';
        // Узнаём, сколько "Успокоительных" уже есть у игрока
        $itemQuantity = $this->getCraftedItemQuantity($characterId, $itemNameEng);

        // Заголовок
        $itemTitle = '🫖 Успокоительное!';
        if ($itemQuantity > 0) {
            $itemTitle .= " (в инв. – {$itemQuantity} шт.)";
        }

        // Ресурсы для 1 шт.
        $requiredResources = [
            'Цветы орхидей' => 1,
            'Травы'         => 2,
            'Вода'          => 25,
        ];

        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);
        // Сколько максимум можно скрафтить (берём минимум по всем ресурсам)
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // Формируем описание предмета
        $text = "*{$itemTitle}*\n\n"
            . "Для крафта *1 шт.* необходимо:\n\n";

        foreach ($resourcesAvailable as $res) {
            $req     = $requiredResources[$res['name']] ?? 0;
            $have    = $res['quantity'];
            $rarity  = $res['rarity'];
            $text   .= ResourceIconHelper::for($res['name']) . " {$res['name']} - {$req} ед. "
                . "(в наличии {$have} ед., редк. {$rarity})\n";
        }

        $text .= "\n*Стоимость на рынке:* _40_ 💰\n"
            . "*Одноразовый:* _Да_\n"
            . "*Время крафта (1 шт.):* _7 мин._\n\n"
            . "*Описание:* Отличный напиток для успокоения, даёт +5 к здоровью и +30 к выносливости.\n\n";

        // Если ресурсов не хватает даже на 1 шт.
        if ($maxCraftableItems < 1) {
            $text .= "__Недостаточно ресурсов для крафта хотя бы 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать', 'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить', 'callback_data' => 'buy']
                    ],
                ]
            ];
        } else {
            // Можно крафтить
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            $quantityRows    = array_chunk($quantityButtons, 3);

            // Добавим стандартные кнопки
            $quantityRows[] = [
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $quantityRows[] = [
                ['text' => '💰 Продать', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить', 'callback_data' => 'buy']
            ];

            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        $imagePath = base_url('uploads/telegram/craft/dry_herb_tea.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Узнаём, сколько "Успокоительных" (англ. name_eng) есть у игрока.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        $logEntry = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $logEntry ? (int) $logEntry['quantity'] : 0;
    }

    /**
     * Проверяем, сколько у игрока ресурсов (для 1 шт.).
     */
    private function checkResourcesAvailability(int $characterId, array $requiredResources): array
    {
        $results = [];
        foreach ($requiredResources as $name => $amount) {
            $resource = $this->resourceModel->getResourceByName($name);
            $qty      = 0;
            $rarity   = 0;

            if ($resource) {
                $charRes = $this->characterResourceModel
                    ->getResourceByNameAndCharacterId($name, $characterId);

                $qty    = $charRes ? $charRes['quantity'] : 0;
                $rarity = $resource['rarity'];
            }

            $results[] = [
                'name'     => $name,
                'quantity' => $qty,
                'rarity'   => $rarity,
            ];
        }
        return $results;
    }

    /**
     * Определяем, на сколько штук максимум хватит ресурсов.
     */
    private function calculateMaxCraftableItems(array $resourcesAvailable, array $requiredResources): int
    {
        $maxCraftable = PHP_INT_MAX;

        foreach ($resourcesAvailable as $res) {
            $name = $res['name'];
            $have = $res['quantity'];
            $need = $requiredResources[$name] ?? 0;

            if ($need > 0) {
                $possible = (int)floor($have / $need);
                if ($possible < $maxCraftable) {
                    $maxCraftable = $possible;
                }
            }
        }

        return ($maxCraftable === PHP_INT_MAX) ? 0 : $maxCraftable;
    }

    /**
     * Генерируем кнопки "Крафт N шт.", если N <= $maxCraftableItems.
     * Пример: "craftSedative_10", чтоб потом в Start-классе распарсить 10.
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q} шт",
                    'callback_data' => "genericCraft_Sedative_{$q}"
                ];
            }
        }
        return $buttons;
    }

    /**
     * Проверка ресурсов (1 шт.) — частично дублирует calculateMaxCraftableItems().
     */
    private function areAllResourcesSufficient(array $resourcesAvailable, array $requiredResources): bool
    {
        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']] ?? 0;
            if ($res['quantity'] < $need) {
                return false;
            }
        }
        return true;
    }
}

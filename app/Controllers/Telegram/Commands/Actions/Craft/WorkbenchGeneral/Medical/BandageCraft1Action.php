<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class BandageCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel;

    /**
     * Возможные варианты количественного крафта (1, 5, 10, 25, 50, 100).
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

        // Предполагаем, что в базе у "Повязки" (Bandage) англ. name_eng = 'Bandage'
        $bandageNameEng = 'Bandage';

        // Сколько уже есть у игрока
        $bandageQuantity = $this->getCraftedItemQuantity($characterId, $bandageNameEng);

        // Заголовок
        $bandageTitle = '🩹 Повязка!';
        if ($bandageQuantity > 0) {
            $bandageTitle .= " (в инв. – {$bandageQuantity} шт.)";
        }

        // Ресурсы, необходимые для 1 шт.
        $requiredResources = [
            'Травы'         => 2,
            'Кора деревьев' => 2,
            'Водоросли'     => 3,
        ];

        // Проверим, сколько ресурсов у игрока
        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);
        // Считаем, сколько штук максимально можно скрафтить
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // Формируем текст
        $text = "*{$bandageTitle}*\n\n"
            . "Для крафта *1 шт.* необходимо:\n\n";

        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']];
            $have = $res['quantity'];
            $rarity = $res['rarity'];
            $text .= "📦 {$res['name']} - {$need} ед. "
                . "(в наличии {$have} ед., редк - {$rarity})\n";
        }

        $text .= "\n*Стоимость на рынке:* _20_ 💰\n"
            . "*Одноразовый:* _Да_\n"
            . "*Время крафта (1 шт.):* _3 мин._\n\n"
            . "*Описание:* Минимальное восстановление здоровья и выносливости. "
            . "Можно использовать при крафте более мощных средств.\n\n";

        // Формируем кнопки
        if ($maxCraftableItems < 1) {
            // Недостаточно ресурсов даже на 1 шт.
            $text .= "__Ты не можешь крафтить: не хватает ресурсов даже на 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать',    'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',    'callback_data' => 'buy']
                    ],
                ]
            ];
        } else {
            // Можно крафтить
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            // Разбиваем по 3 кнопки в ряд
            $quantityRows = array_chunk($quantityButtons, 3);

            // Добавим пару рядов с основными кнопками
            $quantityRows[] = [
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $quantityRows[] = [
                ['text' => '💰 Продать', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
            ];

            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        // Путь к картинке
        $imagePath = base_url('uploads/telegram/craft/bandage_that_is_made_in_the_wild.jpg');

        // Ответим на callbackQuery
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправим фото
        return Request::sendPhoto([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Возвращает, сколько у персонажа этого предмета (Bandage).
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        $itemLog = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $itemLog ? (int)$itemLog['quantity'] : 0;
    }

    /**
     * Смотрим, сколько ресурсов для 1 шт. есть у игрока.
     */
    private function checkResourcesAvailability(int $characterId, array $requiredResources): array
    {
        $results = [];
        foreach ($requiredResources as $resName => $need) {
            $resRow = $this->resourceModel->getResourceByName($resName);
            $qty = 0;
            $rarity = 0;

            if ($resRow) {
                $charRes = $this->characterResourceModel
                    ->getResourceByNameAndCharacterId($resName, $characterId);

                $qty    = $charRes ? $charRes['quantity'] : 0;
                $rarity = $resRow['rarity'];
            }

            $results[] = [
                'name'     => $resName,
                'quantity' => $qty,
                'rarity'   => $rarity,
            ];
        }
        return $results;
    }

    /**
     * Вычисляем, сколько максимум штук можно скрафтить, исходя из имеющихся ресурсов.
     */
    private function calculateMaxCraftableItems(array $resourcesAvailable, array $requiredResources): int
    {
        $maxCraftable = PHP_INT_MAX;

        foreach ($resourcesAvailable as $res) {
            $name = $res['name'];
            $have = $res['quantity'];
            $need = $requiredResources[$name] ?? 0;

            if ($need > 0) {
                $possible = (int) floor($have / $need);
                if ($possible < $maxCraftable) {
                    $maxCraftable = $possible;
                }
            }
        }

        return ($maxCraftable === PHP_INT_MAX) ? 0 : $maxCraftable;
    }

    /**
     * Формируем кнопки "Крафт X шт." (1, 5, 10, 25, 50, 100) только если X <= $maxCraftableItems.
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        // Колбэк: "craftBandage_X", напр. "craftBandage_10"
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q} шт",
                    'callback_data' => "craftBandage_{$q}"
                ];
            }
        }
        return $buttons;
    }

    /**
     * Старый метод, проверяет только на 1 шт. (можно выпилить, если не используется).
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

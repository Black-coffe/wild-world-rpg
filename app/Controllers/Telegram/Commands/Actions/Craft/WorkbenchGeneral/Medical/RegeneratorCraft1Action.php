<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel; // <-- Модель лога крафта
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class RegeneratorCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel; // <-- Свойство для лога

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel = new ResourceModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel(); // <-- Инициализируем лог
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

        // Допустим, в базе (crafted_items/crafted_items_log) этот предмет называется "Regenerator" (name_eng).
        // Если в вашей таблице другое название — подставьте нужное.
        $itemNameEng = 'Regenerator';

        // Сколько у игрока уже есть «Регенераторов»?
        $itemQuantity = $this->getCraftedItemQuantity($characterId, $itemNameEng);

        // Формируем заголовок (добавляем количество, если > 0)
        $itemTitle = '🔋 Регенератор!';
        if ($itemQuantity > 0) {
            $itemTitle .= " (в инв. – {$itemQuantity} шт.)";
        }

        // Проверка необходимых ресурсов (как было у вас)
        $requiredResources = [
            'Мясо диких животных' => 2,
            'Водные растения'     => 2,
            'Травы'               => 6,
            'Вода'               => 30,
        ];

        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);

        // Собираем текст
        $text = "*{$itemTitle}*\n\n"
            . "Для крафта предмета тебе нужны:\n\n";

        foreach ($resourcesAvailable as $resource) {
            $reqAmount  = $requiredResources[$resource['name']];
            $haveAmount = $resource['quantity'];
            $rarity     = $resource['rarity'];
            $text      .= "📦 {$resource['name']} - {$reqAmount} ед. "
                . "(в наличии {$haveAmount} ед. редк - {$rarity})\n";
        }

        $text .= "\n*Стоимость на рынке:* _45_ 💰\n"
            . "*Одноразовый:* _Да_\n"
            . "*Время крафта:* _15 мин._\n\n"
            . "*Описание:* Адреналин в сердце, взрывает организм на новые подвиги и силы, "
            . "восстановит +30 к здоровью и +20 к выносливости\n\n";

        // Проверяем, хватает ли ресурсов
        if (!$this->areAllResourcesSufficient($resourcesAvailable, $requiredResources)) {
            $text .= "__Вы не можете крафтить, так как у вас недостаточно ресурсов для крафта этого предмета.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж',   'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь',   'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать',     'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',     'callback_data' => 'buy']
                    ],
                ]
            ];
        } else {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🛠️ Крафтить',   'callback_data' => 'craftRegenerator'],
                    ],
                    [
                        ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
                    ],
                ]
            ];
        }

        $imagePath = base_url('uploads/telegram/craft/health_and_strength_regenerator.jpg');
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
     * Узнаём, сколько предметов (name_eng) у персонажа есть в логе крафта.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        // Предполагается, что в CraftedItemsLogModel есть метод:
        // getItemByNameEngAndCharacterId($itemNameEng, $characterId)
        // возвращающий массив ['quantity' => ...] или null, если предмета нет.
        // Если у вас метод называется иначе, подправьте.
        // Если нет такого метода — нужно его добавить.
        $itemLog = (new \App\Models\CraftedItemsLogModel())
            ->getItemByNameEngAndCharacterId($itemNameEng, $characterId);

        return $itemLog ? (int) $itemLog['quantity'] : 0;
    }

    private function checkResourcesAvailability($characterId, $requiredResources)
    {
        $results = [];
        foreach ($requiredResources as $name => $amount) {
            $resource = $this->resourceModel->getResourceByName($name);
            if ($resource) {
                $characterResource = $this->characterResourceModel->getResourceByNameAndCharacterId($name, $characterId);
                $results[] = [
                    'name'     => $name,
                    'quantity' => $characterResource ? $characterResource['quantity'] : 0,
                    'rarity'   => $resource['rarity']
                ];
            }
        }
        return $results;
    }

    private function areAllResourcesSufficient($resourcesAvailable, $requiredResources)
    {
        foreach ($resourcesAvailable as $resource) {
            $need = $requiredResources[$resource['name']] ?? 0;
            if ($resource['quantity'] < $need) {
                return false;
            }
        }
        return true;
    }
}

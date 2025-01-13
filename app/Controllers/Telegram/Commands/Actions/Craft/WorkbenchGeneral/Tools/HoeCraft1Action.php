<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel; // <-- Добавляем, чтобы узнать, сколько предметов уже скрафтил игрок
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class HoeCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel; // <-- Свойство для лога крафта

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel(); // <-- Инициализируем
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

        // 1) Предположим, что в базе у мотыги name_eng = "Hoe".
        // Если у вас иное название, подставьте его здесь.
        $hoeNameEng = 'Hoe';

        // 2) Узнаём, сколько мотыг уже есть у игрока
        $hoeQuantity = $this->getCraftedItemQuantity($characterId, $hoeNameEng);

        // 3) Формируем заголовок (включая наличие, если > 0)
        $hoeTitle = '🌾 Мотыга!';
        if ($hoeQuantity > 0) {
            $hoeTitle .= " (в инв. – {$hoeQuantity} шт.)";
        }

        // Остальная логика (проверка ресурсов) остаётся без изменений:
        $requiredResources = [
            'Древесина'     => 50,
            'Железная руда' => 16,
        ];

        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);

        // Собираем текст, используя $hoeTitle:
        $text = "*{$hoeTitle}*\n\n"
            . "Для крафта предмета тебе нужны:\n\n";

        foreach ($resourcesAvailable as $resource) {
            $text .= "📦 {$resource['name']} - {$requiredResources[$resource['name']]} ед. "
                . "(в наличии {$resource['quantity']} ед. редк - {$resource['rarity']})\n";
        }

        $text .= "\n*Стоимость на рынке:* _164_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта:* _14 минут_\n\n"
            . "*Описание:* Мотыка (сапа) — сельскохозяйственный инструмент в виде широкого "
            . "металлического полотна, прикрепленного под углом к древку. "
            . "Дает +30 к земледельческим ресурсам.\n\n";

        // Проверяем, хватает ли ресурсов:
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
                        ['text' => '🛍️ Купить',     'callback_data' => 'buy'],
                    ],
                ]
            ];
        } else {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🛠️ Крафтить',    'callback_data' => 'craftHoe'],
                    ],
                    [
                        ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                ]
            ];
        }

        $imagePath = base_url('uploads/telegram/craft/traditional-hoe.jpg');

        // Закрываем анимацию "часики" в Telegram, отвечая на callback
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
     * Узнаёт, сколько уже есть "мотыг" (name_eng = $itemNameEng) у данного персонажа.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        // Если в вашей модели лога крафта есть метод getItemByNameEngAndCharacterId(...)
        // — используем его так же, как в других классах
        // (Предполагается, что вы уже добавили аналогичный метод, как в LumberjackAxeCraft1Action)

        // Пример:
        $craftedItemsLogModel = new \App\Models\CraftedItemsLogModel();
        $item = $craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);

        return $item ? (int) $item['quantity'] : 0;
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
            if ($resource['quantity'] < $requiredResources[$resource['name']]) {
                return false;
            }
        }
        return true;
    }
}

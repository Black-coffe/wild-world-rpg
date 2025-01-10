<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class StimulatorCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel = new ResourceModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $characterId = $character['id'];

        $requiredResources = [
            'Грибы' => 3,
            'Мед' => 2,
            'Алоэ' => 3,
            'Вода' => 12,
        ];

        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);

        $text = "*💉 Стимулятор!*\n\n"
            . "Для крафта предмета тебе нужны:\n\n";

        foreach ($resourcesAvailable as $resource) {
            $text .= "📦 {$resource['name']} - {$requiredResources[$resource['name']]} ед. (в наличии {$resource['quantity']} ед. редк - {$resource['rarity']})\n";
        }

        $text .= "\n*Стоимость на рынке:* _65_ 💰\n"
            . "*Одноразовый:* _Да_\n"
            . "*Время крафта:* _12 мин._\n\n"
            . "*Описание:* Как говорят, конская доза удовольствия. Повысит уровень выносливости +15 единиц и +25 единиц здоровья\n\n";

        if (!$this->areAllResourcesSufficient($resourcesAvailable, $requiredResources)) {
            $text .= "__Вы не можете крафтить, так как у вас недостаточно ресурсов для крафта этого предмета.__";
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
                ]
            ];
        } else {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🛠️ Крафтить', 'callback_data' => 'craftStimulator'],
                    ],
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                ]
            ];
        }

        $imagePath = base_url('uploads/telegram/craft/liquid_mixture_of_very_invigorating_acid-green_beverage.jpg');

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function checkResourcesAvailability($characterId, $requiredResources)
    {
        $results = [];
        foreach ($requiredResources as $name => $amount) {
            $resource = $this->resourceModel->getResourceByName($name);
            if ($resource) {
                $characterResource = $this->characterResourceModel->getResourceByNameAndCharacterId($name, $characterId);
                $results[] = [
                    'name' => $name,
                    'quantity' => $characterResource ? $characterResource['quantity'] : 0,
                    'rarity' => $resource['rarity']
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

<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Controllers\Telegram\Commands\Actions\BaseAction;

class SellAction extends BaseAction
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
        $this->updateResourcePrices(); // Обновляем цены перед обработкой запроса
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $characterResources = $this->characterResourceModel->where('id_characters', $character['id'])->findAll();
        $totalResources = count($characterResources);
        $totalValue = 0;

        foreach ($characterResources as $characterResource) {
            $resource = $this->resourceModel->find($characterResource['id_resources']);
            if ($resource) {
                $totalValue += $resource['sell_price'] * $characterResource['quantity'];
            }
        }

        $text = "📦 *У тебя есть разных: $totalResources вид(а) всех ресурсов*\n"
            . "👉Их общая стоимость = *" . number_format($totalValue) . "💰*\n\n"
            . "_📌ВАЖНО📌 Чтобы и тебе, и мне, как торговцу, было проще, пересмотри ресурсы и обрати внимание на их редкость. Ниже отметь цифрой, какой редкости ресурсы ты готов продать. Если их там будет несколько, на следующем шаге ты выберешь нужный ресурс._";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '1️⃣ редкость', 'callback_data' => 'sellResource_rarity_1'],
                    ['text' => '2️⃣ редкость', 'callback_data' => 'sellResource_rarity_2'],
                    ['text' => '3️⃣ редкость', 'callback_data' => 'sellResource_rarity_3'],
                ],
                [
                    ['text' => '4️⃣ редкость', 'callback_data' => 'sellResource_rarity_4'],
                    ['text' => '5️⃣ редкость', 'callback_data' => 'sellResource_rarity_5'],
                    ['text' => '6️⃣ редкость', 'callback_data' => 'sellResource_rarity_6'],
                ],
                [
                    ['text' => '7️⃣ редкость', 'callback_data' => 'sellResource_rarity_7'],
                    ['text' => '8️⃣ редкость', 'callback_data' => 'sellResource_rarity_8'],
                    ['text' => '9️⃣ редкость', 'callback_data' => 'sellResource_rarity_9'],
                ],
                [
                    ['text' => '🔟 редкость', 'callback_data' => 'sellResource_rarity_10'],
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ]
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}

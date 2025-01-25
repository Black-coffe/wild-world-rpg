<?php

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\ResourceModel;
use App\Models\TelegramUserModel;
use CodeIgniter\Controller;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use App\Models\ActionLogModel;

class GatherTips extends Controller
{
    protected $characterModel;
    protected $characterResourceModel;
    protected $mapModel;
    protected $biomeModel;
    protected $resourceModel;
    protected $telegramUserModel;
    private $telegram;
    protected $callbackQuery;
    protected $actionLogModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->characterModel = new CharacterModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->mapModel = new MapModel();
        $this->biomeModel = new BiomeModel();
        $this->resourceModel = new ResourceModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->callbackQuery = $callbackQuery;
        $this->actionLogModel = new ActionLogModel();

        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    public function handle(): \Longman\TelegramBot\Entities\ServerResponse
    {
        $telegramUserId = $this->callbackQuery->getFrom()->getId();
        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (!$user) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных',
            ]);
        }

        $character = $this->characterModel->where('telegram_user_id', $user['id'])->first();
        if (!$character || !$character['cell_number']) {
           return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Персонаж не найден в базе данных',
            ]);
        }

        // Получаем имя класса без полного namespace
        $className = basename(str_replace('\\', '/', get_class($this)));

        // Проверяем, записано ли уже действие как выполненное
        $lastAction = $this->actionLogModel->where([
            'character_id' => $character['id'],
            'action_name' => $className,
            'action_status' => 'Completed'
        ])->first();

        if ($lastAction) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => "Обучение уже пройдено. Переходите к следующему шагу.",
            ]);
        }

        $resources = $this->getAvailableResources($character);
        $foundResources = $this->calculateFoundResources($resources, $character);
        $this->saveFoundResources($foundResources, $character);
        $this->sendResourcesFoundReply($foundResources, $character);

        // Записываем действие как выполненное
        $this->actionLogModel->save([
            'character_id' => $character['id'],
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'action_name' => $className,
            'action_status' => 'Completed',
            'description' => 'User completed training section: ' . $className
        ]);

        $lastAction = $this->actionLogModel->where([
            'character_id' => $character['id'],
            'action_name' => $className,
            'action_status' => 'Completed'
        ])->first();

        if ($lastAction) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ],
                ]
            ];
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => "Обучение закончено. Начни действовать не теряя время 👇",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }
    }

    protected function getAvailableResources($character)
    {
        // Получаем текущую локацию персонажа
        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$currentCell) {
            log_message('error', "Location for character {$character['id']} not found.");
            return []; // Возвращаем пустой массив, если локация не найдена
        }

        // Получаем биом текущей локации
        $biome = $this->biomeModel->find($currentCell['biome_id']);
        if (!$biome) {
            log_message('error', "Biome for cell {$currentCell['cell_number']} not found.");
            return []; // Возвращаем пустой массив, если биом не найден
        }

        // Получаем ресурсы, доступные в этом биоме, учитывая уровень персонажа
        $resources = $this->resourceModel
            ->like('biome_id', (string)$biome['id'], 'both') // Используем метод like для поиска
            ->where('level_required <=', $character['level'])
            ->findAll();
        return $resources;
    }

    protected function calculateFoundResources($resources, $character)
    {
        $foundResources = [];
        $waterResourcesIds = [];

        foreach ($resources as $resource) {
            if ($this->isResourceCollectible($resource, $character)) {

                $resourceFactor = $this->getResourceFactor($resource);
                $characterFactor = $this->getCharacterFactor($character);

                $totalAmount = round($resourceFactor * $characterFactor);

                if ($totalAmount > 0) {
                    $foundResources[] = [
                        'resource_id' => $resource['id'],
                        'amount' => max(1, $totalAmount)
                    ];
                }
            }
        }

        return $foundResources;
    }

    protected function isResourceCollectible($resource, $character)
    {
        // Код остается без изменений
        return isset($resource['rarity'], $resource['level_required']) &&
            $resource['level_required'] <= $character['level'];
    }

    protected function getResourceFactor($resource)
    {
        // Адаптируем значение редкости в зависимости от заданного диапазона
        if ($resource['rarity'] >= 2 && $resource['rarity'] <= 5) {
            $randomMultiplier1 = rand(101, 299) / 100;
            $resource['rarity'] *= $randomMultiplier1;
        } elseif ($resource['rarity'] >= 6 && $resource['rarity'] <= 9) {
            $randomMultiplier2 = rand(155, 399) / 100;
            $resource['rarity'] *= $randomMultiplier2;
        } elseif ($resource['rarity'] === 10) {
            $randomMultiplier3 = rand(255, 499) / 100;
            $resource['rarity'] *= $randomMultiplier3;
        }

        // Рассчитываем фактор редкости
        $rarityFactor = $resource['rarity'];
        $difficultyLevel = max(1, 5);

        // Рассчитываем корректировку на основе сложности задачи
        $difficultyAdjustment = 1 / (1 + $difficultyLevel / 10);

        // Возвращаем конечный фактор ресурса
        return $rarityFactor * $difficultyAdjustment;
    }

    protected function getCharacterFactor($character)
    {
        return 1 + 0.05 * ($character['strength'] + $character['agility'] + $character['intellect'] + $character['level']);
    }

    // Метод сохранения найденных ресурсов и обновления статистики персонажа
    protected function saveFoundResources($foundResources, $character)
    {
        foreach ($foundResources as $resource) {
            // Проверка на существование ресурса для персонажа
            $existingResource = $this->characterResourceModel->where([
                'id_characters' => $character['id'],
                'id_resources' => $resource['resource_id'],
            ])->first();

            if ($existingResource) {
                $newQuantity = $existingResource['quantity'] + $resource['amount'];
                $this->characterResourceModel->update($existingResource['id'], ['quantity' => $newQuantity]);
            } else {
                $this->characterResourceModel->insert([
                    'id_characters' => $character['id'],
                    'id_resources' => $resource['resource_id'],
                    'id_telegram_users' => $character['telegram_user_id'],
                    'quantity' => $resource['amount'],
                ]);
            }
        }
    }

    // Метод отправки сообщения пользователю о найденных ресурсах и обновлении статистики
    protected function sendResourcesFoundReply($foundResources, $character) {
        $chatId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first()['telegram_id'];
        $messageText = "*🤖 Поздравляю с первой добычей ресурсов!*\n\n";

        if (!empty($foundResources)) {
            foreach ($foundResources as $resource) {
                $resourceData = $this->resourceModel->find($resource['resource_id']);
                $messageText .= "📦 *{$resourceData['name']}:* {$resource['amount']} единиц\n";
            }
        } else {
            $messageText .= "К сожалению, ресурсы не были найдены.\n";
        }

        $messageText .= "\nТы собрал свои первые ресурсы, которые доступны именно в этом биоме. "
            . "Далее время на изучение местности и сбор ресурсов будет немного дольше, от 3 до 15 минут в зависимости от прокачки твоего персонажа.\n\n"
            . "🤖 Хочу предложить тебе проверенный метод первой недели пребывания на острове:\n\n"
            . "1️⃣ *Изучай побольше местности*\n"
            . "2️⃣ *Собирай постоянно ресурсы*\n"
            . "3️⃣ *Продвигайся по острову*, но не спеши выходить вглубь севера\n"
            . "4️⃣ *Изучи возможности крафта*\n"
            . "5️⃣ *Продай ненужные ресурсы* и купи те, которые помогут скрафтить инструмент\n\n"
            . "🌟 Помни, уже совсем скоро (2, 3 и 4 уровни персонажа) я приду с новыми новостями.\n\n"
            . "🛠️ На досуге просмотри команды (меню) внизу чата. Удачи и до встречи!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🎉 События', 'callback_data' => 'events'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']
                ]
            ]
        ];

        try {
            return Request::sendPhoto([
                'chat_id' => $chatId,
                'photo'   => Request::encodeFile(base_url('uploads/telegram/loot_resources_in_the_box.png')),
                'caption' => $messageText,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard)
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Failed to send message: " . $e->getMessage());
        }
    }


}

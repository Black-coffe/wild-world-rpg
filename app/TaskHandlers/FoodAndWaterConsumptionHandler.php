<?php

namespace App\TaskHandlers;

use App\Models\CharacterResourceModel;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use App\Models\CharacterModel;
use App\Models\BiomeModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
use App\Models\TelegramUserModel;

class FoodAndWaterConsumptionHandler
{
    protected $characterModel;
    protected $biomeModel;
    protected $mapModel;
    protected $resourceModel;
    protected $characterResourceModel;
    protected $telegramUserModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->mapModel = new MapModel();
        $this->biomeModel = new BiomeModel();
        $this->resourceModel = new ResourceModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->telegramUserModel = new TelegramUserModel();
        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            // Регистрация команд
            $this->telegram->addCommandsPath(__DIR__ . '/Commands');

        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    public function process()
    {
        // Получаем текущее время
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        // Устанавливаем часовой пояс, который соответствует времени в Киеве
        $now->setTimezone(new \DateTimeZone('Europe/Kiev'));

        // Получаем текущий час
        $currentHour = (int)$now->format('H');

        // Получаем текущую минуту
        $currentMinute = (int)$now->format('i');

        // Проверяем, соответствует ли текущее время интервалам времени для выполнения задачи
        if (
            ($currentHour === 7 && $currentMinute === 0) ||
            ($currentHour === 15 && $currentMinute === 0) ||
            ($currentHour === 22 && $currentMinute === 0)
        ) {
            // ПОМЕНЯТЬ ПОТОМ УРОВЕНЬ НА 3 с 300
            $characters = $this->characterModel->where('level >=', 3)->findAll();

            foreach ($characters as $character) {
                $telegramId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
                $mapRow = $this->mapModel->where('cell_number', $character['cell_number'])->first();
                if (!$mapRow) {
                    continue; // Пропускаем обработку персонажа, если не найдена строка карты
                }

                $biome = $this->biomeModel->where('id', $mapRow['biome_id'])->first();
                if (!$biome) {
                    continue; // Пропускаем обработку персонажа, если биом не найден
                }

                // Расчет ресурсов еды и воды, которые нужно потребить
                $foodToConsume = $this->calculateResourceConsumption($character['level'], $biome['survival_difficulty'], 'food');
                $waterToConsume = $this->calculateResourceConsumption($character['level'], $biome['survival_difficulty'], 'water');

                // Вычитание ресурсов и отправка сообщения
                $result = $this->subtractResources($character, $foodToConsume, $waterToConsume);

                if (!$result['healthSubtracted']) {
                    // Используйте $result['totalFoodResources'] и $result['totalWaterResources']
                    // для отображения остатков в сообщении
                    $this->sendMessageToTelegram(
                        $telegramId['telegram_id'],
                        $foodToConsume,
                        $waterToConsume,
                        $result['totalFoodResources'],
                        $result['totalWaterResources']
                    );
                }
            }
        }
    }

    private function calculateTotalResources($characterId)
    {
        $resources = $this->resourceModel->getCharacterResources($characterId);
        $totalFoodResources = 0;
        $totalWaterResources = 0;

        foreach ($resources as $resource) {
            $types = explode(',', $resource['type']);
            if ($resource['type'] === 'food' || in_array('food', $types)) {
                $totalFoodResources += $resource['quantity'];
            } elseif ($resource['type'] === 'water' || in_array('water', $types)) {
                $totalWaterResources += $resource['quantity'];
            }
        }

        return [$totalFoodResources, $totalWaterResources];
    }


    private function subtractResources($character, $foodToConsume, $waterToConsume)
    {
        $characterId = $character['id'];
        $health = $character['health'];
        $resources = $this->resourceModel->getCharacterResources($characterId);
        $totalFoodResources = 0;
        $totalWaterResources = 0;
        $healthSubtracted = false; // Флаг для отслеживания, было ли списание здоровья

        if (!empty($resources)) {
            usort($resources, function($a, $b) {
                return $b['rarity'] <=> $a['rarity'];
            });

            $foodConsumed = 0;
            $waterConsumed = 0;

            // Инициализация переменных для отслеживания нехватки ресурсов
            $missingFood = $foodToConsume;
            $missingWater = $waterToConsume;
            foreach ($resources as $resource) {
                if (array_key_exists('quantity', $resource)) {
                    $types = explode(',', $resource['type']);

                    // Проверяем, есть ли у персонажа достаточно еды для потребления
                    if ($foodConsumed < $foodToConsume && $resource['quantity'] > 0 && (in_array('food', $types) || $resource['type'] == 'food')) {
                        $consumption = min($resource['quantity'], $foodToConsume - $foodConsumed);
                        $this->resourceModel->subtractResources($characterId, $resource['id'], $consumption);
                        $foodConsumed += $consumption;
                        $totalFoodResources += $consumption;
                        $missingFood -= $consumption;
                    }

                    // Проверяем, есть ли у персонажа достаточно воды для потребления
                    if ($waterConsumed < $waterToConsume && $resource['quantity'] > 0 && (in_array('water', $types) || $resource['type'] == 'water')) {
                        $consumption = min($resource['quantity'], $waterToConsume - $waterConsumed);
                        $this->resourceModel->subtractResources($characterId, $resource['id'], $consumption);
                        $waterConsumed += $consumption;
                        $totalWaterResources += $consumption;
                        $missingWater -= $consumption;
                    }

                    // Проверяем, достигнуты ли условия для завершения цикла
                    if ($foodConsumed >= $foodToConsume && $waterConsumed >= $waterToConsume) {
                        break;
                    }
                } else {
                    log_message('error', 'Key "quantity" is missing in resource array');
                }
            }

            // Проверка на недостаток ресурсов и списание здоровья
            if (($foodConsumed < $foodToConsume || $waterConsumed < $waterToConsume) && $health > 0.02) {
                $healthToSubtract = max(0.01, $health * 0.5);
                $this->subtractHealth($character, $healthToSubtract, max(0, $missingFood), max(0, $missingWater));
                $healthSubtracted = true; // Устанавливаем флаг, что здоровье было списано
            }
        } else {
            log_message('error', 'Failed to retrieve resources for character ID: ' . $characterId);
        }

        // После вычитания ресурсов вызовите метод для проверки и удаления ресурса при необходимости
        $this->deleteResourceIfNeeded($characterId);
        // Возвращает актуальные остатки после вычитания
        list($totalFoodResources, $totalWaterResources) = $this->calculateTotalResources($characterId);

        // Возвращает массив с информацией о списании ресурсов и здоровья
        return [
            'healthSubtracted' => $healthSubtracted,
            'totalFoodResources' => $totalFoodResources,
            'totalWaterResources' => $totalWaterResources
        ];
    }

    private function calculateResourceConsumption($level, $survivalDifficulty, $resourceType)
    {
        $resourceToConsume = ($level * $survivalDifficulty) / 10;

        switch ($resourceType) {
            case 'food':
                $resourceToConsume *= 0.6;
                break;
            case 'water':
                $resourceToConsume *= 0.7;
                break;
        }

        return $resourceToConsume;
    }

    private function subtractHealth($character, $healthToSubtract, $missingFood = 0, $missingWater = 0)
    {
        $telegramId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first()['telegram_id'];
        $newHealth = max(0.01, $character['health'] / 2); // Новое значение здоровья, не ниже 0.01
        $this->characterModel->update($character['id'], ['health' => $newHealth]); // Обновление здоровья в базе данных

        // Добавляем информацию о недостатке ресурсов в текст сообщения
        $resourceMessage = '';
        if ($missingFood > 0) {
            $resourceMessage .= "🍔 __Недостаточно еды:__ " . number_format($missingFood, 2) . " единиц.\n";
        }
        if ($missingWater > 0) {
            $resourceMessage .= "💧 __Недостаточно воды:__ " . number_format($missingWater, 2) . " единиц.\n";
        }

        $text = "👤 *Персонаж теряет здоровье!* 💔\n\n"
            . "*У вас недостаточно еды или воды*\n\n"
            . $resourceMessage // Вставляем информацию о недостатке ресурсов
            . "*Здоровье персонажа просело на:* {$healthToSubtract}%\n\n"
            . "*💖 Текущее здоровье:* {$newHealth}\n\n"
            . "*Срочно пополните запасы*\n\n"
            . "*P.S.*: 👀\nТебе нужно выйти за ресурсами 🗺 \nили купить еды у торговца! 🛒\n\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⛏️ Добыть ресурсы', 'callback_data' => 'gather'],
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                ]
            ]
        ];
        $imagePath = base_url('uploads/telegram/water_and_food_resources.png'); // Укажите актуальный путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $telegramId]);

        // Ответ в телеграм
        return Request::sendPhoto([
            'chat_id' => $telegramId,
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    public function sendMessageToTelegram($telegramId, $foodToConsume, $waterToConsume, $totalFoodResources, $totalWaterResources)
    {
        $text = "👤 *Персонаж отлично покушал!* 😋\n\n"
            . "*Съел и Выпил:*\n\n"
            . "*🍔 Еды:* {$foodToConsume} 🍖\n\n"
            . "*💧 Воды:* {$waterToConsume} 💦\n\n"
            . "*Осталось ресурсов пропитания:*\n\n"
            . "🔹*Еды:* ". number_format($totalFoodResources)." 🌾\n"
            . "🔹*Воды:* ". number_format($totalWaterResources)." 💧\n\n"
            . "*P.S.*: 👀 Не забудь пополнить запасы провизии! 😉\n\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                ]
            ]
        ];
        $imagePath = base_url('uploads/telegram/water_and_food_resources.png'); // Укажите актуальный путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $telegramId]);

        // Ответ в телеграм
        return Request::sendPhoto([
            'chat_id' => $telegramId,
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);

    }

    private function deleteResourceIfNeeded($characterId)
    {
        // Получите список ресурсов у персонажа
        $resources = $this->resourceModel->getCharacterResources($characterId);

        foreach ($resources as $resource) {
            // Проверьте, равно ли количество ресурса нулю
            if ($resource['quantity'] <= 0) {
                // Если да, удалите запись о ресурсе
                $this->characterResourceModel->delete($resource['id']);
            }
        }
    }


}

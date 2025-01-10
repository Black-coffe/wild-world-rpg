<?php

namespace App\TaskHandlers\Events;

use App\Models\CharacterModel;
use App\Models\BiomeModel;
use App\Models\TelegramUserModel;
use App\Models\EventEffectsLogModel;
use App\Models\EventModel;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class EpidemicHandler
{
    protected $characterModel;
    protected $biomeModel;
    protected $eventModel;
    protected $telegramUserModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->biomeModel = new BiomeModel();
        $this->eventModel = new EventModel();
        $this->telegramUserModel = new TelegramUserModel();

        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    public function process()
    {
        if (mt_rand(0, 100) >= 10) {
            return; // 90% шанс на то, что событие не будет обработано
        }

        if (!$this->isEventActive('Epidemic')) {
            return; // Прекращаем выполнение, если событие не активно
        }

        $allCharacters = $this->characterModel->findAll();
        $characterCount = count($allCharacters);
        $affectedCount = ceil($characterCount / 100); // Расчет количества заболевших в зависимости от общего числа персонажей

        for ($i = 0; $i < $affectedCount; $i++) {
            $randomCharacter = $allCharacters[array_rand($allCharacters)];
            $this->applyEpidemicEffects($randomCharacter);
        }
    }

    protected function applyEpidemicEffects($character)
    {
        $biome = $this->biomeModel->find($character['biome_id']);
        if (!$biome) {
            return;
        }
        $epidemicRisk = $this->calculateEpidemicRisk($biome, $character['level']);

        if (mt_rand(0, 100) >= 2) {
            $parameterAffected = $this->affectCharacter($character, $biome);

            // Логируем эффекты эпидемии на персонажа
            $this->logEpidemicEffects($character['id'], $parameterAffected);

            $this->notifyCharacter($character, $parameterAffected);
        }
    }

    protected function logEpidemicEffects($characterId, $parameterAffected)
    {
        $logModel = new EventEffectsLogModel();
        $effectDetails = json_encode([
            'affectedParameter' => $parameterAffected,
        ]);

        $logData = [
            'character_id' => $characterId,
            'event_id' => $this->eventModel->where('name_english', 'Epidemic')->first()['event_id'], // Предполагается, что у вас есть такое поле
            'effect_details' => $effectDetails,
            'event_time' => date('Y-m-d H:i:s'),
        ];

        // Добавляем информацию о биоме и ячейке персонажа, если необходимо
        $characterInfo = $this->characterModel->find($characterId);
        if ($characterInfo) {
            $logData['cell_number'] = $characterInfo['cell_number'] ?? null;
            $logData['biome_id'] = $characterInfo['biome_id'] ?? null;
        }

        $logModel->addLog($logData);
    }

    protected function notifyCharacter($character, $parameterAffected)
    {
        $telegramUser = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$telegramUser) {
            return;
        }
        $chatId = $telegramUser['telegram_id'];

        $message = "⚠️ *Внимание!* На вашего персонажа повлияла эпидемия 🦠. \n\n";

        foreach ($parameterAffected as $parameter => $value) {
            $translated = $this->translateParameter($parameter, $value);
            $message .= $translated . "\n";
        }

        $message .= "\nЧтобы уменьшить риск заражения или восстановить потерянные параметры, используйте препараты из аптечки против эпидемий 💉.";
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        try {
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке фото: " . $e->getMessage());
        }
    }

    protected function translateParameter($parameter, $value)
    {
        $icons = [
            'experience' => '🌟 Опыт',
            'health' => '💖 Здоровье',
            'strength' => '💪 Сила',
            'agility' => '🤸‍♂️ Ловкость',
            'intellect' => '🧠 Интеллект',
            'tired' => '🥱 Выносливость',
        ];

        $translations = [
            'experience' => 'Опыт',
            'health' => 'Здоровье',
            'strength' => 'Сила',
            'agility' => 'Ловкость',
            'intellect' => 'Интеллект',
            'tired' => 'Выносливость',
        ];

        $icon = $icons[$parameter] ?? '❓';
        $translatedParam = $translations[$parameter] ?? 'Неизвестный параметр';

        return "{$icon}: -{$value}";
    }

    protected function calculateEpidemicRisk($biome, $characterLevel)
    {
        // Проверка наличия данных о уровне опасности и сложности выживания биома
        if (!isset($biome['danger_level']) || !isset($biome['survival_difficulty'])) {
            // Если данные отсутствуют, присваиваем случайные значения от 3 до 7
            $biome['danger_level'] = rand(3, 7);
            $biome['survival_difficulty'] = rand(3, 7);
        }

        // Влияние биома и уровня персонажа на риск заражения
        $biomeEffect = $biome['danger_level'] + $biome['survival_difficulty'];
        $levelEffect = 100 - min(100, $characterLevel); // Чем выше уровень, тем меньше шанс заражения

        return ($biomeEffect * $levelEffect) / 100;
    }

    protected function affectCharacter($character, $biome)
    {
        $parameter = $this->selectRandomParameter();
        $decrement = $this->calculateParameterDecrement($parameter, $biome);

        $newValue = max(0.01, $character[$parameter] - $decrement);
        $this->characterModel->update($character['id'], [$parameter => $newValue]);

        // Возвращаем информацию о том, какой параметр был изменен и на сколько
        return [$parameter => $decrement];
    }

    protected function selectRandomParameter()
    {
        $parameters = ['experience', 'health', 'strength', 'agility', 'intellect', 'tired'];
        return $parameters[array_rand($parameters)];
    }

    protected function calculateParameterDecrement($parameter, $biome)
    {
        // Проверка наличия данных о уровне опасности и сложности выживания биома
        if (!isset($biome['danger_level']) || !isset($biome['survival_difficulty'])) {
            // Если данные отсутствуют, присваиваем случайные значения от 3 до 7
            $biome['danger_level'] = rand(3, 7);
            $biome['survival_difficulty'] = rand(3, 7);
        }
        // Уменьшение зависит от типа параметра и характеристик биома
        $baseDecrement = rand(1, 10) / 100; // Базовое уменьшение от 0.01 до 0.1
        return $baseDecrement + ($biome['danger_level'] + $biome['survival_difficulty']) / 200; // Увеличиваем уменьшение на основе биома
    }

    protected function isEventActive($eventNameEnglish)
    {
        $eventInfo = $this->eventModel->where('name_english', $eventNameEnglish)->first();
        if (!$eventInfo) {
            return false; // Если событие не найдено в базе
        }

        // Используем модель ActiveEventModel для проверки активности
        $activeEventModel = new \App\Models\ActiveEventModel();
        $activeEvent = $activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();

        return !empty($activeEvent);
    }

}

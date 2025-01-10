<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterModel;
use App\Models\ActiveEventModel;
use App\Models\EventModel;
use App\Models\BiomeModel;

use DateTime;

class EventAction extends BaseAction
{
    protected $characterModel;
    protected $activeEvents;
    protected $events;
    protected $biomeModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
        $this->activeEvents = new ActiveEventModel();
        $this->events = new EventModel();
        $this->biomeModel = new BiomeModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $activeEvents = $this->activeEvents->where('status', 'active')->findAll();
        $text = "🌟 *На текущий момент в мире развернулись следующие события:* 🌟\n\n";

        if (!empty($activeEvents)) {
            foreach ($activeEvents as $index => $event) {
                $eventDetails = $this->events->find($event['event_id']);
                $endTime = new DateTime($event['end_time']);
                $now = new DateTime();
                $interval = $now->diff($endTime);
                $timeLeft = $interval->format('%a дн. %H чс. %I мин.');

                $biomesText = '';
                if (!empty($eventDetails['biome_ids'])) {
                    $biomeIds = json_decode($eventDetails['biome_ids'], true);
                    $biomes = []; // Массив для хранения названий биомов

                    foreach ($biomeIds as $biomeId) {
                        $biome = $this->biomeModel->find($biomeId); // Предполагается, что у вас есть метод find в модели BiomeModel
                        if ($biome) {
                            $biomes[] = $biome['name']; // Предполагается, что у биома есть поле name
                        }
                    }

                    if (!empty($biomes)) {
                        $biomesText = "🌱 *Биомы:* " . implode(', ', $biomes) . "\n\n";
                    }
                }

                $text .= "№" . ($index + 1) . " *{$eventDetails['name']}*\n\n"
                    . "📜 *Описание:* _{$eventDetails['description']}_\n\n"
                    . "🌍 *Тип события:* _" . $this->translateEventType($eventDetails['event_type']) . "_\n\n"
                    . $biomesText // Добавляем список биомов к сообщению
                    . "🚀 *Влияние на персонажа:* _" . $this->translateEffectType($eventDetails['effect_type']) . "_\n\n"
                    . "⏳ *Событие закончится через:* _" . $timeLeft . "_\n\n";
            }
        } else {
            $text = "🎉 На текущий момент активных событий нет. Исследуйте мир и готовьтесь к новым приключениям! 🎉";
        }

        $keyboard = $this->getKeyboard();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    protected function translateEventType($type)
    {
        $translations = [
            'local' => 'Выборочно в указанных биомах',
            'global' => 'Повсеместно в указанных биомах'
        ];
        return $translations[$type] ?? $type;
    }

    protected function translateEffectType($type)
    {
        $translations = [
            'damage' => 'Урон',
            'heal' => 'Лечение',
            'buff' => 'Усиление',
            'debuff' => 'Ослабление',
            'none' => 'Без эффекта'
        ];
        return $translations[$type] ?? $type;
    }

    protected function getKeyboard()
    {
        // Метод для получения клавиатуры, если нужно изменить структуру
        return [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];
    }

}

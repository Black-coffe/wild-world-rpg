<?php

namespace App\Controllers\Telegram\Commands\Actions\PVP;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\TelegramUserModel;
use App\Services\Player\PlayerDetectionService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

/**
 * Класс для обработки нажатия кнопки "🏃 Бежать" в PVP.
 */
class RunAwayAction extends BaseAction
{
    protected $characterModel;
    protected $mapModel;
    protected $telegramUserModel;
    protected $playerDetectionService;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->characterModel = new CharacterModel();
        $this->mapModel = new MapModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->playerDetectionService = new PlayerDetectionService();
    }

    /**
     * Основной метод обработки логики побега.
     */
    public function handle(): ServerResponse
    {
        // 1. Получаем объекты пользователя и персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError("Пользователь или персонаж не найден в базе данных.");
        }

        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // 2. Проверяем, хватает ли золота
        if ($character['gold'] < 1000) {
            return $this->sendError("Недостаточно золота для побега (необходимо 1000).");
        }

        // 3. Считаем новое здоровье, выносливость, золото
        $newHealth = max(1, $character['health'] * 0.5);  // отнимаем 50%
        $newTired  = max(1, $character['tired'] * 0.1);   // отнимаем 90%
        $newGold   = $character['gold'] - 1000;           // отнимаем 1000 золотых

        // 4. Расчёт дистанции для побега (10..50)
        $distanceMin = 10;
        $distanceMax = 50;
        $levelFactor  = min(1.0, $character['level'] / 1000.0);
        $healthFactor = min(1.0, $newHealth / 100.0);
        $tiredFactor  = min(1.0, $newTired / 100.0);

        $distancePossible = ($levelFactor + $healthFactor + $tiredFactor) * $distanceMax;
        if ($distancePossible < $distanceMin) {
            $distancePossible = $distanceMin;
        }
        $distanceInt = rand($distanceMin, (int) round($distancePossible));

        // 5. Многократная попытка выбрать рабочее направление
        $directions = [
            ['dx' =>  1, 'dy' =>  0], // Восток
            ['dx' => -1, 'dy' =>  0], // Запад
            ['dx' =>  0, 'dy' =>  1], // Юг
            ['dx' =>  0, 'dy' => -1], // Север
            ['dx' =>  1, 'dy' =>  1], // Юго-восток
            ['dx' => -1, 'dy' =>  1], // Юго-запад
            ['dx' =>  1, 'dy' => -1], // Северо-восток
            ['dx' => -1, 'dy' => -1], // Северо-запад
        ];

        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$currentCell) {
            return $this->sendError("Текущая локация персонажа не найдена.");
        }

        $origX = $currentCell['coordinate_x'];
        $origY = $currentCell['coordinate_y'];

        $targetCell = null;
        $maxAttempts = 20; // Сколько раз пробуем выбрать случайное направление
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $randDirection = $directions[array_rand($directions)];
            $newX = $origX + $randDirection['dx'] * $distanceInt;
            $newY = $origY + $randDirection['dy'] * $distanceInt;

            // Пытаемся найти ячейку
            $targetCell = $this->mapModel
                ->where('coordinate_x', $newX)
                ->where('coordinate_y', $newY)
                ->first();

            if ($targetCell) {
                // Нашли подходящую ячейку — выходим из цикла
                break;
            }
        }

        if (!$targetCell) {
            // Не нашли ни одного подходящего направления
            // Дополняем сообщение пояснениями, что делать игроку
            return $this->sendError(
                "Не удалось найти подходящую ячейку для побега. " .
                "Вероятно, вы на краю карты или у вас слишком мало параметров. " .
                "Попробуйте восстановить силы, поднять уровень и попытаться снова!"
            );
        }

        // 6. Обновляем данные персонажа (health, tired, gold, cell_number)
        $this->characterModel->update($character['id'], [
            'health'     => $newHealth,
            'tired'      => $newTired,
            'gold'       => $newGold,
            'cell_number'=> $targetCell['cell_number'],
        ]);

        // 7. Формируем итоговое сообщение
        $text = "🏃 <b>Вы решили бежать!</b>\n\n"
            . "🌍 <b>Перемещение завершено</b> на дистанцию <b>{$distanceInt}</b> ячеек.\n"
            . "🗺 <b>Новая локация:</b> Ячейка №{$targetCell['cell_number']} (X={$targetCell['coordinate_x']}, Y={$targetCell['coordinate_y']}).\n\n"
            . "<b>Текущее здоровье:</b> ".(float)$newHealth." из 100\n"
            . "<b>Выносливость:</b> ".(float)$newTired." из 100\n"
            . "<b>Золото:</b> ".(int)$newGold."\n\n"
            . "Осталось лишь надеяться, что вас не настигнут вновь...";

        // ADR-150 (чистка дублей): после побега важно уйти дальше — «🧭 Идти» + «Поход».
        // «Инвентарь»/«События» переехали на нижнюю панель, из боя они не нужны.
        $keyboard = \App\Services\Telegram\NavKeyboards::simplified()
            ? \App\Services\Telegram\NavKeyboards::whatNextWith([
                [
                    ['text' => '🗺️ Поход',    'callback_data' => 'march'],
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ],
            ])
            : [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🗺️ Поход', 'callback_data' => 'march'],
                    ],
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '🎉 События',   'callback_data' => 'events'],
                    ],
                ]
            ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // 8. Отправляем сообщение о результатах побега
        $response = Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($keyboard),
        ]);

        // 9. Проверка на обнаружение других игроков в новой точке
        try {
            $this->playerDetectionService->detectNearbyPlayers($character['id']);
        } catch (TelegramException $e) {
            log_message('error', "RunAwayAction detectNearbyPlayers error: " . $e->getMessage());
        }

        return $response;
    }

    /**
     * Унифицированный метод для отправки ошибок пользователю.
     */
    protected function sendError(string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text' => $msg,
            'show_alert' => true,
        ]);

        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $msg,
        ]);
    }
}

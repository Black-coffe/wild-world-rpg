<?php

namespace App\Services\Player;

use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\PlayerDetectionHistoryModel;
use App\Models\TelegramUserModel;
use Config\GameBalance;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class PlayerDetectionService
{
    protected $characterModel;
    protected $mapModel;
    protected $telegramUserModel;
    protected $detectionHistoryModel;
    protected $telegram;
    private GameBalance $cfg;

    /**
     * F2.10 wire-in: $cfg инжектируется опционально (для тестов), по умолчанию
     * читается из config('GameBalance') — централизованный balance config.
     */
    public function __construct(?GameBalance $cfg = null)
    {
        $this->cfg                  = $cfg ?? config('GameBalance');
        $this->characterModel = new CharacterModel();
        $this->mapModel = new MapModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->detectionHistoryModel = new PlayerDetectionHistoryModel();

        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');

        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', 'Ошибка инициализации Telegram: ' . $e->getMessage());
        }
    }

    /**
     * Обнаруживает близких игроков для данного персонажа и отправляет уведомления.
     *
     * @param int $characterId ID персонажа, который переместился.
     * @return void
     */
    public function detectNearbyPlayers(int $characterId): void
    {
        // Получаем информацию о персонаже
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            log_message('error', "Персонаж с ID {$characterId} не найден.");
            return;
        }

        // Проверяем наличие номера ячейки
        if (!isset($character['cell_number']) || !$character['cell_number']) {
            log_message('error', "Персонаж с ID {$characterId} не имеет номера ячейки.");
            return;
        }

        // Получаем координаты текущей ячейки
        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$currentCell) {
            log_message('error', "Ячейка с номером {$character['cell_number']} не найдена.");
            return;
        }

        $x1 = $currentCell['coordinate_x'];
        $y1 = $currentCell['coordinate_y'];

        // Получаем уровень персонажа
        $level = isset($character['level']) ? (int)$character['level'] : 0;

        // Новый расчёт радиуса (detectionRadiusBase=2 + floor(level/500), capped на 3 — все из GameBalance):
        $detectionRadius = $this->cfg->detectionRadiusBase + (int) floor($level / $this->cfg->detectionRadiusDivisor);
        $detectionRadius = min($detectionRadius, $this->cfg->detectionRadiusMax);

        // Дальше всё как прежде:
        $detectionRadiusSq = $detectionRadius * $detectionRadius;

        // Определяем границы для предварительной фильтрации
        $minX = $x1 - $detectionRadius;
        $maxX = $x1 + $detectionRadius;
        $minY = $y1 - $detectionRadius;
        $maxY = $y1 + $detectionRadius;

        // Получаем всех игроков, находящихся в пределах границ, исключая текущего игрока
        $builder = $this->characterModel->select('characters.id, characters.cell_number, map.coordinate_x, map.coordinate_y')
            ->join('map', 'characters.cell_number = map.cell_number', 'inner')
            ->where('characters.id !=', $characterId)
            ->where('map.coordinate_x >=', $minX)
            ->where('map.coordinate_x <=', $maxX)
            ->where('map.coordinate_y >=', $minY)
            ->where('map.coordinate_y <=', $maxY);

        $potentialPlayers = $builder->findAll();

        $detectedPlayers = [];

        foreach ($potentialPlayers as $player) {
            $x2 = $player['coordinate_x'];
            $y2 = $player['coordinate_y'];

            $dx = $x2 - $x1;
            $dy = $y2 - $y1;

            $distanceSq = ($dx * $dx) + ($dy * $dy);

            if ($distanceSq <= $detectionRadiusSq) {
                $distance = (int) sqrt($distanceSq); // Округляем до целого числа

                // Проверяем, было ли уже отправлено уведомление об этом игроке
                if ($this->canSendNotification($characterId, $player['id'])) {
                    $detectedPlayers[] = [
                        'id' => $player['id'],
                        'cell_number' => $player['cell_number'],
                        'distance' => $distance,
                    ];

                    // Сохраняем запись в историю обнаружений
                    $this->detectionHistoryModel->insert([
                        'detector_player_id' => $characterId,
                        'detected_player_id' => $player['id'],
                        'detected_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        }

        // Если найдены игроки, отправляем уведомление
        if (!empty($detectedPlayers)) {
            // Получаем Telegram chat_id детектора
            $telegramUser = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
            if (!$telegramUser || !isset($telegramUser['telegram_id'])) {
                log_message('error', "Telegram пользователь не найден для персонажа ID {$characterId}.");
                return;
            }

            $chatId = $telegramUser['telegram_id'];

            // Формируем текст сообщения
            $message = "🔍 *Обнаружение игроков!* 🔍\n\n";
            $keyboardRows = [];

            foreach ($detectedPlayers as $detected) {
                $message .= "Есть игрок №{$detected['id']} на расстоянии {$detected['distance']} ячеек от тебя.\n";

                // Для каждой цели можно сделать и отдельную строку клавиатуры,
                // или общий набор кнопок, если нужно.
                $keyboardRows[] = [
                    [
                        'text' => '🏃 Бежать',
                        'callback_data' => 'runAway'
                    ],
                    [
                        'text' => '⚔️ Атаковать',
                        // ВАЖНО: приклеиваем сюда ID найденного игрока
                        'callback_data' => 'attackPlayer_' . $detected['id']
                    ],
                ];
            }

            $message .= "\n_Вы можете предпринять дальнейшие действия._";

            // Если хотим все цели на одной клавиатуре, можно объединять кнопки
            // Но логичнее для каждого игрока – одна «строка» кнопок
            $keyboard = [
                'inline_keyboard' => $keyboardRows
            ];

            // Отправляем сообщение через Telegram
            try {
                Request::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode($keyboard),
                ]);
            } catch (TelegramException $e) {
                log_message('error', 'Ошибка отправки сообщения в Telegram: ' . $e->getMessage());
            }
        }

    }

    /**
     * Проверяет, можно ли отправить уведомление о данном игроке.
     *
     * @param int $detectorPlayerId ID игрока, который обнаруживает.
     * @param int $detectedPlayerId ID обнаруженного игрока.
     * @return bool Возвращает true, если уведомление можно отправить.
     */
    protected function canSendNotification($detectorPlayerId, $detectedPlayerId)
    {
        $lastDetection = $this->detectionHistoryModel
            ->where('detector_player_id', $detectorPlayerId)
            ->where('detected_player_id', $detectedPlayerId)
            ->orderBy('detected_at', 'desc')
            ->first();

        // Если запись не найдена, значит уведомление можно отправить
        if (!$lastDetection) {
            return true;
        }

        $lastDetectedAt = strtotime($lastDetection['detected_at']);
        $currentTime = time();

        // Проверяем, прошло ли больше 1 часа с последнего уведомления (3600 секунд)
        return ($currentTime - $lastDetectedAt) > 3;
    }
}

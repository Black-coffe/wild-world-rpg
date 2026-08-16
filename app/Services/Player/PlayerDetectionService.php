<?php

namespace App\Services\Player;

use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\PlayerDetectionHistoryModel;
use App\Models\TelegramUserModel;
use Config\GameBalance;
use Longman\TelegramBot\Exception\TelegramException;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Telegram;

class PlayerDetectionService
{
    protected $characterModel;
    protected $mapModel;
    protected $telegramUserModel;
    protected $detectionHistoryModel;
    protected $telegram;
    private GameBalance $cfg;
    private ?\App\Services\PVE\DuelService $duelService = null;

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
     * @return bool true — если был обнаружен и нотифицирован хотя бы один игрок
     *              (вне cooldown'а). Используется `MarchingTaskHandler` (ADR-019 §4):
     *              поход встаёт на паузу с промптом «атаковать/бежать». Существующие
     *              callers вызывают как statement и возврат игнорируют.
     */
    public function detectNearbyPlayers(int $characterId): bool
    {
        // Получаем информацию о персонаже
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            log_message('error', "Персонаж с ID {$characterId} не найден.");
            return false;
        }

        // Проверяем наличие номера ячейки
        if (!isset($character['cell_number']) || !$character['cell_number']) {
            log_message('error', "Персонаж с ID {$characterId} не имеет номера ячейки.");
            return false;
        }

        // Получаем координаты текущей ячейки
        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$currentCell) {
            log_message('error', "Ячейка с номером {$character['cell_number']} не найдена.");
            return false;
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
        // Идея #3 (15.01.2025): включаем characters.name для замены числового ID на ник.
        $builder = $this->characterModel->select('characters.id, characters.name, characters.cell_number, map.coordinate_x, map.coordinate_y')
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

                $detectedPid = is_numeric($player['id'] ?? null) ? (int) $player['id'] : 0;

                // Проверяем, было ли уже отправлено уведомление об этом игроке
                if ($detectedPid > 0 && $this->canSendNotification($characterId, $detectedPid)) {
                    $detectedPlayers[] = [
                        'id' => $detectedPid,
                        'name' => $player['name'] ?? '',
                        'cell_number' => $player['cell_number'],
                        'distance' => $distance,
                    ];

                    // Сохраняем запись в историю обнаружений
                    $this->detectionHistoryModel->insert([
                        'detector_player_id' => $characterId,
                        'detected_player_id' => $detectedPid,
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
                return false;
            }

            $chatId = $telegramUser['telegram_id'];

            // Формируем текст сообщения
            $message = "🔍 *Обнаружение игроков!* 🔍\n\n";
            $keyboardRows = [];

            foreach ($detectedPlayers as $detected) {
                // Идея #3 (15.01.2025): показываем ник, fallback на №id если ник не задан.
                $nameTag = $detected['name'] !== '' ? "*{$detected['name']}*" : "№{$detected['id']}";
                $message .= "Есть игрок {$nameTag} на расстоянии {$detected['distance']} ячеек от тебя.\n";

                // Для каждой цели можно сделать и отдельную строку клавиатуры,
                // или общий набор кнопок, если нужно.
                $row = [
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

                // W17 (ADR-071): «🤺 Дуэль» только при активном killswitch и если цель открыта
                // к дуэлям. При dormant (killswitch OFF) — детект 100% без изменений (0 риска).
                if ($this->duelsEnabledAndOpen((int) $detected['id'])) {
                    $row[] = [
                        'text'          => '🤺 Дуэль',
                        'callback_data' => 'duel_' . $detected['id'],
                    ];
                }

                $keyboardRows[] = $row;
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

        return !empty($detectedPlayers);
    }

    /**
     * Проверяет, можно ли отправить уведомление о данном игроке.
     *
     * @param int $detectorPlayerId ID игрока, который обнаруживает.
     * @param int $detectedPlayerId ID обнаруженного игрока.
     * @return bool Возвращает true, если уведомление можно отправить.
     */
    /**
     * W17 (ADR-071) — показывать ли «🤺 Дуэль» для цели: killswitch ON И цель открыта.
     * Killswitch проверяется ПЕРВЫМ → при dormant (OFF) запросов нет (детект без изменений,
     * 0 риска до миграции duels_open). Raw db-builder — без model-builder-state quirk в цикле.
     */
    private function duelsEnabledAndOpen(int $targetId): bool
    {
        if ($this->duelService === null) {
            $this->duelService = new \App\Services\PVE\DuelService();
        }
        if (! $this->duelService->enabled()) {
            return false;
        }
        $row = \Config\Database::connect()->table('characters')
            ->select('duels_open')->where('id', $targetId)->get();
        $arr = $row === false ? null : $row->getRowArray();
        return is_array($arr) && is_numeric($arr['duels_open'] ?? null) && (int) $arr['duels_open'] === 1;
    }

    protected function canSendNotification(int $detectorPlayerId, int $detectedPlayerId): bool
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

        return ($currentTime - $lastDetectedAt) > $this->cfg->playerDetectionCooldownSec;
    }
}

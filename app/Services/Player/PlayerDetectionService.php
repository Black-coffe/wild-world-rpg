<?php

namespace App\Services\Player;

use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\PlayerDetectionHistoryModel;
use App\Models\TelegramUserModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Telegram\ButtonPacker;
use Config\Database;
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
    private GameSettingsService $settings;
    private PvPRestrictionService $restriction;
    private ?\App\Services\PVE\DuelService $duelService = null;

    /**
     * F2.10 wire-in: $cfg инжектируется опционально (для тестов), по умолчанию
     * читается из config('GameBalance') — централизованный balance config.
     *
     * pvp-detection-clarity-07: $settings/$restriction — та же опциональная DI-схема
     * (паттерн `PvPRestrictionService`), нужна тестам, чтобы собирать
     * `renderDetectionMessage()` без реальной сети/окружения.
     */
    public function __construct(
        ?GameBalance $cfg = null,
        ?GameSettingsService $settings = null,
        ?PvPRestrictionService $restriction = null
    ) {
        $this->cfg                   = $cfg ?? config('GameBalance');
        $this->settings               = $settings ?? new GameSettingsService();
        $this->restriction            = $restriction ?? new PvPRestrictionService();
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

        // Получаем всех игроков, находящихся в пределах границ, исключая текущего игрока.
        // pvp-detection-clarity-07: добавлены level/created_at — нужны замку PvPRestrictionService
        // (сам SQL-запрос по-прежнему БЕЗ LIMIT/ORDER BY — non-goal, кулдаун ключуется парой).
        $builder = $this->characterModel->select(
            'characters.id, characters.name, characters.level, characters.created_at, characters.cell_number, map.coordinate_x, map.coordinate_y'
        )
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
                        'id'          => $detectedPid,
                        'name'        => $player['name'] ?? '',
                        'level'       => is_numeric($player['level'] ?? null) ? (int) $player['level'] : 0,
                        'created_at'  => $player['created_at'] ?? null,
                        'cell_number' => $player['cell_number'],
                        'distance'    => $distance,
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
            // Идея #3 (15.01.2025): показываем ник, fallback на №id если ник не задан.
            // pvp-detection-clarity-07: признак «брошенного» — по `action_log.created_at`,
            // НЕ `characters.last_update_time` (пуста у всех 709 строк на проде).
            $ids = array_column($detectedPlayers, 'id');
            $lastActiveMap = $this->fetchLastActiveMap($ids);
            foreach ($detectedPlayers as &$dp) {
                $dp['last_active_at'] = $lastActiveMap[$dp['id']] ?? null;
            }
            unset($dp);

            // Получаем Telegram chat_id детектора
            $telegramUser = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
            if (!$telegramUser || !isset($telegramUser['telegram_id'])) {
                log_message('error', "Telegram пользователь не найден для персонажа ID {$characterId}.");
                return false;
            }

            $chatId = $telegramUser['telegram_id'];

            // 🔴 12/14/true ниже — не правило (рендер им ничего не сравнивает напрямую), а
            // safety net третьим аргументом `get()` на случай непримененной миграции: значения
            // байт-в-байт равны дефолтам `Adr186SeedDetectionSettings` (паттерн
            // `PvPRestrictionService`). Без него отсутствие строки дало бы `(int) null = 0`
            // и обрушило бы `max_listed` в ноль — список пропал бы вовсе.
            $maxListed           = (int) $this->settings->get('world.detection.max_listed', 12);
            $inactiveDays        = (int) $this->settings->get('world.detection.inactive_days', 14);
            $showInactiveSummary = (bool) $this->settings->get('world.detection.show_inactive_summary', true);

            $rendered = $this->renderDetectionMessage($character, $detectedPlayers, $maxListed, $inactiveDays, $showInactiveSummary);

            // Отправляем сообщение через Telegram
            try {
                Request::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $rendered['text'],
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode($rendered['keyboard']),
                ]);
            } catch (TelegramException $e) {
                log_message('error', 'Ошибка отправки сообщения в Telegram: ' . $e->getMessage());
            }
        }

        return !empty($detectedPlayers);
    }

    /**
     * Собирает текст и клавиатуру сообщения обнаружения без похода в Telegram API —
     * `detectNearbyPlayers()` зовёт её после сбора данных, тест зовёт её напрямую
     * на синтетических соседях (не создавая ни одного реального запроса).
     *
     * Гарантии:
     *  - не более `$maxListed` соседей построчно, порядок: недавно действовавшие → ближе → id;
     *  - ЖЁСТКАЯ граница длины текста (`MAX_TEXT_CHARS`) — работает НЕЗАВИСИМО от `$maxListed`:
     *    даже если ручку в admin UI поднимут выше разумного, список остальных соседей уйдёт
     *    в строку-остаток, а не оборвёт сообщение на полуслове, как в прод-инциденте;
     *  - кнопок в клавиатуре не больше рядов показанных соседей — упаковано `ButtonPacker`
     *    (2–3 в ряд, без одиночных строк), «🏃 Бежать» одна на всё сообщение;
     *  - для соседа, которому `PvPRestrictionService::checkPvPAllowed()` отказывает, вместо
     *    «⚔️ Атаковать» — «🔒 <причина>» на том же `callback_data`: тап уходит в существующий
     *    `AttackPlayerAction`, который сам вызывает `checkPvPAllowed()` и отвечает игроку
     *    текстом причины (а не общей ошибкой) — story не имеет права трогать `AttackPlayerAction`
     *    (не в `## Files`), поэтому лок переиспользует уже работающий путь вместо нового.
     *
     * @param array<string,mixed>                                                                                       $attacker         персонаж-детектор (id/level/cell_number/created_at)
     * @param list<array{id:int,name:string,level:int,created_at:mixed,cell_number:mixed,distance:int,last_active_at:mixed}> $detectedPlayers все обнаруженные (без ограничения — кап применяется здесь)
     * @return array{text:string,keyboard:array{inline_keyboard:list<list<array<string,string>>>}}
     */
    public function renderDetectionMessage(
        array $attacker,
        array $detectedPlayers,
        int $maxListed,
        int $inactiveDays,
        bool $showInactiveSummary
    ): array {
        $maxListed    = max(1, $maxListed);
        $inactiveDays = max(0, $inactiveDays);
        $now          = time();

        $enriched = [];
        foreach ($detectedPlayers as $dp) {
            $lastActiveAt = $dp['last_active_at'] ?? null;
            $lastActiveTs = is_string($lastActiveAt) && $lastActiveAt !== '' ? strtotime($lastActiveAt) : false;
            $dp['last_active_ts'] = $lastActiveTs === false ? null : $lastActiveTs;
            $dp['is_abandoned']   = $dp['last_active_ts'] === null
                || ($now - $dp['last_active_ts']) > $inactiveDays * 86400;
            $enriched[] = $dp;
        }

        // Детерминированный порядок: недавно действовавшие впереди (null = никогда, в хвост),
        // затем по расстоянию, затем по id.
        usort($enriched, static function (array $a, array $b): int {
            $tsA = $a['last_active_ts'];
            $tsB = $b['last_active_ts'];
            if ($tsA !== $tsB) {
                if ($tsA === null) {
                    return 1;
                }
                if ($tsB === null) {
                    return -1;
                }
                return $tsB <=> $tsA;
            }
            if ($a['distance'] !== $b['distance']) {
                return $a['distance'] <=> $b['distance'];
            }
            return $a['id'] <=> $b['id'];
        });

        $total     = count($enriched);
        $candidate = array_slice($enriched, 0, $maxListed);

        $header = "🔍 <b>Обнаружение игроков!</b> 🔍\n\n";
        $footer = "\n\n<i>Вы можете предпринять дальнейшие действия.</i>";

        // Жёсткая граница: запас под footer + самую длинную реалистичную строку-остаток
        // ("И ещё 999999 поблизости..." — с большим запасом), чтобы итоговый текст
        // гарантированно оставался короче лимита Telegram (4096) независимо от $maxListed.
        $reserve      = mb_strlen($footer) + 160;
        $bodyBudget   = self::MAX_TEXT_CHARS - mb_strlen($header) - $reserve;
        $bodyLen      = 0;

        $lines        = [];
        $flatButtons  = [];
        $shown        = 0;

        foreach ($candidate as $neighbor) {
            $safeName     = $neighbor['name'] !== '' ? esc((string) $neighbor['name'], 'html') : ('№' . $neighbor['id']);
            $levelTag     = ' (ур. ' . (int) $neighbor['level'] . ')';
            $abandonedTag = $neighbor['is_abandoned'] ? ' — давно не в сети' : '';
            $line         = "Есть игрок <b>{$safeName}</b>{$levelTag} на расстоянии {$neighbor['distance']} ячеек от тебя{$abandonedTag}.";
            $lineLen      = mb_strlen($line) + 1; // + перевод строки

            if ($bodyLen + $lineLen > $bodyBudget) {
                break; // жёсткая граница — остаток уходит в overflow, не обрывая сообщение
            }

            $lines[]  = $line;
            $bodyLen += $lineLen;
            $shown++;

            $check = $this->restriction->checkPvPAllowed($attacker, $neighbor);
            if ($check['allowed']) {
                $flatButtons[] = ['text' => '⚔️ Атаковать', 'callback_data' => 'attackPlayer_' . $neighbor['id']];
            } else {
                $flatButtons[] = [
                    'text'          => '🔒 ' . $this->lockLabel((string) ($check['reason_code'] ?? '')),
                    'callback_data' => 'attackPlayer_' . $neighbor['id'],
                ];
            }

            if ($this->duelsEnabledAndOpen((int) $neighbor['id'])) {
                $flatButtons[] = ['text' => '🤺 Дуэль', 'callback_data' => 'duel_' . $neighbor['id']];
            }
        }

        $flatButtons[] = ['text' => '🏃 Бежать', 'callback_data' => 'runAway'];

        $overflow = $total - $shown;

        $text = $header . implode("\n", $lines);

        if ($overflow > 0 && $showInactiveSummary) {
            $text .= "\n\nИ ещё {$overflow} поблизости — список свёрнут, чтобы не упереться в лимит Telegram; загляни на карту, чтобы увидеть их точнее.";
        }

        $text .= $footer;

        return [
            'text'     => $text,
            'keyboard' => ['inline_keyboard' => ButtonPacker::pack($flatButtons)],
        ];
    }

    /** Запас под лимит Telegram (4096): жёсткая граница `renderDetectionMessage()`. */
    private const MAX_TEXT_CHARS = 3800;

    private function lockLabel(string $reasonCode): string
    {
        return match ($reasonCode) {
            'level'        => 'Уровень',
            'safe_zone'    => 'Южная зона',
            'account_age'  => 'Молодой аккаунт',
            default        => 'Недоступно',
        };
    }

    /**
     * Максимум `action_log.created_at` на персонажа среди переданных id — один групповой
     * запрос вместо N+1. `characters.last_update_time` не годится: пуста у всех строк на проде.
     *
     * @param list<int> $characterIds
     * @return array<int,string|null>
     */
    private function fetchLastActiveMap(array $characterIds): array
    {
        if ($characterIds === []) {
            return [];
        }

        $result = Database::connect()->table('action_log')
            ->select('character_id, MAX(created_at) AS last_active')
            ->whereIn('character_id', $characterIds)
            ->groupBy('character_id')
            ->get();
        $rows = $result === false ? [] : $result->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $cid = is_numeric($row['character_id'] ?? null) ? (int) $row['character_id'] : 0;
            if ($cid > 0) {
                $map[$cid] = $row['last_active'] ?? null;
            }
        }

        return $map;
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

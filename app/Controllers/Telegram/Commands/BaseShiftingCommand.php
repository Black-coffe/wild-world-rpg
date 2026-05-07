<?php

namespace App\Controllers\Telegram\Commands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\CallbackQuery;

use App\Models\TelegramUserModel;
use App\Models\CharacterModel;
use App\Models\MapModel;

use App\Services\Player\Relocation\ClosestCellFinder;
use App\Services\Player\Relocation\RelocationMessageFormatter;
use App\Services\Player\Relocation\RelocationTaskCreator;
use App\Services\Player\Relocation\RelocationValidator;

/**
 * v0.51.47 (decomp Step 1) — extracted 3 валідаційні helpers
 * (`checkPreconditions` + `isCellAvailableForRelocation` + `isCellExploredBy`)
 * у `App\Services\Player\Relocation\RelocationValidator` сервіс.
 *
 * Раніше обидва entry points (`execute()` + `handleCallback()`) дублювали
 * виклики цих helpers. Тепер shared service. Behavior preservation 1:1.
 *
 * Multi-step decomp BaseShiftingCommand 530 LOC → ~150 LOC orchestrator + 4 services.
 * Step 2 (planned): ClosestCellFinder, Step 3: TaskCreator, Step 4: MessageFormatter.
 */
class BaseShiftingCommand extends UserCommand
{
    protected $name = 'base_shifting'; // /base_shifting
    protected $description = 'Полноценный переезд базы на новые координаты (X=..., Y=...)';
    protected $usage = '/base_shifting X=123Y=456';
    protected $version = '1.0.0';

    private ?RelocationValidator $validator = null;
    private ?ClosestCellFinder $closestFinder = null;
    private ?RelocationTaskCreator $taskCreator = null;
    private ?RelocationMessageFormatter $formatter = null;

    /**
     * Lazy getter — уникаємо overriding Longman UserCommand constructor
     * (різні Longman versions можуть мати різні constructor signatures).
     */
    private function validator(): RelocationValidator
    {
        if ($this->validator === null) {
            $this->validator = new RelocationValidator();
        }
        return $this->validator;
    }

    private function closestFinder(): ClosestCellFinder
    {
        if ($this->closestFinder === null) {
            $this->closestFinder = new ClosestCellFinder($this->validator());
        }
        return $this->closestFinder;
    }

    private function taskCreator(): RelocationTaskCreator
    {
        if ($this->taskCreator === null) {
            $this->taskCreator = new RelocationTaskCreator();
        }
        return $this->taskCreator;
    }

    private function formatter(): RelocationMessageFormatter
    {
        if ($this->formatter === null) {
            $this->formatter = new RelocationMessageFormatter();
        }
        return $this->formatter;
    }

    /**
     * Helper: send message via Request::sendMessage з payload-array.
     * Зливає chat_id з content payload.
     *
     * @param array<string,mixed> $payload
     */
    private function send(int|string $chatId, array $payload): ServerResponse
    {
        return Request::sendMessage(array_merge(['chat_id' => $chatId], $payload));
    }

    /**
     * Выполняется, когда игрок вводит: /base_shifting X=...Y=...
     */
    public function execute(): ServerResponse
    {
        $message   = $this->getMessage();
        $chatId    = $message->getChat()->getId();
        $from      = $message->getFrom();
        $userText  = $message->getText(true) ?? '';

        // 1) Парсим "X=123Y=456"
        if (!preg_match('/X=(\d+)Y=(\d+)/', $userText, $m)) {
            return $this->send($chatId, $this->formatter()->badFormat());
        }
        $x = (int)$m[1];
        $y = (int)$m[2];

        // 2) Ищем пользователя/персонажа
        $telegramId = $from->getId();
        $userModel  = new TelegramUserModel();
        $userRow    = $userModel->where('telegram_id', $telegramId)->first();
        if (!$userRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Ошибка: телеграм-профиль не найден.",
            ]);
        }
        $charModel = new CharacterModel();
        $character = $charModel->where('telegram_user_id', $userRow['id'])->first();
        if (!$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "У вас нет персонажа! Сначала создайте персонажа.",
            ]);
        }

        // 3) Проверяем диапазон
        if ($x<1 || $x>1000 || $y<1 || $y>1000) {
            return $this->send($chatId, $this->formatter()->coordsOutOfRange($x, $y));
        }

        // 4) Проверяем кулдаун, активную задачу и т.д. (v0.51.47 — RelocationValidator)
        $canProceed = $this->validator()->checkPreconditions($character);
        if (!$canProceed['ok']) {
            // Если там текст — возвращаем
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $canProceed['error'],
                'parse_mode' => 'Markdown',
            ]);
        }
        $frTaskId = $canProceed['fullRelocTaskId']; // ID задачи FullRelocation

        // 5) Проверяем карту
        $mapModel = new MapModel();
        $mapRow   = $mapModel->where('coordinate_x',$x)->where('coordinate_y',$y)->first();
        if (!$mapRow) {
            return $this->send($chatId, $this->formatter()->cellNotFound($x, $y));
        }
        $mapCellId = $mapRow['id'];

        // 6) Проверяем занятость ячейки / резерв (v0.51.47 — RelocationValidator)
        $reason = '';
        if (!$this->validator()->isCellAvailableForRelocation($character['id'], $mapCellId, $reason)) {
            $closestInfo = $this->closestFinder()->findClosest((int) $character['id'], $x, $y);
            if (!$closestInfo) {
                return $this->send($chatId, $this->formatter()->cellUnavailableNoAlt($x, $y, $reason));
            }
            $altBiomeName = $this->getBiomeNameByMapId($closestInfo['map_id']);
            return $this->send($chatId, $this->formatter()->cellUnavailableWithAlt(
                $x, $y, $reason, $closestInfo['x'], $closestInfo['y'], $altBiomeName
            ));
        }

        // 7) Проверяем, изучил ли игрок данную точку (v0.51.47 — RelocationValidator)
        if (!$this->validator()->isCellExploredBy($character['id'], $mapCellId)) {
            $closestInfo = $this->closestFinder()->findClosest((int) $character['id'], $x, $y);
            if (!$closestInfo) {
                return $this->send($chatId, $this->formatter()->cellNotExploredNoAlt($x, $y));
            }
            $altBiomeName = $this->getBiomeNameByMapId($closestInfo['map_id']);
            return $this->send($chatId, $this->formatter()->cellNotExploredWithAlt(
                $x, $y, $closestInfo['x'], $closestInfo['y'], $altBiomeName
            ));
        }

        // 8) Всё ок, ячейка доступна и изучена => предлагаем подтверждение
        $biomeName = $this->getBiomeNameByMapId($mapCellId);
        return $this->send($chatId, $this->formatter()->confirmPrompt($x, $y, $biomeName, $mapCellId));
    }

    /**
     * Обработчик колбэков (если используете Longman\TelegramBot\Commands\SystemCommands\CallbackqueryCommand)
     * Можно вызывать этот метод вручную из CallbackqueryCommand
     */
    public function handleCallback(CallbackQuery $callbackQuery): ServerResponse
    {
        $callbackData = $callbackQuery->getData();
        $chatId       = $callbackQuery->getMessage()->getChat()->getId();

        // Пример: "StartRelocationConfirm_123_456_9999"
        if (strpos($callbackData, 'StartRelocationConfirm_') === 0) {
            Request::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
            ]);
            $parts = explode('_', $callbackData); // [StartRelocationConfirm, X, Y, mapCellId]
            if (count($parts) < 4) {
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => "Ошибка: неправильные данные колбэка!",
                ]);
            }
            $x = (int)$parts[1];
            $y = (int)$parts[2];
            $mapCellId = (int)$parts[3];

            // Повторно проверим, что сейчас всё ещё ок
            // (на случай, если что-то поменялось)
            [$user,$character] = $this->getUserAndCharacterByCallback($callbackQuery);
            if (!$user || !$character) {
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => "Ошибка: пользователь/персонаж не найден.",
                ]);
            }

            // Снова проверим кулдаун и т.д. (v0.51.47 — RelocationValidator)
            $canProceed = $this->validator()->checkPreconditions($character);
            if (!$canProceed['ok']) {
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => $canProceed['error'],
                    'parse_mode' => 'Markdown',
                ]);
            }
            $frTaskId = $canProceed['fullRelocTaskId'];

            // Проверим, не занял ли кто-то эту ячейку за то время (v0.51.47 — RelocationValidator)
            $reason = '';
            if (!$this->validator()->isCellAvailableForRelocation($character['id'], $mapCellId, $reason)) {
                return $this->send($chatId, $this->formatter()->confirmRaceCondition($reason));
            }
            // Проверим, не изучал ли. (v0.51.47 — RelocationValidator)
            if (!$this->validator()->isCellExploredBy($character['id'], $mapCellId)) {
                return $this->send($chatId, $this->formatter()->confirmNotExplored());
            }

            // Всё нормально => создаём задачу (v0.51.49 — RelocationTaskCreator)
            $this->taskCreator()->createTask(
                (int) $character['id'],
                (int) $user['id'],
                $frTaskId,
                $mapCellId,
                $x,
                $y
            );

            return $this->send($chatId, $this->formatter()->taskStarted($x, $y));
        }

        return Request::emptyResponse();
    }

    // ----------------------------------------------
    // Вспомогательные методы
    // ----------------------------------------------

    // v0.51.47 — extract Step 1: 3 helpers переїхали у RelocationValidator service.
    // Было: checkPreconditions / isCellAvailableForRelocation / isCellExploredBy.
    // Тепер: $this->validator()->checkPreconditions(...) etc.

    // v0.51.48 — extract Step 2: findClosestExploredFreeCell → ClosestCellFinder.
    // v0.51.49 — extract Step 3: startRelocationTask → RelocationTaskCreator.
    // SRP separation: service writes character_task row, command handles UI/Telegram send.

    /**
     * Получаем имя биома (рус) по map_id -> map.biome_id -> biome.name
     */
    private function getBiomeNameByMapId(int $mapId): string
    {
        $db = \Config\Database::connect();
        $query = $db->table('map')
            ->select('biomes.name AS biome_name')
            ->join('biomes','biomes.id=map.biome_id','left')
            ->where('map.id',$mapId)
            ->get();
        if ($query === false) {
            return "Неизвестный биом";
        }
        $row = $query->getRowArray();
        if ($row && !empty($row['biome_name'])) {
            return $row['biome_name'];
        }
        return "Неизвестный биом";
    }

    /**
     * Получаем [user,character] из callbackQuery (если надо)
     */
    private function getUserAndCharacterByCallback(CallbackQuery $callbackQuery): array
    {
        $tid = $callbackQuery->getFrom()->getId();
        $userModel = new TelegramUserModel();
        $urow = $userModel->where('telegram_id',$tid)->first();
        if (!$urow) return [null,null];

        $charModel = new CharacterModel();
        $crow = $charModel->where('telegram_user_id',$urow['id'])->first();
        if (!$crow) return [null,null];

        return [$urow,$crow];
    }
}

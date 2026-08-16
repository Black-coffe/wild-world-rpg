<?php

namespace App\Controllers\Telegram\Commands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\CallbackQuery;

use App\Models\TelegramUserModel;
use App\Models\CharacterModel;
use App\Models\MapModel;

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
     * v0.51.51 (Step 5 polish) — DRY user/character lookup.
     * Used both у execute() (Telegram command flow) і handleCallback()
     * (callback flow) — раніше дублювалося.
     *
     * Return shape: 2-tuple [user, character]. Кожен може бути null
     * якщо lookup не знайшов. CI4 Model first() returns array або object
     * залежно від returnType — widened як array|object|null.
     *
     * @return array{0: array<array-key, mixed>|null, 1: \App\Entities\CharacterEntity|null}
     */
    private function lookupUserAndCharacter(int $telegramId): array
    {
        $userRow = (new TelegramUserModel())->where('telegram_id', $telegramId)->first();
        if (! is_array($userRow)) {
            return [null, null];
        }
        $rawUserId = $userRow['id'] ?? null;
        $userId    = is_numeric($rawUserId) ? (int) $rawUserId : 0;

        $character = (new CharacterModel())->where('telegram_user_id', $userId)->first();

        return [$userRow, $character instanceof \App\Entities\CharacterEntity ? $character : null];
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

        // Пайплайн переезда живёт в RelocationRequestService — тот же, что у кнопки
        // «🚚 Полноценный переезд» (forceReply). Второй копии валидаций нет.
        $coords = \App\Services\Player\Relocation\RelocationRequestService::parseCoords($userText);
        if ($coords === null) {
            return $this->send($chatId, $this->formatter()->badFormat());
        }

        return (new \App\Services\Player\Relocation\RelocationRequestService())
            ->handleCoords((int) $chatId, (int) $from->getId(), $coords[0], $coords[1]);
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

            // Повторно проверим, что сейчас всё ещё ок (v0.51.51 — DRY lookup)
            [$user, $character] = $this->lookupUserAndCharacter((int) $callbackQuery->getFrom()->getId());
            if (!$user || !$character) {
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => "Ошибка: пользователь/персонаж не найден.",
                ]);
            }

            // Снова проверим кулдаун и т.д. (v0.51.47 — RelocationValidator)
            $canProceed = $this->validator()->checkPreconditions($character);
            if ($canProceed['ok'] !== true) {
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => $canProceed['error'] ?? 'Сейчас переехать нельзя.',
                    'parse_mode' => 'Markdown',
                ]);
            }
            $frTaskId = $canProceed['fullRelocTaskId'] ?? 0;

            $rawCharId = $character['id'] ?? null;
            $charIdNum = is_numeric($rawCharId) ? (int) $rawCharId : 0;

            // Проверим, не занял ли кто-то эту ячейку за то время (v0.51.47 — RelocationValidator)
            $reason = '';
            if (!$this->validator()->isCellAvailableForRelocation($charIdNum, $mapCellId, $reason)) {
                return $this->send($chatId, $this->formatter()->confirmRaceCondition($reason));
            }
            // Проверим, не изучал ли. (v0.51.47 — RelocationValidator)
            if (!$this->validator()->isCellExploredBy($charIdNum, $mapCellId)) {
                return $this->send($chatId, $this->formatter()->confirmNotExplored());
            }

            // ADR-102: исходная база, которую переносим (мультибэйс) — та, на которой
            // стоит игрок (fallback — единственная база). null → просим встать на базу.
            $curCellRaw = $character['cell_number'] ?? null;
            $curCell    = is_numeric($curCellRaw) ? (int) $curCellRaw : 0;
            $sourceCell = (new \App\Models\ClaimedCellModel())->resolveTargetBaseCell($charIdNum, $curCell);
            if ($sourceCell === null) {
                return Request::sendMessage([
                    'chat_id'    => $chatId,
                    'text'       => "У тебя несколько баз. Встань на ту, которую хочешь перенести, затем повтори переезд.",
                    'parse_mode' => 'Markdown',
                ]);
            }

            // Всё нормально => создаём задачу (v0.51.49 — RelocationTaskCreator)
            $rawUserId = $user['id'] ?? null;
            $this->taskCreator()->createTask(
                $charIdNum,
                is_numeric($rawUserId) ? (int) $rawUserId : 0,
                $frTaskId,
                $mapCellId,
                $x,
                $y,
                $sourceCell
            );

            return $this->send($chatId, $this->formatter()->taskStarted($x, $y));
        }

        return Request::emptyResponse();
    }

}

<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Entities\CharacterEntity;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\TelegramUserModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Telegram\Request;
use App\Services\World\MoveService;
use App\Services\World\MoveSurfaceService;
use App\Services\World\TextMapService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * Шаг персонажа в одно из 8 направлений по колбэку `move_dir_{direction}`.
 *
 * W2.N2-02 (ADR-190): механика шага — {@see MoveService::step()} (общая с вебом `/play`); этот
 * handler только рендерит исход в Telegram, теми же сообщениями, что и раньше:
 *
 * 1) отказ — alert у края мира, иначе отдельное сообщение с прежним текстом;
 * 2) успех — правка сообщения, на котором нажали (затем `last_map_message_id`), или новое
 *    сообщение с картой 12×12; «хвост» клетки из событий шага — кнопки под картой;
 * 3) хуки с чатом ({@see MoveService::afterStep()}) — сразу после экрана шага; рана — отдельным
 *    сообщением на пути «новое сообщение» (как и прежде: на пути правки её сообщение не шлётся).
 */
class MoveCharacterToDirectionAction
{
    private CharacterModel $characterModel;
    private MapModel $mapModel;
    private TelegramUserModel $telegramUserModel;
    private MoveService $move;

    public function __construct(private CallbackQuery $callbackQuery)
    {
        $this->characterModel    = new CharacterModel();
        $this->mapModel          = new MapModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->move              = new MoveService($this->characterModel, $this->mapModel);
    }

    public function handle(): ServerResponse
    {
        $chatId         = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        // E2: «часики» снимаем осмысленно ниже — пустым ответом, либо alert'ом на краю мира
        // (Telegram позволяет ответить на callback_query лишь один раз).
        $direction = str_replace('move_dir_', '', (string) $this->callbackQuery->getData());
        if (! MoveService::isDirection($direction)) {
            $this->dismissSpinner();

            return Request::sendMessage(['chat_id' => $chatId, 'text' => "Неизвестное направление: {$direction}."]);
        }

        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (! is_array($user)) {
            $this->dismissSpinner();

            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден.']);
        }
        $userId      = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;
        $character   = $this->characterModel->where('telegram_user_id', $userId)->first();
        $characterId = $character instanceof CharacterEntity ? (int) $character->id : 0;

        $outcome = $this->move->step($characterId, $direction);
        if (! $outcome['ok']) {
            return $this->refusal($chatId, $outcome['code'], (string) $outcome['message']);
        }

        // Не край — снимаем «часики».
        $this->dismissSpinner();

        $updatedText = $this->buildUpdatedMapText($characterId, new TextMapService())
            . "\nВы двинулись на: *" . MoveService::DIRECTION_RU[$direction] . "*\n";
        $keyboard    = ['inline_keyboard' => $this->keyboard($outcome['events'])];

        // Правим ИМЕННО то сообщение, на котором нажали (ADR-018 navTarget), затем last_map_message_id.
        $clickedMsgId = $this->callbackQuery->getMessage()->getMessageId();
        $lastMapMsgId = is_numeric($user['last_map_message_id'] ?? null) ? (int) $user['last_map_message_id'] : null;
        $editTargets  = array_values(array_unique(array_filter([$clickedMsgId, $lastMapMsgId])));

        foreach ($editTargets as $targetMsgId) {
            $editResponse = Request::editMessageText([
                'chat_id'      => $chatId,
                'message_id'   => $targetMsgId,
                'text'         => $updatedText,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);

            if ($editResponse->isOk()) {
                if ($targetMsgId !== $lastMapMsgId) {
                    $this->telegramUserModel->update($userId, ['last_map_message_id' => $targetMsgId]);
                }
                $this->move->afterStep($outcome, $chatId);

                return $editResponse;
            }
        }

        // Правка не удалась (или нечего править) — новое сообщение.
        $newMsgResponse = Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $updatedText,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
        if ($newMsgResponse->isOk()) {
            $result = $newMsgResponse->getResult();
            if (is_object($result) && method_exists($result, 'getMessageId')) {
                $this->telegramUserModel->update($userId, ['last_map_message_id' => $result->getMessageId()]);
            }
        }

        // Прежний путь «новое сообщение» не звал приманку cold-open — паритет.
        $this->move->afterStep($outcome, $chatId, false);

        // Рана — отдельным сообщением ПОСЛЕ экрана перехода (media-off, текст самодостаточен).
        foreach ($outcome['events'] as $event) {
            if ($event['type'] === MoveService::EVENT_DEBUFF) {
                Request::sendMessage([
                    'chat_id'      => $chatId,
                    'text'         => $event['text'],
                    'parse_mode'   => 'Markdown',
                    'reply_markup' => json_encode(['inline_keyboard' => [$event['buttons']]]),
                ]);
            }
        }

        return $newMsgResponse;
    }

    /**
     * Отказ шага — прежние ответы: край мира — alert на кнопке, прочее — отдельное сообщение.
     */
    private function refusal(int $chatId, string $code, string $message): ServerResponse
    {
        if ($code === MoveService::EDGE) {
            Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
                'text'              => mb_substr($message, 0, 200),
                'show_alert'        => true,
            ]);

            return Request::emptyResponse();
        }

        $this->dismissSpinner();
        $payload = ['chat_id' => $chatId, 'text' => $message];
        if (in_array($code, MoveService::MARKDOWN_CODES, true)) {
            $payload['parse_mode'] = 'Markdown';
        }
        $response = Request::sendMessage($payload);

        return $code === MoveService::RELOCATION ? Request::emptyResponse() : $response;
    }

    /**
     * Роза из единого источника {@see MoveSurfaceService::compassRows()}, затем «хвост»: нав-ряд
     * (при world_hub ON — Поход · Легенда · Обзор одним рядом; при OFF «Поход» идёт первой кнопкой
     * хвоста) и кнопки событий клетки, по 2 в ряд.
     *
     * @param list<array{type: string, text: string, buttons: list<array{text: string, callback_data: string}>}> $events
     *
     * @return array<int, array<int, array<string, string>>>
     */
    private function keyboard(array $events): array
    {
        $rows = (new MoveSurfaceService())->compassRows();

        $tail = [];
        if ($this->worldHubEnabled()) {
            $rows[] = [
                ['text' => '🗺️ Поход', 'callback_data' => 'march'],       // ADR-019
                ['text' => '❓ Легенда', 'callback_data' => 'mapLegend'],
                ['text' => '🗺 Обзор', 'callback_data' => 'mapOverview'],
            ];
        } else {
            $tail[] = ['text' => '🗺️ Поход', 'callback_data' => 'march']; // ADR-019
        }
        foreach ($events as $event) {
            if (in_array($event['type'], MoveService::TAIL_TYPES, true)) {
                array_push($tail, ...$event['buttons']);
            }
        }
        foreach (array_chunk($tail, 2) as $chunk) {
            $rows[] = $chunk;
        }

        return $rows;
    }

    /** ADR-150 Слайс 1 (navigation.world_hub.enabled). */
    private function worldHubEnabled(): bool
    {
        $raw = (new GameSettingsService())->get('navigation.world_hub.enabled', false);

        return is_bool($raw) ? $raw : (is_numeric($raw) && (int) $raw === 1);
    }

    /**
     * transport-05 — стоимость одиночного шага; ядро — {@see MoveService::computeStepCost()}.
     *
     * @param array{health_cost_base: float, tired_cost_base: float, danger_health_surcharge: float} $settings
     * @param array<string, mixed>                                                                    $vehicleProfile
     *
     * @return array{health: float, tired: float}
     */
    public static function computeStepCost(array $settings, bool $dangerBiome, array $vehicleProfile, float $earlyFactor): array
    {
        return MoveService::computeStepCost($settings, $dangerBiome, $vehicleProfile, $earlyFactor);
    }

    /**
     * E2 — направления, по которым из клетки есть ход; ядро — {@see MoveService::availableDirections()}.
     *
     * @return list<string>
     */
    public static function availableDirections(int $curX, int $curY): array
    {
        return MoveService::availableDirections($curX, $curY);
    }

    /** Снять «часики» с нажатой кнопки (пустой ответ на callback_query). */
    private function dismissSpinner(): void
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
    }

    /**
     * 12×12 карта, легенда, расстояние до базы и статы уже ОБНОВЛЁННОГО персонажа.
     */
    protected function buildUpdatedMapText(int $characterId, TextMapService $textMapService): string
    {
        $character = $this->characterModel->find($characterId);
        if (! $character instanceof CharacterEntity) {
            return 'Ошибка: персонаж не найден!';
        }

        $text = "Куда пойдём? Выберите направление:\n\n";

        // ADR-150 Слайс 1 — при world_hub ON легенда за кнопкой «❓ Легенда», при OFF — в теле.
        if (! $this->worldHubEnabled()) {
            $text .= $textMapService->getLegend() . "\n";
        }

        $distanceLine = $textMapService->getDistanceLine($character);
        if ($distanceLine) {
            $text .= $distanceLine . "\n";
        }

        $hp    = (float) $character->health;
        $tired = (float) ($character->tired ?? 0);
        $text .= "❤️ Здоровье: {$hp}\n"
            . "💤 Усталость: {$tired}\n\n";

        $text .= $textMapService->buildMapOnly($character) . "\n";

        $mapRow = $this->mapModel->where('cell_number', $character->cell_number)->first();
        if (is_array($mapRow) && is_scalar($mapRow['coordinate_x'] ?? null) && is_scalar($mapRow['coordinate_y'] ?? null)) {
            $text .= 'Игрок по центру (X=' . $mapRow['coordinate_x'] . ', Y=' . $mapRow['coordinate_y'] . ")\n";
        }

        return $text;
    }
}

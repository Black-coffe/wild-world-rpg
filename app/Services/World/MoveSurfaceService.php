<?php

declare(strict_types=1);

namespace App\Services\World;

use App\Entities\CharacterEntity;
use App\Models\MapModel;
use App\Models\TelegramUserModel;
use App\Services\GameSettings\GameSettingsService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-150 Слайс 1 — единый рендер «поверхности ходьбы» (компас-розетка 3×3 + карта
 * 12×12 текстом). Вынесен из {@see \App\Controllers\Telegram\Commands\Actions\MoveCharacterAction},
 * чтобы ОДИН и тот же экран открывался из трёх мест без дрейфа:
 *  - callback `move` (кнопка «🧭 Двигаться» в хабе «Действия» и на других экранах);
 *  - нижняя кнопка «🌍 Мир» / текст (при killswitch navigation.world_hub.enabled);
 *  - slash `/go`.
 *
 * Поверхность — ТЕКСТ-сообщение (media-off-нейтрально; редактируется на каждый шаг
 * через move_dir_*). При world_hub ON добавляется кнопка «🗺 Обзор» → фото карты мира
 * (демотированный бывший тупик-экран «Карта»). При OFF кнопки нет → рендер byte-identical
 * прежнему MoveCharacterAction.
 */
class MoveSurfaceService
{
    private MapModel $mapModel;
    private TelegramUserModel $telegramUserModel;

    public function __construct(?MapModel $mapModel = null, ?TelegramUserModel $telegramUserModel = null)
    {
        $this->mapModel          = $mapModel ?? new MapModel();
        $this->telegramUserModel = $telegramUserModel ?? new TelegramUserModel();
    }

    /**
     * Отправляет НОВОЕ текст-сообщение с картой 12×12 + компас-розеткой направлений.
     * Сохраняет message_id в telegram_users.last_map_message_id — последующие
     * move_dir_* редактируют это же сообщение (edit-in-place).
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function show(int $chatId, array|CharacterEntity $character): ServerResponse
    {
        // 1) Текст: шапка + легенда + расстояние до базы + HP/усталость + карта 12×12 + координаты
        $textMapService = new TextMapService();
        $finalText      = $this->buildMapText($character, $textMapService);

        // S7 (ADR-145): тизер «живого острова» в подвале карты + кнопка входа на пульс.
        // Гейт killswitch social.presence.enabled → OFF = byte-identical.
        $island        = new IslandPulseService();
        $islandEnabled = $island->enabled();
        if ($islandEnabled) {
            $teaser = $island->teaserLine($island->snapshot());
            if ($teaser !== null) {
                $finalText .= "\n" . $teaser . "\n";
            }
        }

        // 2) Компас-розетка (8 направлений + центр = хаб действий) + Поход
        $directionsKeyboard = [
            [
                ['text' => '↖️ Сев-Запад', 'callback_data' => 'move_dir_northwest'],
                ['text' => '⬆️ Север',     'callback_data' => 'move_dir_north'],
                ['text' => '↗️ Сев-Восток','callback_data' => 'move_dir_northeast'],
            ],
            [
                ['text' => '⬅️ Запад', 'callback_data' => 'move_dir_west'],
                ['text' => '🏕',       'callback_data' => 'Base'],
                ['text' => '🧑‍🌾 🛠️','callback_data' => 'characterActions'],
                ['text' => '➡️ Восток','callback_data' => 'move_dir_east'],
            ],
            [
                ['text' => '↙️ Юго-Запад','callback_data' => 'move_dir_southwest'],
                ['text' => '⬇️ Юг',      'callback_data' => 'move_dir_south'],
                ['text' => '↘️ Юго-Восток','callback_data' => 'move_dir_southeast'],
            ],
            [
                ['text' => '🗺️ Поход', 'callback_data' => 'march'], // ADR-019
            ],
        ];

        // S7 (ADR-145): кнопка «🌍 Остров живёт» — только при killswitch ON (иначе byte-identical).
        if ($islandEnabled) {
            $directionsKeyboard[] = [
                ['text' => '🌍 Остров живёт', 'callback_data' => 'island'],
            ];
        }

        // ADR-150 Слайс 1: «🗺 Обзор» (фото карты мира) — только при world_hub ON.
        // При OFF кнопки нет → рендер идентичен прежнему MoveCharacterAction.
        if ($this->worldHubEnabled()) {
            $directionsKeyboard[] = [
                ['text' => '🗺 Обзор', 'callback_data' => 'mapOverview'],
            ];
        }

        $keyboard = ['inline_keyboard' => $directionsKeyboard];

        // 3) Новое сообщение (первый показ); move_dir_* дальше будет его редактировать.
        $response = Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $finalText,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);

        // 4) Сохраняем message_id для последующего edit-in-place.
        if ($response->isOk()) {
            $result = $response->getResult();
            if (is_object($result) && method_exists($result, 'getMessageId')) {
                $messageId = $result->getMessageId();
                $tuRaw     = $character['telegram_user_id'] ?? 0;
                $tuId      = is_numeric($tuRaw) ? (int) $tuRaw : 0;
                if ($tuId > 0) {
                    $this->telegramUserModel->update($tuId, [
                        'last_map_message_id' => $messageId,
                    ]);
                }
            }
        }

        // ADR-150 Слайс 1: помечаем, что игрок открыл ЖИВОЙ компас ходьбы — это гейт
        // подсказки FIRST_MOVE (показываем её только тем, кто компас ещё не находил).
        // Defensive: маркер не должен ронять показ карты.
        $cidRaw = $character['id'] ?? 0;
        $cid    = is_numeric($cidRaw) ? (int) $cidRaw : 0;
        if ($cid > 0) {
            try {
                (new \App\Services\Onboarding\OnboardingHintService())->markLiveMoveUsed($cid);
            } catch (\Throwable $e) {
                log_message('error', 'markLiveMoveUsed failed: ' . $e->getMessage());
            }
        }

        return $response;
    }

    /**
     * Текст: 12×12 карта + легенда + здоровье и координаты (перенос из MoveCharacterAction).
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    protected function buildMapText(array|CharacterEntity $character, TextMapService $textMapService): string
    {
        $text = "Куда пойдём? Выберите направление с клавиатуры ниже:\n\n";

        $legend = $textMapService->getLegend();
        $text  .= $legend . "\n";

        $distanceLine = $textMapService->getDistanceLine($character);
        if ($distanceLine) {
            $text .= $distanceLine . "\n";
        }

        $hpRaw    = $character['health'] ?? 0;
        $tiredRaw = $character['tired'] ?? 0;
        $hp       = is_numeric($hpRaw) ? (float) $hpRaw : 0.0;
        $tired    = is_numeric($tiredRaw) ? (float) $tiredRaw : 0.0;
        $text .= "❤️ Здоровье: {$hp}\n"
            . "💤 Усталость: {$tired}\n\n";

        $mapOnly = $textMapService->buildMapOnly($character);
        $text   .= $mapOnly . "\n";

        $mapRow = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if (is_array($mapRow)) {
            $pxRaw = $mapRow['coordinate_x'] ?? null;
            $pyRaw = $mapRow['coordinate_y'] ?? null;
            $px    = is_numeric($pxRaw) ? (int) $pxRaw : 0;
            $py    = is_numeric($pyRaw) ? (int) $pyRaw : 0;
            $text .= "Игрок по центру (X={$px}, Y={$py})\n";
        }

        return $text;
    }

    /** Killswitch ADR-150 Слайс 1 (navigation.world_hub.enabled). Overridable seam для тестов. */
    protected function worldHubEnabled(): bool
    {
        $raw = (new GameSettingsService())->get('navigation.world_hub.enabled', false);
        if (is_bool($raw)) {
            return $raw;
        }

        return is_numeric($raw) ? (int) $raw === 1 : false;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\World;

use App\Entities\CharacterEntity;
use App\Models\MapModel;
use App\Models\TelegramUserModel;
use App\Services\Logging\ActionOrigin;
use App\Services\Telegram\BotMenuService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

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

        // 2) Компас-розетка + нижние кнопки (Поход/Обзор/Легенда) — общий билдер
        //    (тот же используется в renderCompassInPlace для возврата из легенды).
        $keyboard = ['inline_keyboard' => $this->buildDirectionsKeyboard($islandEnabled, $character)];

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
     * W2.N2-01: рисуется из модели {@see LiveMapService} — той же, из которой веб рисует сетку.
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    protected function buildMapText(array|CharacterEntity $character, TextMapService $textMapService): string
    {
        return $this->renderMapText($textMapService->liveMap()->fromCharacter($character, false), $textMapService);
    }

    /**
     * Текст экрана из модели карты.
     *
     * @param array{error:?string, center:?array{x:int, y:int, biome:?int}, cells:list<list<array{marker:string}>>, distance_to_base:?array{distance:int, arrow:string}, stats:array{health:float, tired:float}} $model
     */
    protected function renderMapText(array $model, TextMapService $textMapService): string
    {
        $text = "Куда пойдём? Выберите направление с клавиатуры ниже:

";

        // ADR-150 Слайс 1 — при world_hub ON легенда съедала ~80% сообщения, поэтому
        // прячем её за кнопку «❓ Легенда» (тумблер ⇄ карта). При OFF — оставляем в теле
        // (рендер byte-identical прежнему MoveCharacterAction).
        if (! $this->worldHubEnabled()) {
            $text .= $textMapService->getLegend() . "
";
        }

        $distanceLine = TextMapService::distanceText($model['distance_to_base']);
        if ($distanceLine) {
            $text .= $distanceLine . "
";
        }

        $hp    = $model['stats']['health'];
        $tired = $model['stats']['tired'];
        $text .= "❤️ Здоровье: {$hp}
"
            . "💤 Усталость: {$tired}

";

        $text .= TextMapService::gridText($model) . "
";

        if ($model['center'] !== null) {
            $text .= "Игрок по центру (X={$model['center']['x']}, Y={$model['center']['y']})
";
        }

        return $text;
    }

    /**
     * Компас-розетка 3×3 + нижний нав-ряд. При world_hub ON — три кнопки в один ряд
     * [🗺️ Поход, ❓ Легенда, 🗺 Обзор] (ADR-150 Слайс 1). При OFF — только [🗺️ Поход]
     * → рендер byte-identical прежнему MoveCharacterAction.
     *
     * @param array<string, mixed>|CharacterEntity $character
     *
     * @return array<int, array<int, array<string, string>>>
     */
    protected function buildDirectionsKeyboard(bool $islandEnabled, array|CharacterEntity $character): array
    {
        $rows = $this->compassRows();

        // W2.N2-01: кнопки под розой — действия модели карты (те же гейты, что и раньше):
        // нав-ряд [Поход · Легенда · Обзор] (при world_hub OFF — только [Поход]) и ряд
        // «состояние мира» [Остров живёт · События · Дроны].
        $actions = (new LiveMapService($this->mapModel))->actions(
            $character,
            $islandEnabled,
            $this->worldHubEnabled(),
            $this->finalGridEnabled(),
            $this->gatherOnCompassEnabled()
        );
        $nav   = [];
        $world = [];
        foreach ($actions as $action) {
            $button = ['text' => $action['label'], 'callback_data' => $action['callback']];
            if ($action['group'] === LiveMapService::GROUP_NAV) {
                $nav[] = $button;
            } elseif ($action['group'] === LiveMapService::GROUP_WORLD) {
                $world[] = $button;
            }
        }
        $rows[] = $nav;

        // Ряд «состояние мира» пакуется по 3 кнопки (унаследованное поведение самого ряда,
        // не новая гарантия): при обоих killswitch'ах мира (island, final_grid) выключенных
        // и наличии дрона у чара ряд выродится в одну кнопку «🚁 Дрон» — на проде оба
        // killswitch'а сейчас ON, поэтому сегодня это недостижимо, но код такую конфигурацию
        // не запрещает.
        foreach (array_chunk($world, 3) as $chunk) {
            $rows[] = $chunk;
        }

        return $rows;
    }

    /**
     * Компас-розетка направлений + (при killswitch ON) ряд действий на текущей клетке.
     * ЕДИНЫЙ источник для ОБЕИХ поверхностей ходьбы: первого рендера (этот сервис) и
     * рендера каждого шага ({@see \App\Controllers\Telegram\Commands\Actions\MoveCharacterToDirectionAction}),
     * который раньше держал собственную копию тех же трёх рядов — классический близнец,
     * расходящийся при любой правке (memory feedback_twin_hotfix_grep).
     *
     * Слайс «Второй шаг» (2026-07-24, живая когорта Хабра на новом холодном старте):
     * фикс старта поднял долю дошедших до первого шага 44% → 79%, и тем самым обнажил
     * следующую стену — ДОБЫЧУ находил 1 новичок из 14 (79 шагов по карте против 3 тапов
     * добычи). Причина не в балансе: добыча — ядро игрового цикла — не имела на пути
     * новичка НИ ОДНОЙ подписанной словом двери. Единственный вход вёл через безымянную
     * `🧑‍🌾 🛠️` в ряду направлений, и нашедший её единственный игрок когорты оказался
     * единственным добывавшим. Нижнее меню входа в добычу тоже не содержит (ADR-150:
     * `[🌍 Мир · 🧑 Я · 🔨 Крафт]/[🏠 База · 📋 Дела · ⚙️ Ещё]`).
     *
     * При ON ряд направлений становится честной 3×3-розеткой (в центре — 🏕 база), а под
     * ней встаёт ряд из двух ПОДПИСАННЫХ дверей: `[⛏️ Добыть ресурсы] [🧑‍🌾 Действия 🛠️]`.
     * Хаб при этом возвращает себе слово (в ряду из двух кнопок ширины хватает — именно
     * теснота ряда из четырёх кнопок и съела подпись). Метки — из
     * {@see \App\Services\Telegram\BotMenuService::actionLabel()}, не литералами.
     * При OFF — рендер byte-identical прежнему.
     *
     * @return array<int, array<int, array<string, string>>>
     */
    public function compassRows(): array
    {
        // Подписи и коды направлений — из модели карты (одна роза для бота и веба).
        $dir = static fn (string $code): array => [
            'text'          => LiveMapService::DIRECTIONS[$code][2],
            'callback_data' => 'move_dir_' . $code,
        ];
        $north = [$dir('northwest'), $dir('north'), $dir('northeast')];
        $south = [$dir('southwest'), $dir('south'), $dir('southeast')];

        if (! $this->gatherOnCompassEnabled()) {
            return [
                $north,
                [
                    $dir('west'),
                    ['text' => '🏠 База',       'callback_data' => 'Base'],
                    ['text' => BotMenuService::actionLabel('actionsHubCompact'), 'callback_data' => 'characterActions'],
                    $dir('east'),
                ],
                $south,
            ];
        }

        return [
            $north,
            [
                $dir('west'),
                ['text' => '🏠 База',       'callback_data' => 'Base'],
                $dir('east'),
            ],
            $south,
            [
                // ADR-168 — метка источника: ровно этот вход мерил слайс «второй шаг», и до
                // метки он был неотличим от входа из хаба действий. При выключенном
                // killswitch tag() возвращает строку без изменений (byte-identical).
                ['text' => BotMenuService::actionLabel('gather'),     'callback_data' => ActionOrigin::tag('gather', ActionOrigin::FROM_COMPASS)],
                ['text' => BotMenuService::actionLabel('actionsHub'), 'callback_data' => 'characterActions'],
            ],
        ];
    }

    /** Killswitch слайса «Второй шаг». Overridable seam для тестов. */
    protected function gatherOnCompassEnabled(): bool
    {
        return BotMenuService::gatherOnCompassEnabled();
    }

    /**
     * ADR-150 Слайс 1 — возврат из легенды в компас БЕЗ движения: редактирует то же
     * сообщение обратно на карту+розетку (кнопка «🧭 К карте» → callback mapBack).
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function renderCompassInPlace(int $chatId, int $messageId, array|CharacterEntity $character): ServerResponse
    {
        $textMapService = new TextMapService();
        $finalText      = $this->buildMapText($character, $textMapService);

        $island        = new IslandPulseService();
        $islandEnabled = $island->enabled();
        if ($islandEnabled) {
            $teaser = $island->teaserLine($island->snapshot());
            if ($teaser !== null) {
                $finalText .= "\n" . $teaser . "\n";
            }
        }

        $keyboard = ['inline_keyboard' => $this->buildDirectionsKeyboard($islandEnabled, $character)];

        return Request::editMessageText([
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $finalText,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /** Killswitch ADR-150 ФИНАЛ (navigation.final_grid.enabled). Overridable seam для тестов. */
    protected function finalGridEnabled(): bool
    {
        return \App\Services\Telegram\BotMenuService::finalGridEnabled();
    }

    /** Killswitch ADR-150 Слайс 1 (navigation.world_hub.enabled). Overridable seam для тестов. */
    protected function worldHubEnabled(): bool
    {
        return LiveMapService::worldHubEnabled();
    }
}

<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Player\VehicleActivationService;
use App\Services\Tasks\ActiveTasksService;
use App\Services\World\MarchPaceService;
use App\Services\World\TextMapService;
use App\Services\World\VehicleEffectsService;
use CodeIgniter\Database\BaseResult;
use Config\CraftRecipes;
use Config\Database;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * ADR-019 Step 3c — UI «Похода»: набор маршрута, выступление, продление, возобновление.
 *
 * Callback-флоу (один class, dispatch по callback_data):
 *   `march`               → экран выбора направления (8 кнопок → march_<dir>_1)
 *   `march_<dir>_<n>`     → экран маршрута + предупреждение (➖/➕5/Выступить/др.направление/назад)
 *   `march_go_<dir>_<n>`  → создать Marching-задачу (status='in_work', task_settings),
 *                           отредактировать сообщение в «🚜 Поход начат…» + карта
 *                           (MarchingTaskHandler дальше редактирует это сообщение каждый тик)
 *   `march_more_<n>`      → продлить идущий поход на n клеток (steps_planned += n) + toast
 *   `march_resume`        → возобновить paused-поход (status='in_work', end_time=now+minutes_per_cell) + toast
 *
 * Экраны редактируют сообщение, на котором нажата кнопка (ADR-018 navTarget), с
 * fallback на новое. Остановку похода обрабатывает {@see CancelMarchAction} (`cancelMarch`).
 */
class MarchAction extends BaseAction
{
    use \App\Services\GameSettings\GameSettingsReaderTrait;

    /** @var array<string, array{int,int}> */
    private const DIRECTIONS = [
        'north'     => [0, -1],
        'south'     => [0, 1],
        'west'      => [-1, 0],
        'east'      => [1, 0],
        'northwest' => [-1, -1],
        'northeast' => [1, -1],
        'southwest' => [-1, 1],
        'southeast' => [1, 1],
    ];

    /**
     * Порог «длинного» Похода для JIT-подсказки про транспорт (story 12): не баланс
     * (не влияет на цену/вероятность/формулу боя — только на то, ПОКАЗЫВАЕМ ли
     * одноразовую подсказку), поэтому остаётся кодовой константой, а не GameSettings.
     * Значение — из «чувствуемого» примера концепта (`concept-final.md` §3): «Поход
     * в 10 клеток».
     */
    private const LONG_MARCH_HINT_THRESHOLD_CELLS = 10;

    /** @var array<string, string> */
    private const DIR_LABEL = [
        'north'     => '⬆️ Север',
        'south'     => '⬇️ Юг',
        'west'      => '⬅️ Запад',
        'east'      => '➡️ Восток',
        'northwest' => '↖️ Сев-Запад',
        'northeast' => '↗️ Сев-Восток',
        'southwest' => '↙️ Юго-Запад',
        'southeast' => '↘️ Юго-Восток',
    ];

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $telegramId = (int) $this->callbackQuery->getFrom()->getId();
        $userRow    = $this->fetchRow('SELECT id FROM telegram_users WHERE telegram_id = ? LIMIT 1', [$telegramId]);
        if ($userRow === null) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден.']);
        }
        $telegramUserId = $this->asInt($userRow['id'] ?? 0);
        $charRow        = $this->fetchRow('SELECT id, cell_number, level FROM characters WHERE telegram_user_id = ? LIMIT 1', [$telegramUserId]);
        if ($charRow === null) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }
        $characterId   = $this->asInt($charRow['id'] ?? 0);
        $charCellNumber = $this->asInt($charRow['cell_number'] ?? 0);
        $characterLevel = $this->asInt($charRow['level'] ?? 0);

        $data  = (string) $this->callbackQuery->getData();
        $parts = explode('_', $data);

        if ($data === 'march_resume') {
            return $this->resumeMarch($characterId);
        }
        if (count($parts) === 3 && $parts[1] === 'more') {
            return $this->extendMarch($characterId, max(1, (int) $parts[2]));
        }
        if (count($parts) === 4 && $parts[1] === 'go') {
            return $this->startMarch($characterId, $telegramUserId, $charCellNumber, (string) $parts[2], (int) $parts[3], $chatId);
        }
        if (count($parts) === 3 && isset(self::DIRECTIONS[$parts[1]])) {
            return $this->showRouteSetup($characterId, $charCellNumber, (string) $parts[1], (int) $parts[2], $chatId, $characterLevel);
        }
        return $this->showDirectionPicker($characterId, $chatId);
    }

    // ------------------------------------------------------------------ screens

    private function showDirectionPicker(int $characterId, int $chatId): ServerResponse
    {
        $blocked = $this->blockReason($characterId, $chatId);
        if ($blocked !== null) {
            return $blocked;
        }

        $text = "🚜 *Куда выступаем?* Выбери направление.\n\n"
            . "_В походе ты идёшь, пока что-то не потребует решения: встреча с игроком, "
            . "тяжёлая рана, чужой лагерь на пути, край мира, привал по усталости._";
        $keyboard = [
            [$this->dirBtn('northwest'), $this->dirBtn('north'), $this->dirBtn('northeast')],
            [$this->dirBtn('west'), ['text' => '↩️ К карте', 'callback_data' => 'move'], $this->dirBtn('east')],
            [$this->dirBtn('southwest'), $this->dirBtn('south'), $this->dirBtn('southeast')],
        ];
        return $this->editOrSendText($chatId, $text, $keyboard);
    }

    private function showRouteSetup(int $characterId, int $charCellNumber, string $dir, int $n, int $chatId, int $characterLevel): ServerResponse
    {
        if (!isset(self::DIRECTIONS[$dir])) {
            return $this->showDirectionPicker($characterId, $chatId);
        }
        $blocked = $this->blockReason($characterId, $chatId);
        if ($blocked !== null) {
            return $blocked;
        }
        $aheadInfo = $this->aheadInfo($characterId, $charCellNumber, $dir);
        $pace      = new MarchPaceService();
        $profile   = $this->resolveVehicleProfile($characterId, $aheadInfo['terrain']);
        $clamp     = $this->clampOrderToCap($n, $profile);
        $n         = $clamp['n'];
        $cap       = $clamp['cap'];

        $aheadBiome = $aheadInfo['label'];
        $hpEst      = round($n * $pace->healthCostPerCell($this->healthCostPerCell(), $profile), 2);
        $tiredEst   = round($n * $pace->tiredCostPerCell($this->tiredCostPerCell(), $profile), 2);
        $dirLabel   = self::DIR_LABEL[$dir];

        // Находка 1 (ревью): было ДВА независимых ETA — заголовок по профилю одной клетки
        // впереди на весь заказ, и сумма ceil() по сегментам разбивки — расходятся системно
        // (sum(ceil) >= ceil(sum)), врёт даже пешеходу. Теперь ОДНА цифра — `routeBreakdown()`
        // считает по фактическому пути одним финальным ceil ({@see self::routeEtaMinutes()}),
        // и заголовок, и разбивка берут её же. Пеший ETA для сравнения — та же функция на
        // нейтральном профиле (тот же путь, тот же ceil), не отдельный третий расчёт.
        $profileKey    = is_string($profile['key'] ?? null) ? $profile['key'] : null;
        $breakdown     = $this->routeBreakdown($characterId, $charCellNumber, $dir, $n, $profileKey);
        $minEst        = $breakdown['total_minutes'];
        $pedestrianMinEst = $breakdown['pedestrian_minutes'];
        $breakdownLine = $this->breakdownLine($breakdown, $pedestrianMinEst);

        // Крючок «Транспорт» (story 12, ADR-174 §3): цель видна ВСЕГДА, пока игрок
        // топает пешком — UX-DISCOVERABILITY. Чистая функция builds текст+кнопку,
        // сама сборка активной машины — единственное DB-обращение здесь.
        // Находка 2 (ревью): килсвитч `world.vehicle.enabled` по умолчанию false — крючок
        // не имеет права обещать эффект, которого сейчас нет ни у кого.
        $vehicleEnabled = (new VehicleEffectsService())->isEnabled();
        $requiredLevel  = self::vehicleRequiredLevel();
        $activeVehicle  = $characterLevel >= $requiredLevel ? $this->activeVehicleDisplay($characterId) : null;
        $hook           = self::vehicleHookBlock($characterLevel, $requiredLevel, $activeVehicle, $vehicleEnabled);

        $text = "🚜 *Поход:* {$dirLabel} ×{$n}\n\n"
            . "Видишь впереди (1 клетка): {$aheadBiome}. Дальше — туман.\n"
            . "В пути возможно (поход тогда прервётся, ты решишь):\n"
            . "  • встреча с игроком → бой / бегство / пройти мимо\n"
            . "  • рейдеры → авто-стычка; тяжёлая рана → привал\n"
            . "  • объект/событие со 100% триггером → отчёт и выбор\n"
            . "  • чужой лагерь на пути → остановишься не доходя\n"
            . "  • кончится выносливость → привал раньше срока\n"
            . "_Прочее (находки, биомы, мелочи) — разгребётся само, отчёт по прибытии._\n\n"
            . "Расход ≈ ❤️{$hpEst}  💤{$tiredEst}  ·  в пути ~{$minEst} мин\n"
            . ($breakdownLine !== '' ? "{$breakdownLine}\n" : '')
            . "_Отряд идёт сам и довольно шустро — темп от ❤️/💤 не зависит "
            . "(они лишь топливо в пути), но зависит от активного транспорта._\n\n"
            . $clamp['capLine']
            . $hook['line'];

        $minus = max(1, $n - 1);
        $plus  = min($n + 5, $cap);
        $keyboard = [
            [
                ['text' => '➖', 'callback_data' => "march_{$dir}_{$minus}"],
                ['text' => "×{$n}", 'callback_data' => "march_{$dir}_{$n}"],
                ['text' => '➕5', 'callback_data' => "march_{$dir}_{$plus}"],
            ],
            [['text' => '🚜 Выступить', 'callback_data' => "march_go_{$dir}_{$n}"]],
            [
                ['text' => '🧭 Другое направление', 'callback_data' => 'march'],
                ['text' => '↩️ Назад', 'callback_data' => 'move'],
                $hook['button'],
            ],
        ];
        return $this->editOrSendText($chatId, $text, $keyboard);
    }

    /**
     * Story 03 (`chat-requests-batch`) — потолок заказа всегда назван в тексте, тем же
     * числом, что клэмп `$n` (`profile['max_steps_per_order']`, ADR-174: зависит от
     * машины). Показывается всегда, не только при упоре в потолок (feedback Max Syskov
     * «в походе могу выбрать максимум 60 клеток, это баг или фича?)»).
     */
    private static function capLine(int $cap): string
    {
        return "Заказать можно не больше {$cap} клеток за раз (зависит от транспорта).\n\n";
    }

    /**
     * Ревью-доследование story 03 — считает потолок из профиля транспорта, клэмпит
     * `$n` к нему И строит `capLine()` ОДНОЙ функцией, чтобы клэмп и число,
     * показанное игроку, физически не могли разойтись. Раньше `showRouteSetup()`
     * сам считал `$cap`, сам клэмпил `$n` и ОТДЕЛЬНО передавал `$cap` в `capLine()`
     * тремя независимыми строками — подменить литералом или потерять вызов при
     * правке мог любую из них, а `MarchCapTextTest` этого не ловил: он гонял
     * `capLine()` в изоляции, а не то, чем реально зажимается заказ в
     * `showRouteSetup()`. Теперь оба потребителя (клэмп `$n` и текст) читают
     * `$cap`/`capLine` из ОДНОГО возврата — разойтись им негде.
     *
     * @param array<string,mixed> $profile профиль транспорта ({@see resolveVehicleProfile()})
     * @return array{n:int,cap:int,capLine:string}
     */
    private function clampOrderToCap(int $n, array $profile): array
    {
        $cap = max(1, $this->asInt($profile['max_steps_per_order'] ?? 60, 60));
        $n   = max(1, min($n, $cap));

        return ['n' => $n, 'cap' => $cap, 'capLine' => self::capLine($cap)];
    }

    private function startMarch(int $characterId, int $telegramUserId, int $charCellNumber, string $dir, int $n, int $chatId): ServerResponse
    {
        if (!isset(self::DIRECTIONS[$dir])) {
            return $this->showDirectionPicker($characterId, $chatId);
        }
        $blocked = $this->blockReason($characterId, $chatId);
        if ($blocked !== null) {
            return $blocked;
        }
        $aheadInfo = $this->aheadInfo($characterId, $charCellNumber, $dir);
        $profile   = $this->resolveVehicleProfile($characterId, $aheadInfo['terrain']);
        $n         = max(1, min($n, max(1, $this->asInt($profile['max_steps_per_order'] ?? 60, 60))));

        $marchingTaskId = $this->marchingTaskId();
        if ($marchingTaskId === null) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Задача "Поход" не найдена в системе.']);
        }

        $clickedMsgId = $this->callbackQuery->getMessage()->getMessageId();
        $settings = [
            'heading'       => $dir,
            'steps_planned' => $n,
            'steps_done'    => 0,
            'started_cell'  => $charCellNumber,
            'msg_chat_id'   => $chatId,
            'msg_id'        => $clickedMsgId,
            'acc'           => [],
            'log'           => [],
        ];
        $start = new \DateTime();
        $end   = (clone $start)->add($this->stepDueInterval());
        $this->characterTaskModel->insert([
            'character_id'     => $characterId,
            'telegram_user_id' => $telegramUserId,
            'task_id'          => $marchingTaskId,
            'start_time'       => $start->format('Y-m-d H:i:s'),
            'end_time'         => $end->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode($settings),
        ]);

        // JIT-подсказка при ПЕРВОМ Походе (one-shot): объясняет логику скорости —
        // темп постоянный, ❤️/💤 = топливо, не двигатель (след вопроса Max Syskov).
        // Defensive: сбой подсказки не должен ломать старт похода.
        try {
            (new \App\Services\Onboarding\OnboardingHintService())->maybeSendFirstMarchHint($characterId, $chatId);
        } catch (\Throwable $e) {
            log_message('error', '[MarchAction] first-march hint: ' . $e->getMessage());
        }

        // JIT-подсказка про транспорт (story 12) — при ПЕРВОМ ДЛИННОМ Походе (one-shot).
        // Зовём public maybeSend() каталога напрямую: генерик-метод не заводит гейта
        // по уровню (длинный Поход можно запустить и до 6 ур.). Defensive — как выше.
        if ($n >= self::LONG_MARCH_HINT_THRESHOLD_CELLS) {
            try {
                $hintRow = $this->fetchRow('SELECT id, daily_tips_enabled FROM characters WHERE id = ? LIMIT 1', [$characterId]);
                if ($hintRow !== null) {
                    /** @var array<string, mixed> $hintCharacter */
                    $hintCharacter = [
                        'id'                 => $this->asInt($hintRow['id'] ?? 0),
                        'daily_tips_enabled' => $hintRow['daily_tips_enabled'] ?? 1,
                    ];
                    (new \App\Services\Onboarding\OnboardingHintService())->maybeSend(
                        $hintCharacter,
                        $chatId,
                        \App\Services\Onboarding\OnboardingHintCatalog::FIRST_LONG_MARCH
                    );
                }
            } catch (\Throwable $e) {
                log_message('error', '[MarchAction] first-long-march hint: ' . $e->getMessage());
            }
        }

        $map     = $this->renderMap($characterId);
        $dirLabel = self::DIR_LABEL[$dir];
        $text = "🚜 *Поход начат:* {$dirLabel} ×{$n}\n\n{$map}\n"
            . "_Карта обновляется по мере движения — отряд идёт сам и довольно шустро "
            . "(темп ровный, от ❤️/💤 не зависит)._";
        $keyboard = [[['text' => '❌ Остановиться', 'callback_data' => 'cancelMarch']]];
        return $this->editOrSendText($chatId, $text, $keyboard);
    }

    private function extendMarch(int $characterId, int $n): ServerResponse
    {
        $task = $this->activeMarchTask($characterId, 'in_work');
        if ($task === null) {
            return Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
                'text'              => 'Поход уже завершён — продлевать нечего.',
            ]);
        }
        $s = json_decode($this->asStr($task['task_settings'] ?? '{}', '{}'), true);
        if (!is_array($s)) {
            $s = [];
        }
        $total = max(1, $this->asInt($s['steps_planned'] ?? 1)) + $n;
        $s['steps_planned'] = $total;
        $this->characterTaskModel->update($this->asInt($task['id'] ?? 0), ['task_settings' => json_encode($s)]);

        return Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => "Поход продлён на {$n} клеток. Всего: {$total}.",
        ]);
    }

    private function resumeMarch(int $characterId): ServerResponse
    {
        $task = $this->activeMarchTask($characterId, 'paused');
        if ($task === null) {
            return Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
                'text'              => 'Походов на паузе нет.',
            ]);
        }
        $start = new \DateTime();
        $end   = (clone $start)->add($this->stepDueInterval());
        $this->characterTaskModel->update($this->asInt($task['id'] ?? 0), [
            'status'     => 'in_work',
            'start_time' => $start->format('Y-m-d H:i:s'),
            'end_time'   => $end->format('Y-m-d H:i:s'),
        ]);
        return Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Поход возобновлён.',
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string,string> */
    private function dirBtn(string $dir): array
    {
        return ['text' => self::DIR_LABEL[$dir], 'callback_data' => "march_{$dir}_1"];
    }

    /**
     * Интервал «созревания» шага марша: `world.march.minutes_per_cell − 1` мин (см.
     * подробный разбор в {@see \App\TaskHandlers\MarchingTaskHandler::stepDueInterval()}).
     * Worker — everyMinute, спавн phase-locked к тику → −1 убирает лишний тик ожидания
     * (дрожание 1–2 мин/клетку → ровно ~1 мин/клетку). M=1 → PT0M = due на след. тике.
     */
    private function stepDueInterval(): \DateInterval
    {
        $minutes = (new MarchPaceService())->stepDueInterval($this->minutesPerCell());

        return new \DateInterval('PT' . $minutes . 'M');
    }

    /**
     * Профиль активного транспорта персонажа (transport-04) для класса местности
     * `$terrain`. Killswitch off / нет активной машины / заряды = 0 / имя предмета не
     * резолвится через {@see VehicleEffectsService::keyForItemNameEng()} →
     * {@see VehicleEffectsService::neutralProfile()} — контракт `plan.md → ## Contracts`.
     *
     * @return array<string,mixed>
     */
    private function resolveVehicleProfile(int $characterId, string $terrain): array
    {
        $effects = new VehicleEffectsService();
        if (!$effects->isEnabled()) {
            return $effects->neutralProfile();
        }
        $active = (new VehicleActivationService())->resolveActive($characterId);
        if ($active === null || $active['charges'] <= 0) {
            return $effects->neutralProfile();
        }
        $vehicleKey = VehicleEffectsService::keyForItemNameEng($active['key']);
        if ($vehicleKey === null) {
            return $effects->neutralProfile();
        }
        return $effects->profileFor($vehicleKey, $terrain);
    }

    /**
     * Требуемый уровень общего транспорта (крючок читает его из рецепта каталога
     * `Config\CraftRecipes`, а не хардкодит число второй раз — единый источник правды
     * с `VehicleAction::lockInfo()`/крафт-витриной story 07).
     */
    private static function vehicleRequiredLevel(): int
    {
        $recipe = (new CraftRecipes())->get('LightCart');

        return is_numeric($recipe['required_level'] ?? null) ? (int) $recipe['required_level'] : 6;
    }

    /**
     * Активная машина для крючка (story 12): имя/иконка — `Config\CraftRecipes`,
     * остаток износа в клетках — то же чтение GameSettings, что `VehicleAction::buildState()`
     * (`world.vehicle.<key>.{charges_full,wear_per_cell}`). null — если машины нет.
     *
     * @return array{icon:string,name:string,cells_left:int}|null
     */
    private function activeVehicleDisplay(int $characterId): ?array
    {
        $resolved = (new VehicleActivationService())->resolveActive($characterId);
        if ($resolved === null) {
            return null;
        }
        $vehicleKey  = VehicleEffectsService::keyForItemNameEng($resolved['key']);
        $chargesFull = $vehicleKey !== null ? $this->gsInt("world.vehicle.{$vehicleKey}.charges_full", $resolved['charges']) : $resolved['charges'];
        $wearPerCell = $vehicleKey !== null ? $this->gsInt("world.vehicle.{$vehicleKey}.wear_per_cell", 1) : 1;
        $cellsLeft   = $wearPerCell > 0 ? intdiv($resolved['charges'], $wearPerCell) : $resolved['charges'];

        $recipe = (new CraftRecipes())->findByItemNameEng($resolved['key']);
        $icon   = is_string($recipe['icon_emoji'] ?? null) ? $recipe['icon_emoji'] : '🚚';
        $name   = is_string($recipe['item_name_rus'] ?? null) ? $recipe['item_name_rus'] : 'Транспорт';

        return ['icon' => $icon, 'name' => $name, 'cells_left' => max(0, $cellsLeft)];
    }

    /**
     * Крючок «Транспорт» на экране Похода (story 12, ADR-174 §3, правило
     * UX-DISCOVERABILITY): видна ВСЕГДА, а не только при выполненном условии.
     * Чистая функция без БД — тестируется напрямую в `VehicleGuideAndHintTest`
     * (memory `feedback_caption_length_needs_a_test_not_a_note`).
     *
     * Ниже требуемого уровня — строка-замок с реальным уровнем игрока + кнопка на
     * крафт-витрину (`resourcesCrafting`, story 07), которая честно объясняет прогресс
     * ("с N уровня — у тебя M") для ЛЮБОЙ из пяти машин, включая общую (в отличие от
     * `vehicleLockInfo`, рассчитанного только на фракционные машины — использовать его
     * здесь для LightCart значило бы показать «Только фракция ?», это вне файлов story 12).
     * С требуемого уровня — вход на «🚚 Мой транспорт»; с активной машиной — реальные
     * числа (имя + остаток), а не обещание (acceptance: «числами, которые чувствуются,
     * и только тем, у кого машина есть»).
     *
     * Находка 2 (ревью): при выключенном килсвитче `world.vehicle.enabled` (default
     * false) транспорт ни у кого не даёт эффекта (`VehicleEffectsService::profileFor()`
     * всегда откатывается на нейтраль) — крючок обязан честно сказать это, а не
     * обещать ускорение/остаток заряда, которых сейчас нет ни у кого.
     *
     * @param array{icon:string,name:string,cells_left:int}|null $active
     * @return array{line:string, button:array{text:string,callback_data:string}}
     */
    public static function vehicleHookBlock(int $characterLevel, int $requiredLevel, ?array $active, bool $vehicleEnabled = true): array
    {
        if (!$vehicleEnabled) {
            return [
                'line'   => '🚚 Транспорт — в разработке, на темп похода пока не влияет.',
                'button' => ['text' => '🚚 Транспорт', 'callback_data' => 'vehicleScreen'],
            ];
        }

        if ($characterLevel < $requiredLevel) {
            return [
                'line'   => "🔒 Транспорт — с {$requiredLevel} уровня (у тебя {$characterLevel})",
                'button' => ['text' => '🔒 Транспорт', 'callback_data' => 'resourcesCrafting'],
            ];
        }

        if ($active === null) {
            return [
                'line'   => '🚚 Транспорт доступен — собери в 🔨 Крафте, ускорит длинные переходы.',
                'button' => ['text' => '🚚 Транспорт', 'callback_data' => 'vehicleScreen'],
            ];
        }

        $icon = $active['icon'] !== '' ? $active['icon'] : '🚚';
        $name = $active['name'] !== '' ? $active['name'] : 'Транспорт';

        return [
            'line'   => "🚚 Активна: {$icon} {$name} — хватит ещё на ~{$active['cells_left']} клеток.",
            'button' => ['text' => '🚚 Транспорт', 'callback_data' => 'vehicleScreen'],
        ];
    }

    // ── march-баланс: live-tunable через admin GameSettings `world.march.*`
    //    (ADMIN-TUNABLE BALANCE, ADR-024). Fallback-числа = safe-baseline код-дефолт,
    //    синхронны с MarchingTaskHandler и seed-миграцией 2026-10-08.

    /** Минут на крон-тик Похода. Fallback 1. */
    private function minutesPerCell(): int
    {
        return max(1, $this->gsInt('world.march.minutes_per_cell', 1));
    }

    /** Базовый расход ❤️ за клетку (для оценки на экране заказа). Fallback 0.02. */
    private function healthCostPerCell(): float
    {
        return $this->gsFloat('world.march.health_cost_per_cell', 0.02);
    }

    /** Расход 💤 за клетку (для оценки на экране заказа). Fallback 0.5. */
    private function tiredCostPerCell(): float
    {
        return $this->gsFloat('world.march.tired_cost_per_cell', 0.5);
    }

    /** ServerResponse если поход начать нельзя (блокирующая задача / переезд), иначе null. */
    private function blockReason(int $characterId, int $chatId): ?ServerResponse
    {
        if ((new ActiveTasksService())->checkRelocationAndBlock($characterId, $this->callbackQuery->getId(), $chatId)) {
            return Request::emptyResponse();
        }
        if (!$this->checkParallelExecutionAllowed($characterId)) {
            $resp = $this->prepareBlockedTaskResponse('cancelMarch');
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $resp['text'],
                'reply_markup' => $resp['reply_markup'],
                'parse_mode'   => 'Markdown',
            ]);
        }
        return null;
    }

    /**
     * Клетка впереди (1 шаг): подпись для текста (как раньше `biomeAhead()`) + класс
     * местности (`MarchPaceService::TERRAIN_*`) для профиля транспорта — один и тот же
     * lookup, не два по отдельности (E5/story-04).
     *
     * @return array{label: string, terrain: string}
     */
    private function aheadInfo(int $characterId, int $charCellNumber, string $dir): array
    {
        $cell = $this->fetchRow('SELECT coordinate_x, coordinate_y FROM map WHERE cell_number = ? LIMIT 1', [$charCellNumber]);
        if ($cell === null) {
            return ['label' => 'туман', 'terrain' => MarchPaceService::TERRAIN_UNEXPLORED];
        }
        [$dx, $dy] = self::DIRECTIONS[$dir];
        $nx = $this->asInt($cell['coordinate_x'] ?? 0) + $dx;
        $ny = $this->asInt($cell['coordinate_y'] ?? 0) + $dy;
        // Находка 3 (ревью): границы мира — реальная сетка карты `0..999` (1000×1000,
        // сверено с `map`), тот же контур, что и `MarchingTaskHandler::terrainAhead()`/
        // `advanceOneCell()`. Было `1..1000` — расхождение на клетку с обработчиком.
        if ($nx < 0 || $nx > 999 || $ny < 0 || $ny > 999) {
            return ['label' => 'край мира', 'terrain' => MarchPaceService::TERRAIN_UNEXPLORED];
        }
        $target = $this->fetchRow('SELECT cell_number, biome_id FROM map WHERE coordinate_x = ? AND coordinate_y = ? LIMIT 1', [$nx, $ny]);
        if ($target === null) {
            return ['label' => 'туман', 'terrain' => MarchPaceService::TERRAIN_UNEXPLORED];
        }
        $biome    = $this->fetchRow('SELECT name FROM biomes WHERE id = ? LIMIT 1', [$this->asInt($target['biome_id'] ?? 0)]);
        $terrain  = $biome !== null && $this->isColdBiome($this->asStr($biome['name'] ?? ''))
            ? MarchPaceService::TERRAIN_COLD
            : MarchPaceService::TERRAIN_UNEXPLORED;
        $explored = $this->fetchRow(
            'SELECT id FROM explored_cells WHERE character_id = ? AND map_cell_id = ? LIMIT 1',
            [$characterId, $this->asInt($target['cell_number'] ?? 0)]
        );
        if ($explored === null) {
            return ['label' => 'туман', 'terrain' => $terrain];
        }
        if ($terrain !== MarchPaceService::TERRAIN_COLD) {
            $terrain = MarchPaceService::TERRAIN_EXPLORED;
        }
        $label = $biome !== null ? $this->asStr($biome['name'] ?? 'неизвестно', 'неизвестно') : 'неизвестно';
        return ['label' => $label, 'terrain' => $terrain];
    }

    /** Горы/Тундра — «холодная» местность (plan.md → ## Contracts). */
    private function isColdBiome(string $biomeName): bool
    {
        $name = mb_strtolower($biomeName);
        return str_contains($name, 'горы') || str_contains($name, 'тундра');
    }

    /**
     * Разбивка предстоящего маршрута по классу местности (E5/story-04): сколько клеток
     * уже разведано (быстрее с транспортом) и сколько целины (медленнее). Превью-экран,
     * не тик-цикл — один batched SQL-запрос на весь заказ, не «лишний lookup за клетку»
     * из Non-goal тика (тот адресован `MarchingTaskHandler`, не этому экрану).
     *
     * Находка 1 (ревью): `total_minutes`/`pedestrian_minutes` — единственный источник
     * ETA для всего экрана (заголовок И разбивка И «пешком» берут отсюда), через
     * {@see self::routeEtaMinutes()}. Раньше заголовок считал отдельно (профиль одной
     * клетки впереди на весь заказ), а разбивка суммировала ceil() по сегментам —
     * два независимых числа, системно расходившихся.
     *
     * @return array{segments: array<string, array{count:int, per_tick:int}>, total_minutes:int, pedestrian_minutes:int}
     */
    private function routeBreakdown(int $characterId, int $charCellNumber, string $dir, int $n, ?string $profileKey): array
    {
        $empty = ['segments' => [], 'total_minutes' => 0, 'pedestrian_minutes' => 0];
        $start = $this->fetchRow('SELECT coordinate_x, coordinate_y FROM map WHERE cell_number = ? LIMIT 1', [$charCellNumber]);
        if ($start === null || !isset(self::DIRECTIONS[$dir])) {
            return $empty;
        }
        [$dx, $dy] = self::DIRECTIONS[$dir];
        $x = $this->asInt($start['coordinate_x'] ?? 0);
        $y = $this->asInt($start['coordinate_y'] ?? 0);

        $selects = [];
        $bind    = [];
        for ($i = 1; $i <= $n; $i++) {
            $nx = $x + $dx * $i;
            $ny = $y + $dy * $i;
            // Находка 3 (ревью): те же границы `0..999`, что и aheadInfo()/MarchingTaskHandler.
            if ($nx < 0 || $nx > 999 || $ny < 0 || $ny > 999) {
                break;
            }
            $selects[] = 'SELECT ? AS cx, ? AS cy';
            $bind[]    = $nx;
            $bind[]    = $ny;
        }
        if ($selects === []) {
            return $empty;
        }

        $sql = 'SELECT b.name AS biome_name, (ec.id IS NOT NULL) AS is_explored '
            . 'FROM (' . implode(' UNION ALL ', $selects) . ') coords '
            . 'JOIN map m ON m.coordinate_x = coords.cx AND m.coordinate_y = coords.cy '
            . 'LEFT JOIN biomes b ON b.id = m.biome_id '
            . 'LEFT JOIN explored_cells ec ON ec.character_id = ? AND ec.map_cell_id = m.cell_number';
        $bind[] = $characterId;

        $res  = Database::connect()->query($sql, $bind);
        $rows = $res instanceof BaseResult ? $res->getResultArray() : [];

        $counts = [
            MarchPaceService::TERRAIN_EXPLORED   => 0,
            MarchPaceService::TERRAIN_UNEXPLORED => 0,
            MarchPaceService::TERRAIN_COLD       => 0,
        ];
        foreach ($rows as $row) {
            if ($this->isColdBiome($this->asStr($row['biome_name'] ?? ''))) {
                $counts[MarchPaceService::TERRAIN_COLD]++;
            } elseif (!empty($row['is_explored'])) {
                $counts[MarchPaceService::TERRAIN_EXPLORED]++;
            } else {
                $counts[MarchPaceService::TERRAIN_UNEXPLORED]++;
            }
        }

        $effects           = new VehicleEffectsService();
        $pace              = new MarchPaceService();
        $base              = max(1, $this->gsInt('world.march.cells_per_tick', 3));
        $minutes           = $this->minutesPerCell();
        $segments          = [];
        $pedestrianSegments = [];
        foreach ($counts as $terrain => $count) {
            if ($count <= 0) {
                continue;
            }
            $perTick               = $pace->cellsPerTick($base, $effects->profileFor($profileKey, $terrain));
            $pedestrianPerTick     = $pace->cellsPerTick($base, $effects->neutralProfile());
            $segments[$terrain]    = ['count' => $count, 'per_tick' => $perTick];
            $pedestrianSegments[$terrain] = ['count' => $count, 'per_tick' => $pedestrianPerTick];
        }

        return [
            'segments'           => $segments,
            'total_minutes'      => self::routeEtaMinutes($segments, $minutes),
            'pedestrian_minutes' => self::routeEtaMinutes($pedestrianSegments, $minutes),
        ];
    }

    /**
     * Единственная арифметика ETA маршрута (Находка 1 ревью): сумма ДОЛЕЙ тика по
     * сегментам местности, ОДИН `ceil()` в конце — не сумма `ceil()` по каждому
     * сегменту (та схема завышает системно: `ceil(a) + ceil(b) >= ceil(a + b)`).
     * Чистая функция без БД/GameSettings — тестируется напрямую без бутстрапа.
     *
     * @param array<string, array{count:int, per_tick:int}> $segments
     */
    public static function routeEtaMinutes(array $segments, int $minutesPerCell): int
    {
        if ($segments === []) {
            return 0;
        }
        $ticksExact = 0.0;
        foreach ($segments as $seg) {
            $count   = max(0, (int) $seg['count']);
            $perTick = max(1, (int) $seg['per_tick']);
            $ticksExact += $count / $perTick;
        }

        return (int) ceil($ticksExact) * max(1, $minutesPerCell);
    }

    /**
     * @param array{segments: array<string, array{count:int, per_tick:int}>, total_minutes:int, pedestrian_minutes:int} $breakdown
     */
    private function breakdownLine(array $breakdown, int $pedestrianMinutes): string
    {
        if ($breakdown['segments'] === []) {
            return '';
        }
        $labels = [
            MarchPaceService::TERRAIN_EXPLORED   => 'Разведано',
            MarchPaceService::TERRAIN_UNEXPLORED => 'целина',
            MarchPaceService::TERRAIN_COLD       => 'мороз',
        ];
        $parts = [];
        foreach ($breakdown['segments'] as $terrain => $seg) {
            $parts[] = "{$labels[$terrain]} {$seg['count']} по {$seg['per_tick']}";
        }
        return implode(' · ', $parts) . " — {$breakdown['total_minutes']} мин (пешком {$pedestrianMinutes})";
    }

    private function renderMap(int $characterId): string
    {
        $charRow = $this->fetchRow('SELECT * FROM characters WHERE id = ? LIMIT 1', [$characterId]);
        if ($charRow === null) {
            return '';
        }
        try {
            return (new TextMapService())->buildMapOnly($charRow);
        } catch (\Throwable $e) {
            log_message('error', '[MarchAction] map render: ' . $e->getMessage());
            return '';
        }
    }

    private function marchingTaskId(): ?int
    {
        $row = $this->fetchRow("SELECT id FROM tasks WHERE name = 'Marching' LIMIT 1", []);
        if ($row === null) {
            return null;
        }
        $id = $this->asInt($row['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** @return array<int|string, mixed>|null */
    private function activeMarchTask(int $characterId, string $status): ?array
    {
        $marchingTaskId = $this->marchingTaskId();
        if ($marchingTaskId === null) {
            return null;
        }
        return $this->fetchRow(
            'SELECT id, task_settings FROM character_tasks WHERE character_id = ? AND task_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
            [$characterId, $marchingTaskId, $status]
        );
    }

    /**
     * @param array<int, int|string> $bind
     * @return array<int|string, mixed>|null
     */
    private function fetchRow(string $sql, array $bind): ?array
    {
        $res = Database::connect()->query($sql, $bind);
        if (!$res instanceof BaseResult) {
            return null;
        }
        $rows = $res->getResultArray();
        $row  = $rows[0] ?? null;
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int, array<int, array<string,string>>> $keyboardRows
     */
    private function editOrSendText(int $chatId, string $text, array $keyboardRows): ServerResponse
    {
        $payload = [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboardRows]),
        ];
        $msgId = $this->callbackQuery->getMessage()->getMessageId();
        try {
            $resp = Request::editMessageText($payload + ['message_id' => $msgId]);
            if ($resp->isOk()) {
                return $resp;
            }
        } catch (\Throwable $e) {
            // fallthrough → новое сообщение
        }
        return Request::sendMessage($payload);
    }

    private function asInt(mixed $v, int $default = 0): int
    {
        return is_numeric($v) ? (int) $v : $default;
    }

    private function asStr(mixed $v, string $default = ''): string
    {
        return is_scalar($v) ? (string) $v : $default;
    }
}

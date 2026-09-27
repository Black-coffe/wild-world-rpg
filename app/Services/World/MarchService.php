<?php

declare(strict_types=1);

namespace App\Services\World;

use App\Models\CharacterTaskModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\Services\Onboarding\OnboardingHintCatalog;
use App\Services\Onboarding\OnboardingHintService;
use App\Services\Player\VehicleActivationService;
use App\Services\Tasks\ActiveTasksService;
use CodeIgniter\Database\BaseResult;
use Config\CraftRecipes;
use Config\Database;

/**
 * W2.N2-03 (ADR-190, ADR-019) — «Поход» без Telegram: превью маршрута, старт, продление,
 * возобновление, остановка и статус для HUD. Логика перенесена 1:1 из `MarchAction` /
 * `CancelMarchAction`; оба handler'а теперь только рендерят исходы этого сервиса, веб
 * (`/play/view`, `op=march_*`) зовёт те же методы.
 *
 * Исход — `{ok, code, message, …}`: `message` — готовый текст отказа/ответа без Markdown.
 * Сервис сам в Telegram не пишет; `msg_chat_id`/`msg_id` в `task_settings` пишет только бот
 * ({@see attachMessage()}) после отправки своего сообщения — Поход из веба идёт без них, и тик
 * ({@see \App\TaskHandlers\MarchingTaskHandler}) шлёт прогресс новым сообщением (прежний фолбэк).
 * Подсказки первого Похода, которым нужен чат, — {@see afterStart()} (веб зовёт её под захватом).
 *
 * Баланс — `world.march.*` (GameSettings, ADR-024), ключи и дефолты прежние.
 *
 * @phpstan-type Hook array{line:string, button:array{text:string,callback_data:string}}
 * @phpstan-type Preview array{ok:bool, code:string, message:string, dir:string, dir_label:string, n:int, cap:int, cap_line:string, ahead:string, hp:float, tired:float, eta_minutes:int, pedestrian_minutes:int, breakdown_line:string, hook:?Hook}
 * @phpstan-type Status array{status:string, heading:string, heading_label:string, steps_done:int, steps_planned:int, eta:?int, paused_reason:?string}
 */
class MarchService
{
    use GameSettingsReaderTrait;

    /** @var array<string, array{int,int}> */
    public const DIRECTIONS = [
        'north'     => [0, -1],
        'south'     => [0, 1],
        'west'      => [-1, 0],
        'east'      => [1, 0],
        'northwest' => [-1, -1],
        'northeast' => [1, -1],
        'southwest' => [-1, 1],
        'southeast' => [1, 1],
    ];

    /** @var array<string, string> */
    public const DIR_LABEL = [
        'north'     => '⬆️ Север',
        'south'     => '⬇️ Юг',
        'west'      => '⬅️ Запад',
        'east'      => '➡️ Восток',
        'northwest' => '↖️ Сев-Запад',
        'northeast' => '↗️ Сев-Восток',
        'southwest' => '↙️ Юго-Запад',
        'southeast' => '↘️ Юго-Восток',
    ];

    /**
     * Порог «длинного» Похода для JIT-подсказки про транспорт (story 12): не баланс — решает
     * только, ПОКАЗЫВАТЬ ли одноразовую подсказку (`concept-final.md` §3: «Поход в 10 клеток»).
     */
    public const LONG_MARCH_HINT_THRESHOLD_CELLS = 10;

    /** Шаг ➕ на экране маршрута и «Продлить» в прогрессе (тот же, что у кнопок бота). */
    public const EXTEND_STEP = 5;

    public const PREVIEW  = 'preview';
    public const STARTED  = 'started';
    public const EXTENDED = 'extended';
    public const RESUMED  = 'resumed';
    public const STOPPED  = 'stopped';

    public const BAD_DIR      = 'bad_dir';
    public const NO_CHARACTER = 'no_character';
    public const RELOCATION   = 'relocation';
    public const BUSY         = 'busy';
    public const NO_TASK      = 'no_task';
    public const NOT_ACTIVE   = 'not_active';
    public const NOT_PAUSED   = 'not_paused';
    public const NO_MARCH     = 'no_march';

    public static function isDirection(string $dir): bool
    {
        return isset(self::DIRECTIONS[$dir]);
    }

    /**
     * Экран маршрута: биом впереди, расход, ETA по местности, потолок заказа, крючок транспорта.
     *
     * @return Preview
     */
    public function preview(int $characterId, string $dir, int $n): array
    {
        $refusal = $this->refusalFor($characterId, $dir);
        if ($refusal !== null) {
            return self::previewRefusal($refusal['code'], $refusal['message'], $dir);
        }
        $char        = $this->character($characterId);
        $cellNumber  = self::int($char['cell_number'] ?? 0);
        $level       = self::int($char['level'] ?? 0);
        $aheadInfo   = $this->aheadInfo($characterId, $cellNumber, $dir);
        $pace        = new MarchPaceService();
        $profile     = $this->resolveVehicleProfile($characterId, $aheadInfo['terrain']);
        $clamp       = self::clampOrderToCap($n, $profile);
        $n           = $clamp['n'];

        // Одна цифра ETA на весь экран (Находка 1 ревью): заголовок, разбивка и «пешком» —
        // из routeBreakdown() по фактическому пути, одним финальным ceil.
        $profileKey = is_string($profile['key'] ?? null) ? $profile['key'] : null;
        $breakdown  = $this->routeBreakdown($characterId, $cellNumber, $dir, $n, $profileKey);

        // Крючок «Транспорт» (story 12, ADR-174 §3) — виден всегда; при выключенном килсвитче
        // честно говорит, что эффекта нет (Находка 2 ревью).
        $vehicleEnabled = (new VehicleEffectsService())->isEnabled();
        $requiredLevel  = self::vehicleRequiredLevel();
        $activeVehicle  = $level >= $requiredLevel ? $this->activeVehicleDisplay($characterId) : null;

        return [
            'ok'                 => true,
            'code'               => self::PREVIEW,
            'message'            => '',
            'dir'                => $dir,
            'dir_label'          => self::DIR_LABEL[$dir],
            'n'                  => $n,
            'cap'                => $clamp['cap'],
            'cap_line'           => $clamp['capLine'],
            'ahead'              => $aheadInfo['label'],
            'hp'                 => round($n * $pace->healthCostPerCell($this->healthCostPerCell(), $profile), 2),
            'tired'              => round($n * $pace->tiredCostPerCell($this->tiredCostPerCell(), $profile), 2),
            'eta_minutes'        => $breakdown['total_minutes'],
            'pedestrian_minutes' => $breakdown['pedestrian_minutes'],
            'breakdown_line'     => self::breakdownLine($breakdown, $breakdown['pedestrian_minutes']),
            'hook'               => self::vehicleHookBlock($level, $requiredLevel, $activeVehicle, $vehicleEnabled),
        ];
    }

    /**
     * Потолок заказа из профиля транспорта, клэмп `$n` и строка потолка — одной функцией, чтобы
     * клэмп и число, показанное игроку, не могли разойтись (ревью story 03 `chat-requests-batch`).
     *
     * @param array<string,mixed> $profile профиль транспорта
     *
     * @return array{n:int,cap:int,capLine:string}
     */
    public static function clampOrderToCap(int $n, array $profile): array
    {
        $cap = max(1, self::int($profile['max_steps_per_order'] ?? 60, 60));
        $n   = max(1, min($n, $cap));

        return ['n' => $n, 'cap' => $cap, 'capLine' => self::capLine($cap)];
    }

    /** Потолок заказа всегда назван в тексте (feedback Max Syskov «максимум 60 клеток — баг?»). */
    public static function capLine(int $cap): string
    {
        return "Заказать можно не больше {$cap} клеток за раз (зависит от транспорта).\n\n";
    }

    /**
     * Потолок заказа для клика по клетке в вебе (тот же профиль, что у превью).
     */
    public function cap(int $characterId, string $dir): int
    {
        if (! self::isDirection($dir)) {
            return 1;
        }
        $char    = $this->character($characterId);
        $terrain = $this->aheadInfo($characterId, self::int($char['cell_number'] ?? 0), $dir)['terrain'];

        return self::clampOrderToCap(1, $this->resolveVehicleProfile($characterId, $terrain))['cap'];
    }

    /**
     * Старт Похода: строка `character_tasks` Marching (`in_work`) без `msg_*`.
     *
     * @return array{ok:bool, code:string, message:string, task_id:?int, dir:string, dir_label:string, n:int}
     */
    public function start(int $characterId, string $dir, int $n): array
    {
        $refusal = $this->refusalFor($characterId, $dir);
        if ($refusal !== null) {
            return $refusal + ['task_id' => null, 'dir' => $dir, 'dir_label' => self::DIR_LABEL[$dir] ?? '', 'n' => 0];
        }
        $char       = $this->character($characterId);
        $cellNumber = self::int($char['cell_number'] ?? 0);
        $aheadInfo  = $this->aheadInfo($characterId, $cellNumber, $dir);
        $profile    = $this->resolveVehicleProfile($characterId, $aheadInfo['terrain']);
        $n          = self::clampOrderToCap($n, $profile)['n'];

        $marchingTaskId = $this->marchingTaskId();
        if ($marchingTaskId === null) {
            return ['ok' => false, 'code' => self::NO_TASK, 'message' => 'Задача "Поход" не найдена в системе.', 'task_id' => null, 'dir' => $dir, 'dir_label' => self::DIR_LABEL[$dir], 'n' => $n];
        }

        $settings = [
            'heading'       => $dir,
            'steps_planned' => $n,
            'steps_done'    => 0,
            'started_cell'  => $cellNumber,
            'acc'           => [],
            'log'           => [],
        ];
        $start = new \DateTime();
        $end   = (clone $start)->add($this->stepDueInterval());
        $model = new CharacterTaskModel();
        $model->insert([
            'character_id'     => $characterId,
            'telegram_user_id' => is_numeric($char['telegram_user_id'] ?? null) ? (int) $char['telegram_user_id'] : null,
            'task_id'          => $marchingTaskId,
            'start_time'       => $start->format('Y-m-d H:i:s'),
            'end_time'         => $end->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode($settings),
        ]);
        $taskId = (int) $model->getInsertID();

        return [
            'ok'        => true,
            'code'      => self::STARTED,
            'message'   => '🚜 Поход начат: ' . self::DIR_LABEL[$dir] . " ×{$n}. Отряд идёт сам — прогресс в полосе сверху и на карте.",
            'task_id'   => $taskId > 0 ? $taskId : null,
            'dir'       => $dir,
            'dir_label' => self::DIR_LABEL[$dir],
            'n'         => $n,
        ];
    }

    /**
     * Подсказки после старта (one-shot): первый Поход — логика скорости; первый длинный — транспорт.
     * Сбой подсказки не ломает старт.
     */
    public function afterStart(int $characterId, int $n, int $chatId): void
    {
        try {
            (new OnboardingHintService())->maybeSendFirstMarchHint($characterId, $chatId);
        } catch (\Throwable $e) {
            log_message('error', '[MarchAction] first-march hint: ' . $e->getMessage());
        }

        // Генерик maybeSend() без гейта по уровню: длинный Поход можно запустить и до 6 ур.
        if ($n >= self::LONG_MARCH_HINT_THRESHOLD_CELLS) {
            try {
                $hintRow = $this->fetchRow('SELECT id, daily_tips_enabled FROM characters WHERE id = ? LIMIT 1', [$characterId]);
                if ($hintRow !== null) {
                    /** @var array<string, mixed> $hintCharacter */
                    $hintCharacter = [
                        'id'                 => self::int($hintRow['id'] ?? 0),
                        'daily_tips_enabled' => $hintRow['daily_tips_enabled'] ?? 1,
                    ];
                    (new OnboardingHintService())->maybeSend($hintCharacter, $chatId, OnboardingHintCatalog::FIRST_LONG_MARCH);
                }
            } catch (\Throwable $e) {
                log_message('error', '[MarchAction] first-long-march hint: ' . $e->getMessage());
            }
        }
    }

    /**
     * Бот после отправки своего сообщения Похода: тик будет редактировать его. Пишется в
     * активные строки Похода, у которых сообщения ещё нет (и в ту, что тик мог успеть породить).
     */
    public function attachMessage(int $characterId, int $chatId, int $messageId): void
    {
        $marchingTaskId = $this->marchingTaskId();
        if ($marchingTaskId === null) {
            return;
        }
        $res  = Database::connect()->query(
            "SELECT id, task_settings FROM character_tasks WHERE character_id = ? AND task_id = ? AND status IN ('in_work','paused')",
            [$characterId, $marchingTaskId]
        );
        $rows = $res instanceof BaseResult ? $res->getResultArray() : [];
        $model = new CharacterTaskModel();
        foreach ($rows as $row) {
            $s = json_decode(self::str($row['task_settings'] ?? '{}', '{}'), true);
            if (! is_array($s) || isset($s['msg_id'])) {
                continue;
            }
            $s['msg_chat_id'] = $chatId;
            $s['msg_id']      = $messageId;
            $model->update(self::int($row['id'] ?? 0), ['task_settings' => json_encode($s)]);
        }
    }

    /**
     * Продлить идущий Поход на `$n` клеток.
     *
     * @return array{ok:bool, code:string, message:string, total:int}
     */
    public function extend(int $characterId, int $n): array
    {
        $n    = max(1, $n);
        $task = $this->activeMarchTask($characterId, 'in_work');
        if ($task === null) {
            return ['ok' => false, 'code' => self::NOT_ACTIVE, 'message' => 'Поход уже завершён — продлевать нечего.', 'total' => 0];
        }
        $s = json_decode(self::str($task['task_settings'] ?? '{}', '{}'), true);
        if (! is_array($s)) {
            $s = [];
        }
        $total              = max(1, self::int($s['steps_planned'] ?? 1)) + $n;
        $s['steps_planned'] = $total;
        (new CharacterTaskModel())->update(self::int($task['id'] ?? 0), ['task_settings' => json_encode($s)]);

        return ['ok' => true, 'code' => self::EXTENDED, 'message' => "Поход продлён на {$n} клеток. Всего: {$total}.", 'total' => $total];
    }

    /**
     * Возобновить Поход на паузе.
     *
     * @return array{ok:bool, code:string, message:string}
     */
    public function resume(int $characterId): array
    {
        $task = $this->activeMarchTask($characterId, 'paused');
        if ($task === null) {
            return ['ok' => false, 'code' => self::NOT_PAUSED, 'message' => 'Походов на паузе нет.'];
        }
        $start = new \DateTime();
        $end   = (clone $start)->add($this->stepDueInterval());
        (new CharacterTaskModel())->update(self::int($task['id'] ?? 0), [
            'status'     => 'in_work',
            'start_time' => $start->format('Y-m-d H:i:s'),
            'end_time'   => $end->format('Y-m-d H:i:s'),
        ]);

        return ['ok' => true, 'code' => self::RESUMED, 'message' => 'Поход возобновлён.'];
    }

    /**
     * Остановить Поход (идущий или на паузе). Пройденные клетки остаются.
     *
     * @return array{ok:bool, code:string, message:string, steps_done:int}
     */
    public function stop(int $characterId): array
    {
        $marchingTaskId = $this->marchingTaskId();
        $task           = $marchingTaskId === null ? null : $this->fetchRow(
            "SELECT id, status, task_settings FROM character_tasks
             WHERE character_id = ? AND task_id = ? AND status IN ('in_work','paused') ORDER BY id DESC LIMIT 1",
            [$characterId, $marchingTaskId]
        );
        if ($task === null || $marchingTaskId === null) {
            return ['ok' => false, 'code' => self::NO_MARCH, 'message' => 'Активного похода нет.', 'steps_done' => 0];
        }

        // Все in_work/paused строки одним UPDATE: цепочка 1-клеточных задач и гонка с Worker'ом
        // могут оставить больше одной.
        Database::connect()->table('character_tasks')
            ->where('character_id', $characterId)
            ->where('task_id', $marchingTaskId)
            ->whereIn('status', ['in_work', 'paused'])
            ->update(['status' => 'completed', 'updated_at' => date('Y-m-d H:i:s')]);

        $s         = json_decode(self::str($task['task_settings'] ?? '{}', '{}'), true);
        $stepsDone = is_array($s) ? max(0, self::int($s['steps_done'] ?? 0)) : 0;

        return [
            'ok'         => true,
            'code'       => self::STOPPED,
            'message'    => "🚜 Поход прерван. Пройдено {$stepsDone} " . self::plural($stepsDone, 'клетку', 'клетки', 'клеток') . '. Раскрытые клетки остаются на карте.',
            'steps_done' => $stepsDone,
        ];
    }

    /**
     * Идущий или паузнутый Поход для HUD и карты; null — Похода нет. `eta` — unix-время прибытия
     * по той же разбивке маршрута, что у превью (на паузе — null).
     *
     * @return Status|null
     */
    public function status(int $characterId): ?array
    {
        $marchingTaskId = $this->marchingTaskId();
        if ($marchingTaskId === null) {
            return null;
        }
        $task = $this->fetchRow(
            "SELECT status, end_time, task_settings FROM character_tasks
             WHERE character_id = ? AND task_id = ? AND status IN ('in_work','paused') ORDER BY id DESC LIMIT 1",
            [$characterId, $marchingTaskId]
        );
        if ($task === null) {
            return null;
        }
        $s       = json_decode(self::str($task['task_settings'] ?? '{}', '{}'), true);
        $s       = is_array($s) ? $s : [];
        $heading = self::str($s['heading'] ?? '');
        $planned = max(1, self::int($s['steps_planned'] ?? 1));
        $done    = max(0, min($planned, self::int($s['steps_done'] ?? 0)));
        $status  = self::str($task['status'] ?? '');
        $reason  = $s['paused_reason'] ?? null;

        $eta = null;
        if ($status === 'in_work' && self::isDirection($heading)) {
            $char    = $this->character($characterId);
            $profile = $this->resolveVehicleProfile($characterId, MarchPaceService::TERRAIN_UNEXPLORED);
            $key     = is_string($profile['key'] ?? null) ? $profile['key'] : null;
            $minutes = $this->routeBreakdown($characterId, self::int($char['cell_number'] ?? 0), $heading, $planned - $done, $key)['total_minutes'];
            $next    = strtotime(self::str($task['end_time'] ?? ''));
            $eta     = max(time(), $next === false ? time() : $next) + $minutes * 60;
        }

        return [
            'status'        => $status,
            'heading'       => $heading,
            'heading_label' => self::DIR_LABEL[$heading] ?? $heading,
            'steps_done'    => $done,
            'steps_planned' => $planned,
            'eta'           => $eta,
            'paused_reason' => is_string($reason) ? $reason : null,
        ];
    }

    /**
     * Крючок «Транспорт» на экране Похода (story 12, ADR-174 §3, UX-DISCOVERABILITY): виден ВСЕГДА.
     * Чистая функция без БД.
     *
     * @param array{icon:string,name:string,cells_left:int}|null $active
     *
     * @return Hook
     */
    public static function vehicleHookBlock(int $characterLevel, int $requiredLevel, ?array $active, bool $vehicleEnabled = true): array
    {
        if (! $vehicleEnabled) {
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

    /**
     * Единственная арифметика ETA маршрута (Находка 1 ревью): сумма долей тика по сегментам,
     * один `ceil()` в конце.
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
     * Направление луча от игрока к клетке и расстояние по Чебышёву; null — клетка не на луче.
     *
     * @return array{dir:string, n:int}|null
     */
    public static function ray(int $dx, int $dy): ?array
    {
        if (($dx === 0 && $dy === 0) || ($dx !== 0 && $dy !== 0 && abs($dx) !== abs($dy))) {
            return null;
        }
        $unit = [$dx <=> 0, $dy <=> 0];
        foreach (self::DIRECTIONS as $dir => $vec) {
            if ($vec === $unit) {
                return ['dir' => $dir, 'n' => max(abs($dx), abs($dy))];
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Отказ до расчёта: направление, персонаж, переезд базы, эксклюзивная задача (прежние гейты).
     *
     * @return array{ok:false, code:string, message:string}|null
     */
    private function refusalFor(int $characterId, string $dir): ?array
    {
        if (! self::isDirection($dir)) {
            return ['ok' => false, 'code' => self::BAD_DIR, 'message' => 'Неизвестное направление.'];
        }
        if ($this->character($characterId) === null) {
            return ['ok' => false, 'code' => self::NO_CHARACTER, 'message' => 'Персонаж не найден.'];
        }
        foreach ((new ActiveTasksService())->getActiveTasksWithDetails($characterId) as $task) {
            if (is_array($task) && ($task['name'] ?? null) === 'BaseRelocation') {
                return ['ok' => false, 'code' => self::RELOCATION, 'message' => "Сейчас идёт Планируемый переезд базы.\nПока эта задача активна, это действие недоступно!"];
            }
        }
        $busy = $this->fetchRow(
            'SELECT t.name_rus, ct.end_time FROM character_tasks ct JOIN tasks t ON t.id = ct.task_id'
            . " WHERE ct.character_id = ? AND ct.status = 'in_work' AND t.parallel_execution_allowed = 0 ORDER BY ct.id LIMIT 1",
            [$characterId]
        );
        if ($busy !== null) {
            $end  = strtotime(self::str($busy['end_time'] ?? ''));
            $left = $end === false ? 0 : max(0, intdiv($end - time(), 60));

            return ['ok' => false, 'code' => self::BUSY, 'message' => 'Вы уже заняты задачей «' . self::str($busy['name_rus'] ?? '') . "» — до конца ещё {$left} мин. Сначала дождитесь окончания."];
        }

        return null;
    }

    /** @return Preview */
    private static function previewRefusal(string $code, string $message, string $dir): array
    {
        return [
            'ok' => false, 'code' => $code, 'message' => $message, 'dir' => $dir, 'dir_label' => self::DIR_LABEL[$dir] ?? '',
            'n' => 0, 'cap' => 0, 'cap_line' => '', 'ahead' => '', 'hp' => 0.0, 'tired' => 0.0, 'eta_minutes' => 0,
            'pedestrian_minutes' => 0, 'breakdown_line' => '', 'hook' => null,
        ];
    }

    /** @return array<int|string, mixed>|null */
    private function character(int $characterId): ?array
    {
        return $this->fetchRow('SELECT id, telegram_user_id, cell_number, level FROM characters WHERE id = ? LIMIT 1', [$characterId]);
    }

    /**
     * Интервал «созревания» шага марша: `world.march.minutes_per_cell − 1` мин (см.
     * {@see \App\TaskHandlers\MarchingTaskHandler::stepDueInterval()}).
     */
    private function stepDueInterval(): \DateInterval
    {
        $minutes = (new MarchPaceService())->stepDueInterval($this->minutesPerCell());

        return new \DateInterval('PT' . $minutes . 'M');
    }

    /**
     * Профиль активного транспорта для класса местности (transport-04); иначе нейтральный.
     *
     * @return array<string,mixed>
     */
    private function resolveVehicleProfile(int $characterId, string $terrain): array
    {
        $effects = new VehicleEffectsService();
        if (! $effects->isEnabled()) {
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

    /** Требуемый уровень общего транспорта — из рецепта `LightCart` (единый источник с крафт-витриной). */
    private static function vehicleRequiredLevel(): int
    {
        $recipe = (new CraftRecipes())->get('LightCart');

        return is_numeric($recipe['required_level'] ?? null) ? (int) $recipe['required_level'] : 6;
    }

    /**
     * Активная машина для крючка: имя/иконка — `Config\CraftRecipes`, остаток — GameSettings
     * `world.vehicle.<key>.{charges_full,wear_per_cell}`. null — машины нет.
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
        $wearPerCell = $vehicleKey !== null ? $this->gsInt("world.vehicle.{$vehicleKey}.wear_per_cell", 1) : 1;
        $cellsLeft   = $wearPerCell > 0 ? intdiv($resolved['charges'], $wearPerCell) : $resolved['charges'];

        $recipe = (new CraftRecipes())->findByItemNameEng($resolved['key']);
        $icon   = is_string($recipe['icon_emoji'] ?? null) ? $recipe['icon_emoji'] : '🚚';
        $name   = is_string($recipe['item_name_rus'] ?? null) ? $recipe['item_name_rus'] : 'Транспорт';

        return ['icon' => $icon, 'name' => $name, 'cells_left' => max(0, $cellsLeft)];
    }

    // ── march-баланс: `world.march.*` (ADR-024), fallback'и синхронны с MarchingTaskHandler.

    private function minutesPerCell(): int
    {
        return max(1, $this->gsInt('world.march.minutes_per_cell', 1));
    }

    private function healthCostPerCell(): float
    {
        return $this->gsFloat('world.march.health_cost_per_cell', 0.02);
    }

    private function tiredCostPerCell(): float
    {
        return $this->gsFloat('world.march.tired_cost_per_cell', 0.5);
    }

    /**
     * Клетка впереди: подпись и класс местности для профиля транспорта (E5/story-04).
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
        $nx        = self::int($cell['coordinate_x'] ?? 0) + $dx;
        $ny        = self::int($cell['coordinate_y'] ?? 0) + $dy;
        // Границы мира — реальная сетка 0..999, тот же контур, что у MarchingTaskHandler.
        if ($nx < 0 || $nx > 999 || $ny < 0 || $ny > 999) {
            return ['label' => 'край мира', 'terrain' => MarchPaceService::TERRAIN_UNEXPLORED];
        }
        $target = $this->fetchRow('SELECT cell_number, biome_id FROM map WHERE coordinate_x = ? AND coordinate_y = ? LIMIT 1', [$nx, $ny]);
        if ($target === null) {
            return ['label' => 'туман', 'terrain' => MarchPaceService::TERRAIN_UNEXPLORED];
        }
        $biome   = $this->fetchRow('SELECT name FROM biomes WHERE id = ? LIMIT 1', [self::int($target['biome_id'] ?? 0)]);
        $terrain = $biome !== null && self::isColdBiome(self::str($biome['name'] ?? ''))
            ? MarchPaceService::TERRAIN_COLD
            : MarchPaceService::TERRAIN_UNEXPLORED;
        $explored = $this->fetchRow(
            'SELECT id FROM explored_cells WHERE character_id = ? AND map_cell_id = ? LIMIT 1',
            [$characterId, self::int($target['cell_number'] ?? 0)]
        );
        if ($explored === null) {
            return ['label' => 'туман', 'terrain' => $terrain];
        }
        if ($terrain !== MarchPaceService::TERRAIN_COLD) {
            $terrain = MarchPaceService::TERRAIN_EXPLORED;
        }
        $label = $biome !== null ? self::str($biome['name'] ?? 'неизвестно', 'неизвестно') : 'неизвестно';

        return ['label' => $label, 'terrain' => $terrain];
    }

    /** Горы/Тундра — «холодная» местность. */
    private static function isColdBiome(string $biomeName): bool
    {
        $name = mb_strtolower($biomeName);

        return str_contains($name, 'горы') || str_contains($name, 'тундра');
    }

    /**
     * Разбивка маршрута по классу местности — один batched SQL на весь заказ; единственный
     * источник ETA экрана (через {@see routeEtaMinutes()}).
     *
     * @return array{segments: array<string, array{count:int, per_tick:int}>, total_minutes:int, pedestrian_minutes:int}
     */
    private function routeBreakdown(int $characterId, int $charCellNumber, string $dir, int $n, ?string $profileKey): array
    {
        $empty = ['segments' => [], 'total_minutes' => 0, 'pedestrian_minutes' => 0];
        $start = $this->fetchRow('SELECT coordinate_x, coordinate_y FROM map WHERE cell_number = ? LIMIT 1', [$charCellNumber]);
        if ($start === null || ! self::isDirection($dir)) {
            return $empty;
        }
        [$dx, $dy] = self::DIRECTIONS[$dir];
        $x         = self::int($start['coordinate_x'] ?? 0);
        $y         = self::int($start['coordinate_y'] ?? 0);

        $selects = [];
        $bind    = [];
        for ($i = 1; $i <= $n; $i++) {
            $nx = $x + $dx * $i;
            $ny = $y + $dy * $i;
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
            if (self::isColdBiome(self::str($row['biome_name'] ?? ''))) {
                $counts[MarchPaceService::TERRAIN_COLD]++;
            } elseif (! empty($row['is_explored'])) {
                $counts[MarchPaceService::TERRAIN_EXPLORED]++;
            } else {
                $counts[MarchPaceService::TERRAIN_UNEXPLORED]++;
            }
        }

        $effects            = new VehicleEffectsService();
        $pace               = new MarchPaceService();
        $base               = max(1, $this->gsInt('world.march.cells_per_tick', 3));
        $minutes            = $this->minutesPerCell();
        $segments           = [];
        $pedestrianSegments = [];
        foreach ($counts as $terrain => $count) {
            if ($count <= 0) {
                continue;
            }
            $segments[$terrain]           = ['count' => $count, 'per_tick' => $pace->cellsPerTick($base, $effects->profileFor($profileKey, $terrain))];
            $pedestrianSegments[$terrain] = ['count' => $count, 'per_tick' => $pace->cellsPerTick($base, $effects->neutralProfile())];
        }

        return [
            'segments'           => $segments,
            'total_minutes'      => self::routeEtaMinutes($segments, $minutes),
            'pedestrian_minutes' => self::routeEtaMinutes($pedestrianSegments, $minutes),
        ];
    }

    /**
     * @param array{segments: array<string, array{count:int, per_tick:int}>, total_minutes:int, pedestrian_minutes:int} $breakdown
     */
    private static function breakdownLine(array $breakdown, int $pedestrianMinutes): string
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

    private function marchingTaskId(): ?int
    {
        $row = $this->fetchRow("SELECT id FROM tasks WHERE name = 'Marching' LIMIT 1", []);
        if ($row === null) {
            return null;
        }
        $id = self::int($row['id'] ?? 0);

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
     *
     * @return array<int|string, mixed>|null
     */
    private function fetchRow(string $sql, array $bind): ?array
    {
        $res = Database::connect()->query($sql, $bind);
        if (! $res instanceof BaseResult) {
            return null;
        }
        $rows = $res->getResultArray();
        $row  = $rows[0] ?? null;

        return is_array($row) ? $row : null;
    }

    public static function plural(int $n, string $one, string $few, string $many): string
    {
        $n  = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $many;
        }
        if ($n1 > 1 && $n1 < 5) {
            return $few;
        }
        if ($n1 === 1) {
            return $one;
        }

        return $many;
    }

    private static function int(mixed $v, int $default = 0): int
    {
        return is_numeric($v) ? (int) $v : $default;
    }

    private static function str(mixed $v, string $default = ''): string
    {
        return is_scalar($v) ? (string) $v : $default;
    }
}

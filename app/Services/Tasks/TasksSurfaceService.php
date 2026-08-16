<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Entities\CharacterEntity;
use App\Models\CharacterFactionModel;
use App\Models\CharacterTaskModel;
use App\Models\TaskModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Onboarding\PolarStarService;
use App\Services\Quest\QuestOverviewService;
use CodeIgniter\I18n\Time;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * ADR-150 Слайс 3 — единый рендер поверхности «📋 Дела».
 *
 * Собирает в ОДИН дом четыре источника целей, которые до этого жили порознь и находились
 * лишь 30% активных игроков (firehose 2026-07-09):
 *  - «что идёт прямо сейчас» — активные `character_tasks` (status=in_work). Раньше это был
 *    ТОЛЬКО slash `/tasks` (326 тапов — все командой, ни одного по кнопке: кнопки не было);
 *  - полярная звезда (ADR-139) — раньше строчка-приписка на чужих экранах (Перс/Карта);
 *  - квесты (хаб `questAndTask`, ADR-126) — были вложены в «Действия»;
 *  - задания дня (ADR-109) — вход только с карточки Перса.
 *
 * Поверхность — ТЕКСТ (media-off-нейтрально, ADR-020: весь смысл словами, картинки нет).
 * Read-only витрина: НИКАКИХ мутаций состояния (кроме ленивого `ensureAssigned` дейликов —
 * идемпотентного). Единственное мутирующее действие доступно кнопкой `finishAllTasks_*` и
 * честно назван «⛔️ Прервать» — см. {@see buildKeyboard}.
 *
 * 🔴 Урок правды текста: legacy-`/tasks` обещал «моментально снять задачу», но
 * {@see \App\Controllers\Telegram\Commands\Actions\FinishTaskAction} ставит `interrupted`,
 * отнимает характеристики и ТЕРЯЕТ награду. На верхнем уровне навигации такая формулировка
 * висеть не может — здесь она заменена честной.
 *
 * Killswitch `navigation.tasks_hub.enabled` (default OFF → dormant, всё byte-identical).
 * Модели/сервисы — конструктор-сеймы (тесты подменяют, БД на CI не нужна).
 */
class TasksSurfaceService
{
    /** Максимум активных задач в списке (Telegram-лимит текста + читабельность). */
    private const MAX_TASKS = 20;

    private CharacterTaskModel $characterTaskModel;
    private TaskModel $taskModel;
    private PolarStarService $polarStar;
    private QuestOverviewService $questOverview;

    public function __construct(
        ?CharacterTaskModel $characterTaskModel = null,
        ?TaskModel $taskModel = null,
        ?PolarStarService $polarStar = null,
        ?QuestOverviewService $questOverview = null
    ) {
        $this->characterTaskModel = $characterTaskModel ?? new CharacterTaskModel();
        $this->taskModel          = $taskModel ?? new TaskModel();
        $this->polarStar          = $polarStar ?? new PolarStarService();
        $this->questOverview      = $questOverview ?? new QuestOverviewService();
    }

    /**
     * Killswitch ADR-150 Слайс 3. false (default) — DORMANT: `/tasks` рендерит легаси-список,
     * нижнее меню и хаб «Действия» byte-identical. true — «📋 Дела» становится домом целей.
     */
    public function enabled(): bool
    {
        $raw = (new GameSettingsService())->get('navigation.tasks_hub.enabled', false);
        if (is_bool($raw)) {
            return $raw;
        }

        return is_numeric($raw) ? (int) $raw === 1 : false;
    }

    /**
     * Отправляет НОВОЕ сообщение с поверхностью «Дела» (slash `/tasks`, нижняя кнопка, текст).
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function show(int $chatId, array|CharacterEntity $character): ServerResponse
    {
        $screen = $this->buildScreen($character);

        return Request::sendMessage([
            'chat_id'                  => $chatId,
            'text'                     => $screen['text'],
            'parse_mode'               => 'Markdown',
            'disable_web_page_preview' => true,
            'reply_markup'             => json_encode($screen['keyboard']),
        ]);
    }

    /**
     * Полный экран «📋 Дела»: текст + inline-клавиатура. Чистый рендер (кроме чтения БД) —
     * переиспользуется callback-хабом (edit-in-place) и slash-командой (новое сообщение).
     *
     * @param array<string, mixed>|CharacterEntity $character
     * @return array{text: string, keyboard: array{inline_keyboard: list<list<array{text:string, callback_data:string}>>}}
     */
    public function buildScreen(array|CharacterEntity $character): array
    {
        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $level  = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 1;

        $tasks   = $this->activeTasks($charId);
        $summary = $this->questSummary($level, $charId);

        return [
            'text'     => $this->buildText($charId, $tasks, $summary),
            'keyboard' => $this->buildKeyboard($tasks, $summary),
        ];
    }

    /**
     * Активные задачи персонажа с человекочитаемым остатком времени.
     *
     * @return list<array{id:int, name:string, left:string}>
     */
    protected function activeTasks(int $charId): array
    {
        if ($charId <= 0) {
            return [];
        }

        $rows = $this->characterTaskModel
            ->where('character_id', $charId)
            ->where('status', 'in_work')
            ->findAll();

        $tasks = [];
        foreach ($rows as $row) {
            if (! is_array($row) || count($tasks) >= self::MAX_TASKS) {
                continue;
            }

            $taskInfo = is_numeric($row['task_id'] ?? null)
                ? $this->taskModel->find((int) $row['task_id'])
                : null;
            if (! is_array($taskInfo)) {
                continue;
            }

            $nameRaw = is_string($taskInfo['name_rus'] ?? null) ? $taskInfo['name_rus'] : 'Задача';
            $endRaw  = is_string($row['end_time'] ?? null) ? $row['end_time'] : null;

            $tasks[] = [
                'id'   => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                'name' => $this->markdownSafe($nameRaw),
                'left' => $this->timeLeft($endRaw),
            ];
        }

        return $tasks;
    }

    /**
     * Сводка квестов/дейликов. Дефолт-заглушка при отсутствии персонажа — экран не падает.
     *
     * @return array{active:int, available:int, locked:int, completed:int, daily:array{enabled:bool, assigned:bool, done:int, total:int, bonus:int}, branches:int, npc_hint:bool}
     */
    protected function questSummary(int $level, int $charId): array
    {
        if ($charId <= 0) {
            return [
                'active'    => 0,
                'available' => 0,
                'locked'    => 0,
                'completed' => 0,
                'daily'     => ['enabled' => false, 'assigned' => false, 'done' => 0, 'total' => 0, 'bonus' => 0],
                'branches'  => 0,
                'npc_hint'  => false,
            ];
        }

        $factionId = (new CharacterFactionModel())->getFactionId($charId);

        return $this->questOverview->summary($level, $charId, $factionId);
    }

    /** Строка полярной звезды (ADR-139) или null — свой killswitch внутри сервиса. */
    protected function polarStarLine(int $charId): ?string
    {
        return $this->polarStar->line($charId);
    }

    /**
     * Текст экрана. Самодостаточен в media-off (ADR-020): числа, состояние, что делать дальше.
     *
     * @param list<array{id:int, name:string, left:string}> $tasks
     * @param array{active:int, available:int, locked:int, completed:int, daily:array{enabled:bool, assigned:bool, done:int, total:int, bonus:int}, branches:int, npc_hint:bool} $s
     */
    protected function buildText(int $charId, array $tasks, array $s): string
    {
        $text = "📋 *Дела* — что идёт сейчас и что делать дальше.\n\n";

        // Полярная звезда — одна закреплённая цель новичка (ADR-139). Первой строкой:
        // ради неё игрок сюда и заходит («что дальше?»).
        $star = $this->polarStarLine($charId);
        if ($star !== null) {
            $text .= $star . "\n\n";
        }

        if ($tasks === []) {
            $text .= "⏳ *Идёт сейчас:* ничего.\n"
                . "_Ты свободен — можно идти, добывать или крафтить._\n\n";
        } else {
            $count = count($tasks);
            $text .= "⏳ *Идёт сейчас ({$count}):*\n";
            $i = 1;
            foreach ($tasks as $task) {
                $text .= "{$i}) *{$task['name']}* — осталось `{$task['left']}`\n";
                $i++;
            }
            $text .= "\n_Задача завершится сама — награда придёт отдельным сообщением._\n\n";
        }

        // Развилка цепочки — необратимый выбор, показываем заметно.
        if ($s['branches'] > 0) {
            $text .= "🔀 *Развилка цепочки ждёт выбор!* Открой «Квесты» — решение необратимо.\n\n";
        }

        $quests = "📜 *Квесты:* активных *{$s['active']}*, доступно *{$s['available']}*";
        if ($s['locked'] > 0) {
            $quests .= " _(🔒 ещё {$s['locked']} по цепочке)_";
        }
        $text .= $quests . "\n";

        if ($s['daily']['enabled']) {
            if ($s['daily']['assigned'] && $s['daily']['total'] > 0) {
                $done  = $s['daily']['done'];
                $total = $s['daily']['total'];
                $text .= "🗓 *Задания дня:* *{$done}/{$total}*" . ($done >= $total ? ' ✅' : '') . "\n";
                if ($done < $total && $s['daily']['bonus'] > 0) {
                    $text .= "    _За все {$total} — бонус +{$s['daily']['bonus']} золота._\n";
                }
            } else {
                $text .= "🗓 *Задания дня:* набор на сегодня уже ждёт — открой.\n";
            }
        }

        return $text;
    }

    /**
     * Клавиатура. Cap 7±2 (ADR-150): прерывание задач — один ряд цифр; далее квесты/дейлики;
     * последний ряд — обновить + выход в мир (экран не тупик).
     *
     * 🔴 «⛔️ Прервать» — честное имя: FinishTaskAction ставит `interrupted`, награда теряется.
     * Legacy-`/tasks` звал это «моментально снять задачу», что читалось как «забрать награду».
     *
     * @param list<array{id:int, name:string, left:string}> $tasks
     * @param array{active:int, available:int, locked:int, completed:int, daily:array{enabled:bool, assigned:bool, done:int, total:int, bonus:int}, branches:int, npc_hint:bool} $s
     * @return array{inline_keyboard: list<list<array{text:string, callback_data:string}>>}
     */
    protected function buildKeyboard(array $tasks, array $s): array
    {
        $rows = [];

        if ($tasks !== []) {
            $interrupt = [];
            $i         = 1;
            foreach ($tasks as $task) {
                $interrupt[] = ['text' => '⛔️ ' . $i, 'callback_data' => 'finishAllTasks_' . $task['id']];
                $i++;
            }
            foreach (array_chunk($interrupt, 5) as $chunk) {
                $rows[] = $chunk;
            }
        }

        $questRow = [['text' => "📜 Квесты ({$s['active']})", 'callback_data' => 'questAndTask']];
        if ($s['daily']['enabled']) {
            $label = '🗓 Задания дня';
            if ($s['daily']['assigned'] && $s['daily']['total'] > 0) {
                $label .= " ({$s['daily']['done']}/{$s['daily']['total']})";
            }
            $questRow[] = ['text' => $label, 'callback_data' => 'dailyTasks'];
        }
        $rows[] = $questRow;

        $rows[] = [
            ['text' => '🔄 Обновить', 'callback_data' => 'tasksHub'],
            ['text' => '🧭 Идти', 'callback_data' => 'move'],
        ];

        return ['inline_keyboard' => $rows];
    }

    /** Человекочитаемый остаток времени до `end_time`. */
    private function timeLeft(?string $endTime): string
    {
        if ($endTime === null || $endTime === '') {
            return 'вот-вот';
        }

        try {
            $diffSec = Time::parse($endTime)->getTimestamp() - Time::now()->getTimestamp();
        } catch (\Throwable $e) {
            return 'вот-вот';
        }

        if ($diffSec <= 0) {
            return 'вот-вот';
        }
        if ($diffSec < 60) {
            return 'меньше минуты';
        }

        $hours = intdiv($diffSec, 3600);
        $mins  = intdiv($diffSec % 3600, 60);

        return $hours > 0 ? "{$hours} ч {$mins} мин" : "{$mins} мин";
    }

    /**
     * Убирает markdown-метасимволы из данных БД. Непарная `*`/`_` в имени задачи иначе
     * ломает ВЕСЬ текст → Telegram 400 → тихий не-сенд (уроки legacy_markdown, per-base tax).
     */
    protected function markdownSafe(string $value): string
    {
        return trim(str_replace(['*', '_', '`', '[', ']'], '', $value));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Tasks;

/**
 * ADR-143 — единая «легенда области действия» для крафта и стройки.
 *
 * Игроки путались (жалобы Лисовского/Евгения 2026-06-28): «когда что-то
 * крафтится — можно ли заниматься другим, ходить добывать?». Ответ владельца
 * «в зависимости от типа крафта» оказался буквально точным — он зависит от
 * флага `tasks.parallel_execution_allowed`. Этот сервис превращает механику в
 * понятный игроку текст по ДВУМ осям:
 *
 *   1. ПОЗИЦИЯ (где можно начать):
 *        • крафт   → 🌍 Где угодно (запуск из любой точки; нужен лишь верстак/база
 *                    в наличии — стоять на ней не нужно, проверка по владению);
 *        • стройка → 🏠 Только на базе (GenericBuildingAction требует findActiveCell —
 *                    реально стоять на своей базе).
 *
 *   2. ЗАНЯТОСТЬ (что можно делать, пока идёт) — вычисляется ДИНАМИЧЕСКИ из флага
 *      задачи `parallel_execution_allowed`, поэтому всегда совпадает с реальным
 *      поведением, даже если админ поменяет флаг в админке (анти-seed-default-trap):
 *        • =1 → ⏳ Идёт в фоне — можно двигаться, добывать, идти в Поход, крафтить ещё;
 *        • =0 → 🔒 Займёт целиком — MoveCharacterToDirectionAction / GatherAction /
 *               MarchAction блокируют движение, добычу и Поход до конца задачи.
 *
 * Инварианты (CLAUDE.md):
 *   • media-off: весь смысл в тексте, без картинок (ADR-020).
 *   • markdown-safe: только парные `_…_`, никаких `*`/`_` внутри — Telegram legacy
 *     Markdown иначе ломает рендер (урок S5b/Sell).
 *   • read-only: сервис чистый (без DB/мутаций) — легко тестируется, badge всегда правда.
 *
 * Источник флага у каждого вызывающего разный (taskRow из БД, либо хардкод в
 * легаси StartCraft*2Action), поэтому нормализацию делает {@see isBackground()}.
 */
class ActionScopeService
{
    public const KIND_CRAFT = 'craft';
    public const KIND_BUILD = 'build';

    /**
     * Нормализует значение `parallel_execution_allowed` (БД отдаёт '1'/'0'/1/0/bool)
     * в bool «идёт в фоне». true = фоновое (не блокирует), false = занимает целиком.
     */
    public function isBackground(mixed $parallelExecutionAllowed): bool
    {
        if (is_bool($parallelExecutionAllowed)) {
            return $parallelExecutionAllowed;
        }
        if (is_numeric($parallelExecutionAllowed)) {
            return (int) $parallelExecutionAllowed === 1;
        }
        return false;
    }

    /**
     * Занятость крафта по ключу рецепта (Config\CraftRecipes) → bool «фоновое».
     * Резолвит recipe → task_name → живой `tasks.parallel_execution_allowed`.
     * Нужен для select/хаб-экранов однородного тира (T3/готовка), чтобы показать
     * РЕАЛЬНОЕ предупреждение по представительному рецепту, не хардкодя занятость.
     *
     * @param string $recipeKey ключ из Config\CraftRecipes
     * @param bool   $default   если рецепт/задача не найдены
     */
    public function isRecipeBackground(string $recipeKey, bool $default = false): bool
    {
        /** @var \Config\CraftRecipes $cfg */
        $cfg      = config('CraftRecipes');
        $recipe   = $cfg->get($recipeKey);
        $taskName = is_array($recipe) && isset($recipe['task_name']) && is_string($recipe['task_name'])
            ? $recipe['task_name']
            : '';
        if ($taskName === '') {
            return $default;
        }
        $row = (new \App\Models\TaskModel())->where('name', $taskName)->first();
        if (is_array($row) && array_key_exists('parallel_execution_allowed', $row)) {
            return $this->isBackground($row['parallel_execution_allowed']);
        }
        if (is_object($row) && isset($row->parallel_execution_allowed)) {
            return $this->isBackground($row->parallel_execution_allowed);
        }
        return $default;
    }

    /** Бейдж позиции: 🌍 Где угодно (крафт) / 🏠 Только на базе (стройка). */
    public function positionBadge(string $kind): string
    {
        return $kind === self::KIND_BUILD ? '🏠 Только на базе' : '🌍 Где угодно';
    }

    /** Бейдж занятости из флага задачи: ⏳ Идёт в фоне / 🔒 Займёт целиком. */
    public function occupancyBadge(bool $background): string
    {
        return $background ? '⏳ Идёт в фоне' : '🔒 Займёт целиком';
    }

    /**
     * Комбинированная строка-бейдж для шапки сообщения:
     * «🌍 Где угодно · ⏳ Идёт в фоне».
     */
    public function scopeLine(string $kind, bool $background): string
    {
        return $this->positionBadge($kind) . ' · ' . $this->occupancyBadge($background);
    }

    /**
     * Поясняющая фраза (курсив, markdown-safe), отвечающая на вопрос игрока
     * «можно ли заниматься другим, пока идёт».
     */
    public function reassurance(string $kind, bool $background): string
    {
        if ($kind === self::KIND_BUILD) {
            // Стройка всегда фоновая (parallel=1), но требует стоять на базе при старте.
            return '_Можешь уходить — стройка идёт сама, сообщу по готовности._';
        }

        return $background
            ? '_Можешь идти добывать, в Поход и двигаться по карте — крафт идёт сам. Сообщу, когда будет готово._'
            : '_Пока идёт — нельзя двигаться, добывать, идти в Поход и начинать второе такое же дело. Дождись окончания._';
    }

    /**
     * Готовый блок для «started»-сообщения: строка-бейдж + перенос + пояснение.
     * Вставляется отдельным абзацем в caption запуска крафта/стройки.
     */
    public function startedBlock(string $kind, bool $background): string
    {
        return $this->scopeLine($kind, $background) . "\n" . $this->reassurance($kind, $background);
    }

    /**
     * Усиленное предупреждение о занятости для хаб/select-экранов ОДНОРОДНОГО
     * тира, где все крафты с одним флагом (T3 / готовка / сезон = блокирующие).
     * Сильнее обычного legend(): прямо говорит, что нельзя делать, пока идёт,
     * чтобы предупредить ДО входа в долгий блокирующий крафт. Курсив, markdown-safe.
     * Занятость передаётся вызывающим (вычислена из живого флага представительной
     * задачи) → предупреждение не дрейфит, если админ поменяет parallel_execution_allowed.
     */
    public function occupancyWarning(bool $background): string
    {
        return $background
            ? '_⏳ Эти крафты идут в фоне — можешь спокойно уходить добывать и ходить, пока делается._'
            : '_🔒 Учти: эти крафты займут тебя целиком — пока идут, нельзя двигаться, добывать и начинать второй такой же. Запускай, когда никуда не спешишь._';
    }

    /**
     * Мини-легенда для хаб-экранов (объясняет, что значат бейджи). Курсив,
     * markdown-safe. Для крафт-хабов позиция всегда «где угодно», поэтому акцент
     * на занятости; для стройки-хаба — на «только на базе».
     */
    public function legend(string $kind): string
    {
        if ($kind === self::KIND_BUILD) {
            return '_🏠 Строить можно только стоя на своей базе. ⏳ Сама стройка идёт в фоне — можешь уходить и заниматься своим._';
        }

        return '_🌍 Крафтить можно где угодно. У каждого крафта при запуске указано: ⏳ идёт в фоне (можно отойти добывать и ходить) или 🔒 займёт целиком (жди на месте, второе такое же не начать)._';
    }

    /**
     * ADR-167 — экран отказа, когда игрок пытается начать 🔒-дело поверх другого
     * 🔒-дела.
     *
     * Почему он вообще понадобился. Флаг `parallel_execution_allowed=0` читали
     * только «полевые» действия (движение, добыча, Поход), а старты крафта и
     * ремонта — нет. Получалась асимметрия, которую игрок Анжела сформулировала
     * точно: «нельзя запустить сбор, если идёт крафт на костре, но можно
     * запустить крафт на костре, если идёт сбор». Врала при этом крафт-сторона:
     * бейдж 🔒 обещал «займёт целиком» и не выполнял обещание.
     *
     * Текст объясняет ПРАВИЛО, а не только факт отказа, — иначе игрок читает
     * блокировку как новый баг, а не как ту же логику, что уже держит добычу.
     *
     * Имена задач приходят из БД (`tasks.name_rus`), поэтому проходят через
     * {@see sanitizeName()}: Telegram legacy Markdown не понимает обратный слэш,
     * и один `_` в названии сломал бы весь рендер (урок
     * feedback_legacy_markdown_no_backslash_escape).
     *
     * @param string $blockingTaskName  что уже идёт (name_rus)
     * @param int    $minutesLeft       сколько осталось текущему делу
     * @param string $attemptedTaskName что игрок пытался начать (name_rus), опционально
     */
    public function exclusiveBlockText(string $blockingTaskName, int $minutesLeft, string $attemptedTaskName = ''): string
    {
        $blocking  = $this->sanitizeName($blockingTaskName);
        $attempted = $this->sanitizeName($attemptedTaskName);

        $text = "🔒 Два таких дела сразу не идут.\n\n"
            . "Сейчас занят: {$blocking}\n"
            . '⌛️ Осталось примерно: ' . $this->humanMinutes($minutesLeft) . "\n";

        if ($attempted !== '') {
            $text .= "\nХочешь начать: {$attempted}\n";
        }

        return $text
            . "\n_Это дело тоже займёт тебя целиком — как добыча, движение по карте и Поход. "
            . 'Дождись окончания текущего или отмени его: /tasks_';
    }

    /**
     * Остаток времени человеческой строкой: «42 мин.» / «2 ч 05 мин.» / «1 дн. 4 ч».
     * Ноль и отрицательные (задача уже отработала, воркер ещё не закрыл) —
     * «меньше минуты», иначе игрок видел бы честное, но пугающее «0 мин.»
     * рядом с отказом.
     */
    public function humanMinutes(int $minutes): string
    {
        if ($minutes <= 0) {
            return 'меньше минуты';
        }
        if ($minutes < 60) {
            return "{$minutes} мин.";
        }
        if ($minutes < 1440) {
            $h = intdiv($minutes, 60);
            $m = $minutes % 60;
            return $m > 0 ? "{$h} ч {$m} мин." : "{$h} ч";
        }
        $d = intdiv($minutes, 1440);
        $h = intdiv($minutes % 1440, 60);
        return $h > 0 ? "{$d} дн. {$h} ч" : "{$d} дн.";
    }

    /**
     * Убирает из пришедшего из БД имени символы, которые Telegram legacy Markdown
     * трактует как разметку. Не экранирует, а вырезает: обратный слэш в legacy
     * Markdown не работает, а непарный `_`/`*` рвёт всё сообщение (ok=false,
     * игрок не получает вообще ничего).
     */
    private function sanitizeName(string $name): string
    {
        return trim(str_replace(['*', '_', '`', '[', ']'], '', $name));
    }
}

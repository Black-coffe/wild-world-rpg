<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * ADR-019 Step 5 — ШИМ.
 *
 * Стоячий таймерный «explore» (`ExploreTheArea`-задача, выбор длительности 10м–12ч)
 * заменён единой механикой «Поход» — движение И есть разведка (туман войны radius-1):
 * двигаясь, выживший сам открывает клетки вокруг; в дальний поход — направление + N клеток.
 *
 * Этот handler (callback `explore` / `explore_<NN>`) больше НЕ создаёт `ExploreTheArea`-задач —
 * он просто перенаправляет на новый экран похода ({@see MarchAction}). `ExplorationTaskHandler`
 * остаётся жив для in-flight `ExploreTheArea`-задач, существовавших на момент деплоя ADR-019
 * Step 5; удаляется отдельным cleanup-тегом после их дренажа (≥12 ч).
 *
 * @deprecated ADR-019 — точка входа в разведку теперь: кнопка «🚜 Дальний поход» на экране
 *             «Переехать» (callback `march`) + сам шаг (`move_dir_*`) раскрывает 3×3.
 */
class ExploreAction extends BaseAction
{
    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
    }

    public function handle(): ServerResponse
    {
        // Перенаправляем на экран похода. MarchAction::handle() при callback_data !=
        // 'march_*' (наш `explore` / `explore_NN`) показывает экран выбора направления.
        return (new MarchAction($this->callbackQuery))->handle();
    }
}

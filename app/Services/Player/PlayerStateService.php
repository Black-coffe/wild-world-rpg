<?php

namespace App\Services\Player;

use App\Models\ClaimedCellModel;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\TaskModel;
use App\Models\CharacterTaskModel;

/**
 * Сервис для проверки состояния игрока (база, задачи и т.п.).
 *
 * Пример использования:
 *   $service = new PlayerStateService(...);
 *   if ($service->isCharacterOnBase($characterId)) {
 *       // игрок на базе
 *   }
 *   if ($service->isExploring($characterId)) {
 *       // игрок занят изучением
 *   }
 *   ...
 */
class PlayerStateService
{
    protected $claimedCellModel;
    protected $characterModel;
    protected $mapModel;
    protected $taskModel;
    protected $characterTaskModel;

    public function __construct()
    {
        // Можно передавать модели через конструктор,
        // либо создавать их здесь напрямую (как ниже).
        $this->claimedCellModel   = new ClaimedCellModel();
        $this->characterModel     = new CharacterModel();
        $this->mapModel           = new MapModel();
        $this->taskModel          = new TaskModel();
        $this->characterTaskModel = new CharacterTaskModel();
    }

    /**
     * Проверяет, стоит ли персонаж СЕЙЧАС на одной из своих активных баз.
     *
     * 🔴 Матчить надо по ТЕКУЩЕЙ клетке, а не по первой строке `claimed_cells`.
     * До 2026-08-12 здесь бралась первая активная запись (`->where('status','active')->first()`,
     * без orderBy) и её клетка сравнивалась с клеткой персонажа. В одно-базовом мире это
     * работало, но мульти-база жива (ADR-095/122): владелец двух лагерей, стоящий на ВТОРОМ,
     * получал `false` — то есть переставал считаться «дома». Цена ошибки не косметическая:
     * этот же метод резолвит состояние игрока для мировых событий
     * ({@see \App\Services\Events\EventDispatcher}, `base_idle` = 0 потерь), и обещание
     * «на базе не потеряешь» для него было бы ложью — тот самый класс расхождения обещания
     * и поведения, из-за которого 09.08 чинилась защита базы.
     *
     * Канон резолва — {@see ClaimedCellModel::findActiveCell()} (ADR-095 Фаза 1b): он ищет
     * активную запись ПО КЛЕТКЕ, где персонаж стоит, и потому одинаково верен и для одной
     * базы, и для десяти. В таблице `map` id == cell_number для всех клеток (проверено на
     * проде: 1 000 000 строк, расхождений 0), поэтому `map_cell_id` сравнивается с
     * `characters.cell_number` напрямую — лишний поход в `map` не нужен.
     *
     * @param int $characterId
     * @return bool true, если у персонажа есть активная база и он физически на ней.
     */
    public function isCharacterOnBase(int $characterId): bool
    {
        $character = $this->characterModel->find($characterId);
        if (! $character) {
            return false;
        }

        $cellNumber = $character['cell_number'] ?? null;
        if (! is_numeric($cellNumber)) {
            return false;
        }

        return $this->claimedCellModel->findActiveCell($characterId, (int) $cellNumber) !== null;
    }

    /**
     * Проверяет, находится ли персонаж в данный момент в процессе "Изучения территории" (ExploreTheArea).
     *
     * @param int $characterId
     * @return bool
     */
    public function isExploring(int $characterId): bool
    {
        // 1) Получаем ID задачи "ExploreTheArea" из tasks
        $exploreTask = $this->taskModel->where('name', 'ExploreTheArea')->first();
        if (!$exploreTask) {
            // Если такой задачи не существует, значит персонаж не может быть ею занят
            return false;
        }

        $taskId = $exploreTask['id'];

        // 2) Проверяем в character_tasks, есть ли запись с in_work
        $activeTask = $this->characterTaskModel->where([
            'character_id' => $characterId,
            'task_id'      => $taskId,
            'status'       => 'in_work',
        ])->first();

        return ($activeTask !== null);
    }

    /**
     * Проверяет, находится ли персонаж в процессе "Добычи ресурсов" (Gather).
     *
     * @param int $characterId
     * @return bool
     */
    public function isGathering(int $characterId): bool
    {
        // 1) Получаем ID задачи "Gather" из tasks
        $gatherTask = $this->taskModel->where('name', 'Gather')->first();
        if (!$gatherTask) {
            // Если такой задачи нет, значит персонаж не может её выполнять
            return false;
        }

        $taskId = $gatherTask['id'];

        // 2) Ищем запись с in_work
        $activeTask = $this->characterTaskModel->where([
            'character_id' => $characterId,
            'task_id'      => $taskId,
            'status'       => 'in_work',
        ])->first();

        return ($activeTask !== null);
    }
}
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
     * Проверяет, есть ли у персонажа активная база и находится ли он на её ячейке.
     *
     * @param int $characterId
     * @return bool true, если у персонажа есть активная база и он физически на ней.
     */
    public function isCharacterOnBase(int $characterId): bool
    {
        // 1) Ищем в claimed_cells запись по character_id со статусом 'active'
        $claimedRow = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();

        if (!$claimedRow) {
            // Нет активной базы
            return false;
        }

        // 2) Получаем ячейку, где стоит база
        //    claimedRow['map_cell_id'] => ID строки из таблицы map
        $mapCellId = $claimedRow['map_cell_id'];
        $mapRow = $this->mapModel->find($mapCellId);
        if (!$mapRow) {
            // На случай, если нет такой записи в map
            return false;
        }

        // 3) Получаем самого персонажа, чтобы узнать его cell_number
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            return false;
        }

        // 4) Сравниваем cell_number персонажа и cell_number базы
        //    Дело в том, что mapRow['cell_number'] — номер ячейки,
        //    тогда как у персонажа character['cell_number'] — тоже номер ячейки.
        return isset($mapRow['cell_number'])
            && $mapRow['cell_number'] == $character['cell_number'];
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
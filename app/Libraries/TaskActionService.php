<?php

namespace App\Libraries;

use App\Models\CharacterTaskModel;

class TaskActionService
{
    protected $characterTaskModel;

    public function __construct()
    {
        $this->characterTaskModel = new CharacterTaskModel();
    }

    public function checkActiveTaskConstraints($characterId)
    {
        $activeTasks = $this->characterTaskModel
            ->where('character_id', $characterId)
            ->where('status', 'in_work')
            ->findAll();

        if (empty($activeTasks)) {
            return [true, null];
        }

        foreach ($activeTasks as $task) {
            if (!$task['parallel_execution_allowed']) {
                return [false, $task];
            }
        }

        return [true, null];
    }
}

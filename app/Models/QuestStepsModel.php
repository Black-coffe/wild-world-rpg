<?php

namespace App\Models;

use CodeIgniter\Model;

class QuestStepsModel extends Model
{
    protected $table = 'quest_steps';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = ['quest_id', 'character_id', 'step_order', 'description', 'is_completed'];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    // Валидационные правила
    protected $validationRules = [
        'quest_id'      => 'required|is_natural_no_zero',
        'character_id'  => 'required|is_natural_no_zero',
        'step_order'    => 'required|is_natural_no_zero',
        'description'   => 'required|string',
        'is_completed'  => 'required|boolean'
    ];

    protected $validationMessages = [
        'quest_id' => [
            'required' => 'Quest ID is required.',
            'is_natural_no_zero' => 'Quest ID must be a natural number greater than zero.'
        ],
        'character_id' => [
            'required' => 'Character ID is required.',
            'is_natural_no_zero' => 'Character ID must be a natural number greater than zero.'
        ],
        'step_order' => [
            'required' => 'Step order is required.',
            'is_natural_no_zero' => 'Step order must be a natural number greater than zero.'
        ],
        'description' => [
            'required' => 'Description is required.'
        ],
        'is_completed' => [
            'required' => 'Completion status is required.',
            'boolean' => 'Completion status must be a boolean value.'
        ]
    ];

    protected $skipValidation = true;

    // Метод для получения активных шагов квеста для персонажа
    public function getAvailableQuests($characterLevel, $characterId)
    {
        // Получение всех квестов, доступных для данного уровня персонажа
        $builder = $this->builder();
        $builder->select('*')
            ->where('min_level <=', $characterLevel)
            ->whereNotIn('id', function ($builder) use ($characterId) {
                return $builder->select('quest_id')
                    ->from('quest_steps')
                    ->where('character_id', $characterId)
                    ->groupBy('quest_id');
            });
        $query = $builder->get();
        if ($query === false) {
            return [];
        }
        return $query->getResultArray();
    }

    public function getActiveQuestStepsForCharacter($characterId, $quests)
    {
        $questIds = array_column($quests, 'id');
        return $this->whereIn('character_id', [$characterId]) // Передаем $characterId как массив
        ->whereIn('quest_id', $questIds)
            ->findAll();
    }

    // Метод для завершения шага квеста
    public function completeStep($id)
    {
        return $this->update($id, ['is_completed' => true]);
    }

    /**
     * W11 (ADR-067) — сколько шагов у персонажа среди указанных квестов (любой статус).
     * Используется QuestChainService для проверки «выбор развилки уже сделан».
     * Raw db-builder (как getCompletedQuestTitles) — без model-builder-state quirk.
     *
     * @param list<int> $questIds
     */
    public function countStepsForQuests(int $characterId, array $questIds): int
    {
        if ($questIds === []) {
            return 0;
        }
        return (int) $this->db->table('quest_steps')
            ->where('character_id', $characterId)
            ->whereIn('quest_id', $questIds)
            ->countAllResults();
    }
}

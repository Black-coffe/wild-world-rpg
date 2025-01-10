<?php

namespace App\Models;

use CodeIgniter\Model;

class QuestModel extends Model
{
    protected $table = 'quests';
    protected $primaryKey = 'id';

    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = ['title_ru', 'title_en', 'description', 'status', 'reward', 'min_level', 'reward_type'];

    protected $useTimestamps = false;
    protected $createdField  = '';
    protected $updatedField  = '';
    protected $deletedField  = '';

    protected $validationRules = [
        'title_ru' => 'required|min_length[3]|max_length[255]',
        'title_en' => 'required|min_length[3]|max_length[255]',
        'status' => 'required|in_list[active,completed,available]',
        'description' => 'required',
        'reward' => 'required|numeric',
        'min_level' => 'integer',
        'reward_type' => 'max_length[255]',
    ];

    protected $validationMessages = [
        'title_ru' => [
            'required' => 'Поле Название квеста (рус.) обязательно для заполнения.',
            'min_length' => 'Минимальная длина поля Название квеста (рус.) - 3 символа.',
            'max_length' => 'Максимальная длина поля Название квеста (рус.) - 255 символов.',
        ],
        'title_en' => [
            'required' => 'Поле Название квеста (англ.) обязательно для заполнения.',
            'min_length' => 'Минимальная длина поля Название квеста (англ.) - 3 символа.',
            'max_length' => 'Максимальная длина поля Название квеста (англ.) - 255 символов.',
        ],
        'status' => [
            'required' => 'Поле Статус обязательно для выбора.',
            'in_list' => 'Недопустимое значение для поля Статус.',
        ],
        'description' => [
            'required' => 'Поле Описание обязательно для заполнения.',
        ],
        'reward' => [
            'required' => 'Поле Награда обязательно для заполнения.',
            'numeric' => 'Поле Награда должно быть числом.',
        ],
        'min_level' => [
            'integer' => 'Поле Минимальный уровень должно быть целым числом.',
        ],
        'reward_type' => [
            'max_length' => 'Максимальная длина поля Тип награды - 255 символов.',
        ],
    ];

    protected $skipValidation = false;

    /**
     * Retrieves available quests based on the character's level.
     *
     * @param int $characterLevel The level of the character.
     * @return array The list of quests available for the character's level.
     */
    public function getAvailableQuests(int $characterLevel): array
    {
        return $this->asArray()
            ->where('status', 'active')
            ->where('min_level <=', $characterLevel)
            ->findAll();
    }
}

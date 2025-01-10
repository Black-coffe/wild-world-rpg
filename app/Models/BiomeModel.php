<?php

namespace App\Models;

use CodeIgniter\Model;

class BiomeModel extends Model
{
    protected $table = 'biomes';
    protected $primaryKey = 'id';
    protected $allowedFields = ['name', 'description', 'biome_type', 'danger_level_text', 'danger_level', 'survival_difficulty_text', 'survival_difficulty', 'occurrence_rate', 'created_at', 'updated_at'];
    protected $useTimestamps = true;
    protected $returnType = 'array'; // Можно использовать 'object' если вам удобнее работать с объектами

    // Валидация данных
    protected $validationRules = [
        'name' => 'required|max_length[100]',
        'description' => 'required',
        'biome_type' => 'required|in_list[hot,cold,wet,dry,volcanic,cave,jungle,desert,plain]', // Правила валидации для нового поля
        'danger_level_text' => 'permit_empty|max_length[100]',
        'danger_level' => 'permit_empty|integer|greater_than_equal_to[1]|less_than_equal_to[10]',
        'survival_difficulty_text' => 'permit_empty|max_length[100]',
        'survival_difficulty' => 'permit_empty|integer|greater_than_equal_to[1]|less_than_equal_to[10]',
        'occurrence_rate' => 'required|decimal|max_length[5]',
    ];

    protected $validationMessages = [
        'name' => [
            'required' => 'Поле "Название" является обязательным.',
            'max_length' => 'Поле "Название" не может содержать более 100 символов.',
        ],
        'description' => [
            'required' => 'Поле "Описание" является обязательным.',
        ],
        'biome_type' => [
            'required' => 'Поле "Тип биома" является обязательным.',
            'in_list' => 'Поле "Тип биома" содержит недопустимое значение.',
        ],
        'occurrence_rate' => [
            'required' => 'Поле "Частота встречаемости" является обязательным.',
            'decimal' => 'Поле "Частота встречаемости" должно быть числом с плавающей запятой.',
            'max_length' => 'Поле "Частота встречаемости" не может содержать более 5 символов.',
        ],
    ];

    // Callback валидации для проверки допустимости уровня опасности
    protected $validationCallbacks = [
        'beforeInsert' => 'validateDangerLevel',
        'beforeUpdate' => 'validateDangerLevel',
    ];

    protected function validateDangerLevel(array $data): bool
    {
        if (isset($data['danger_level'])) {
            if ($data['danger_level'] < 1 || $data['danger_level'] > 10) {
                $this->validationErrors['danger_level'] = 'Уровень опасности должен быть целым числом от 1 до 10.';
                return false;
            }
        }

        return true;
    }

    // Метод для получения названия биома по его ID
    public function getBiomeNameById($id)
    {
        $biome = $this->find($id);
        return $biome ? $biome['name'] : null;
    }
}

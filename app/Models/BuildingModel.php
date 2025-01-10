<?php

namespace App\Models;

use CodeIgniter\Model;

class BuildingModel extends Model
{
    protected $table = 'buildings';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'name_ru',
        'name_en',
        'description',
        'building_type',
        'hp',
        'construction_time',
        'tax',
        'level',
        'usage',
        'required_resources',
        'min_character_level',
        'effects',
        'days_until_disappearance',
        'usage_count'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';

    protected $validationRules = [
        'name_ru' => 'required|string|max_length[255]',
        'name_en' => 'required|string|max_length[255]',
        'description' => 'permit_empty|string',
        'building_type' => 'required|in_list[military,residential,farming,resource,engineering]',
        'hp' => 'required|integer',
        'construction_time' => 'required|integer',
        'tax' => 'required|integer',
        'level' => 'required|integer|in_list[1,2,3]',
        'usage' => 'required|in_list[personal,collective,all]',
        'required_resources' => 'permit_empty|string',
        'min_character_level' => 'required|integer',
        'effects' => 'permit_empty|string',
        'days_until_disappearance' => 'required|integer',
        'usage_count' => 'permit_empty|integer|max_length[1000000]'
    ];

    protected $validationMessages = [
        'name_ru' => [
            'required' => 'Russian name is required.',
            'string' => 'Russian name must be a string.',
            'max_length' => 'Russian name cannot exceed 255 characters.'
        ],
        'name_en' => [
            'required' => 'English name is required.',
            'string' => 'English name must be a string.',
            'max_length' => 'English name cannot exceed 255 characters.'
        ],
        'building_type' => [
            'required' => 'Building type is required.',
            'in_list' => 'Building type must be one of: military, residential, farming, resource, engineering.'
        ],
        'hp' => [
            'required' => 'HP is required.',
            'integer' => 'HP must be an integer.'
        ],
        'construction_time' => [
            'required' => 'Construction time is required.',
            'integer' => 'Construction time must be an integer.'
        ],
        'tax' => [
            'required' => 'Tax is required.',
            'integer' => 'Tax must be an integer.'
        ],
        'level' => [
            'required' => 'Level is required.',
            'integer' => 'Level must be an integer.',
            'in_list' => 'Level must be one of: 1, 2, 3.'
        ],
        'usage' => [
            'required' => 'Usage is required.',
            'in_list' => 'Usage must be one of: personal, collective, all.'
        ],
        'min_character_level' => [
            'required' => 'Minimum character level is required.',
            'integer' => 'Minimum character level must be an integer.'
        ],
        'days_until_disappearance' => [
            'required' => 'Days until disappearance is required.',
            'integer' => 'Days until disappearance must be an integer.'
        ],
        'usage_count' => [
            'integer' => 'Usage count must be an integer.',
            'max_length' => 'Usage count cannot exceed 1000000.'
        ]
    ];

    protected $skipValidation = false;
}

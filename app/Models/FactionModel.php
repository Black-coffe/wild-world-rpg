<?php

namespace App\Models;

use CodeIgniter\Model;

class FactionModel extends Model
{
    protected $table = 'factions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'name',
        'description',
        'type',
        'unique_ability',
        'created_at',
        'updated_at'
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = '';

    protected $validationRules = [
        'name'           => 'required|max_length[100]',
        'description'    => 'permit_empty',
        'type'           => 'required|in_list[PVP,PVE]',
        'unique_ability' => 'permit_empty'
    ];

    protected $validationMessages = [
        'name' => [
            'required' => 'Поле Название обязательно для заполнения.',
            'max_length' => 'Название не может превышать 100 символов.'
        ],
        'type' => [
            'required' => 'Поле Тип обязательно для заполнения.',
            'in_list' => 'Тип должен быть либо PVP, либо PVE.'
        ]
    ];

    protected $skipValidation = false;
}

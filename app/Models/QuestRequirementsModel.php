<?php

namespace App\Models;

use CodeIgniter\Model;

class QuestRequirementsModel extends Model
{
    protected $table = 'quest_requirements';
    protected $primaryKey = 'id';
    protected $allowedFields = ['quest_id', 'requirement'];

    protected $validationRules    = [
        'quest_id' => 'required|integer',
        'requirement' => 'required|string'
    ];

    protected $validationMessages = [
        'quest_id' => [
            'required' => 'Quest ID is required.',
            'integer' => 'Quest ID must be an integer.'
        ],
        'requirement' => [
            'required' => 'Requirement is required.',
            'string' => 'Requirement must be a string.'
        ]
    ];
}

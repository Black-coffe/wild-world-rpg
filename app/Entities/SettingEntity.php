<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class SettingEntity extends Entity
{
    // Здесь вы можете определить свойства и методы для работы с сущностью "Setting"

    protected $casts = [
        'id' => 'int',
    ];
}

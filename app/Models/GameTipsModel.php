<?php

namespace App\Models;

use CodeIgniter\Model;

class GameTipsModel extends Model
{
    protected $table = 'game_tips';
    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = ['title_ru', 'title_en', 'tip_type', 'content', 'created_at', 'updated_at'];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';  // Добавьте это поле в таблицу, если используете soft deletes

    protected $validationRules = [
        'title_ru' => 'required|max_length[255]',
        'title_en' => 'required|max_length[255]',
        'tip_type' => 'required|in_list[биомы,ресурсы,крафт,персонаж,события,NPC,общие,земледелие,еда,квесты,фракции,бой,эндгейм,настройки]',
        'content' => 'required'
    ];

    protected $validationMessages = [];
    protected $skipValidation     = false;
}


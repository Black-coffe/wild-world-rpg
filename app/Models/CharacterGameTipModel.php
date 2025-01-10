<?php

namespace App\Models;

use CodeIgniter\Model;

class CharacterGameTipModel extends Model
{
    protected $table = 'character_game_tips';
    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = ['character_id', 'game_tip_id', 'viewed_at'];

    protected $useTimestamps = false;
    // Если вы хотите использовать метки времени для этой таблицы,
    // установите значение $useTimestamps в true и настройте $createdField и $updatedField соответственно.

    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at'; // Если используете мягкое удаление, добавьте это поле в таблицу.

    // Валидационные правила могут быть добавлены здесь, если необходимо.

    // Пример метода для получения всех советов, просмотренных персонажем
    public function getCharacterViewedTips(int $characterId)
    {
        return $this->where('character_id', $characterId)
            ->whereNotNull('viewed_at')
            ->findAll();
    }

    // Пример метода для проверки просмотра конкретного совета персонажем
    public function isTipViewed(int $characterId, int $tipId): bool
    {
        $tip = $this->where('character_id', $characterId)
            ->where('game_tip_id', $tipId)
            ->first();

        return $tip !== null && $tip['viewed_at'] !== null;
    }

    // Пример метода для отметки совета как просмотренного
    public function markTipAsViewed(int $characterId, int $tipId): bool
    {
        $tip = $this->where('character_id', $characterId)
            ->where('game_tip_id', $tipId)
            ->first();

        if ($tip !== null) {
            return $this->update($tip['id'], ['viewed_at' => date('Y-m-d H:i:s')]);
        }

        return false;
    }
}

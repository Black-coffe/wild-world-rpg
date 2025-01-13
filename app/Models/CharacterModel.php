<?php namespace App\Models;

use CodeIgniter\Model;
use App\Models\ExploredCellsModel;

class CharacterModel extends Model
{
    protected $table = 'characters';
    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;
    protected $returnType = 'array';

    /**
     * Поля, которые разрешено массово заполнять
     * (в т.ч. при использовании ->update() или ->save() через модель).
     */
    protected $allowedFields = [
        'telegram_user_id',
        'name',
        'level',
        'experience',
        'health',
        'tired',
        'strength',
        'agility',
        'intellect',
        'gold',
        'cell_number',
        'biome_id',
        'trading_karma',
        'insurance',
        'preferred_map_type',
        'has_renamed',        // Новое поле
        'last_name_change',   // Новое поле
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Возвращает строку с информацией о персонаже.
     */
    public function getCharacterInfoAsString($characterId)
    {
        $character = $this->find($characterId);

        if (!$character) {
            return "Персонаж не найден.";
        }

        $info = "Информация о Персонаже:\n";
        $info .= "Имя: " . $character['name'] . "\n";
        $info .= "Уровень: " . $character['level'] . "\n";
        $info .= "Опыт: " . $character['experience'] . "\n";
        $info .= "Здоровье: " . $character['health'] . "\n";
        $info .= "Усталость: " . $character['tired'] . "\n";
        $info .= "Сила: " . $character['strength'] . "\n";
        $info .= "Ловкость: " . $character['agility'] . "\n";
        $info .= "Интеллект: " . $character['intellect'] . "\n";
        $info .= "Золото: " . $character['gold'] . "\n";
        $info .= "Номер ячейки: " . $character['cell_number'] . "\n";
        if (isset($character['biome_id'])) {
            $info .= "ID биома: " . $character['biome_id'] . "\n";
        }
        if (isset($character['trading_karma'])) {
            $info .= "Карма торговли: " . $character['trading_karma'] . "\n";
        }
        if (isset($character['preferred_map_type'])) {
            $info .= "Тип карты: " . $character['preferred_map_type'] . "\n";
        }
        // При желании можно вывести и поля про has_renamed / last_name_change.

        return $info;
    }

    public function decreaseGold(int $characterId, float $amount): bool
    {
        $amount = (int) round($amount);
        $character = $this->find($characterId);

        if (!$character || $character['gold'] < $amount) {
            log_message('error', "Проблема с персонажем с ID $characterId при попытке списать золото.");
            return false;
        }

        $newGoldAmount = $character['gold'] - $amount;
        return $this->update($characterId, ['gold' => $newGoldAmount]);
    }

    public function increaseGold(int $characterId, float $amount): bool
    {
        $amount = (int) round($amount);
        $character = $this->find($characterId);

        if (!$character) {
            log_message('error', "Персонаж с ID $characterId не найден при попытке увеличения золота.");
            return false;
        }

        $newGoldAmount = $character['gold'] + $amount;
        return $this->update($characterId, ['gold' => $newGoldAmount]);
    }

    // Ниже — дополнительные методы.

    public function getCurrentLocation(int $characterId)
    {
        $character = $this->find($characterId);
        return $character ? $character['cell_number'] : null;
    }

    public function getNeighboringCells(int $currentCellNumber)
    {
        // Предполагается, что есть MapModel
        $mapModel = new MapModel();
        return $mapModel->getNeighboringCells($currentCellNumber);
    }

    public function exploreCells(int $characterId, array $neighboringCells)
    {
        $exploredCellsModel = new ExploredCellsModel();
        foreach ($neighboringCells as $cell) {
            $exploredCellsModel->save([
                'character_id' => $characterId,
                'map_cell_id' => $cell['cell_number'],
                'explored_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function getCharacterIdByTelegramId(int $telegramId): ?int
    {
        $this->select('characters.id');
        $this->join('telegram_users', 'telegram_users.id = characters.telegram_user_id');
        $this->where('telegram_users.telegram_id', $telegramId);
        $character = $this->first();

        return $character ? (int) $character['id'] : null;
    }

    public function getCharacterByTelegramId(int $telegramId): ?array
    {
        $this->select('characters.*');
        $this->join('telegram_users', 'telegram_users.id = characters.telegram_user_id');
        $this->where('telegram_users.telegram_id', $telegramId);
        return $this->first();
    }

    /**
     * Пример обновления ловкости и интеллекта.
     */
    public function updateAgilityAndIntellect(int $characterId, float $agilityIncrement, float $intellectIncrement): bool
    {
        $character = $this->find($characterId);
        if (!$character) {
            log_message('error', "Персонаж с ID $characterId не найден при попытке обновления ловкости и интеллекта.");
            return false;
        }

        $newAgility = round($character['agility'] + $agilityIncrement, 2);
        $newIntellect = round($character['intellect'] + $intellectIncrement, 2);

        return $this->update($characterId, [
            'agility'   => $newAgility,
            'intellect' => $newIntellect
        ]);
    }
}

<?php namespace App\Models;

use CodeIgniter\Model;
use App\Models\ExploredCellsModel;

class CharacterModel extends Model
{
    protected $table = 'characters';
    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;
    protected $returnType = 'array';
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
        // Новое поле для выбора типа карты
        'preferred_map_type',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

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
        // Добавление информации о биоме
        if (isset($character['biome_id'])) {
            $info .= "ID биома: " . $character['biome_id'] . "\n";
        }
        // Карма торговли
        if (isset($character['trading_karma'])) {
            $info .= "Карма торговли: " . $character['trading_karma'] . "\n";
        }
        // Текущий тип карты
        if (isset($character['preferred_map_type'])) {
            $info .= "Тип карты: " . $character['preferred_map_type'] . "\n";
        }

        return $info;
    }

    public function decreaseGold(int $characterId, float $amount): bool
    {
        // Преобразуем к целому числу после округления
        $amount = (int) round($amount);

        $character = $this->find($characterId);

        if (!$character || $character['gold'] < $amount) {
            log_message('error', "Проблема с персонажем с ID $characterId при попытке списать золото.");
            return false;
        }

        $newGoldAmount = $character['gold'] - $amount;
        return $this->update($characterId, ['gold' => $newGoldAmount]);
    }

    /**
     * Получает текущую ячейку персонажа по его ID.
     */
    public function getCurrentLocation(int $characterId)
    {
        $character = $this->find($characterId);
        return $character ? $character['cell_number'] : null;
    }

    /**
     * Получает информацию о соседних ячейках вокруг заданной ячейки.
     */
    public function getNeighboringCells(int $currentCellNumber)
    {
        $mapModel = new MapModel();
        return $mapModel->getNeighboringCells($currentCellNumber);
    }

    /**
     * Регистрирует ячейки, которые персонаж исследовал.
     */
    public function exploreCells(int $characterId, array $neighboringCells)
    {
        $exploredCellsModel = new ExploredCellsModel();
        foreach ($neighboringCells as $cell) {
            $exploredCellsModel->save([
                'character_id' => $characterId,
                'map_cell_id' => $cell['cell_number'],
                'explored_at' => date('Y-m-d H:i:s'), // Отметка времени исследования
            ]);
        }
    }

    /**
     * Получает ID персонажа по ID Telegram пользователя.
     *
     * @param int $telegramId ID пользователя в Telegram.
     * @return int|null Возвращает ID персонажа или null, если персонаж не найден.
     */
    public function getCharacterIdByTelegramId(int $telegramId): ?int
    {
        $this->select('characters.id');
        $this->join('telegram_users', 'telegram_users.id = characters.telegram_user_id');
        $this->where('telegram_users.telegram_id', $telegramId);
        $character = $this->first();

        return $character ? (int) $character['id'] : null;
    }

    /**
     * Получает информацию о персонаже по ID пользователя Telegram.
     *
     * @param int $telegramId ID пользователя в Telegram.
     * @return array|null Возвращает массив данных персонажа или null, если персонаж не найден.
     */
    public function getCharacterByTelegramId(int $telegramId): ?array
    {
        $this->select('characters.*');
        $this->join('telegram_users', 'telegram_users.id = characters.telegram_user_id');
        $this->where('telegram_users.telegram_id', $telegramId);
        $character = $this->first();

        return $character;
    }

    public function increaseGold(int $characterId, float $amount): bool
    {
        // Преобразуем к целому числу после округления
        $amount = (int) round($amount);

        $character = $this->find($characterId);

        if (!$character) {
            log_message('error', "Персонаж с ID $characterId не найден при попытке увеличения золота.");
            return false;
        }

        $newGoldAmount = $character['gold'] + $amount;
        return $this->update($characterId, ['gold' => $newGoldAmount]);
    }

    /**
     * Обновляет статистику ловкости и интеллекта персонажа после крафта.
     *
     * @param int $characterId ID персонажа.
     * @param float $agilityIncrement Количество очков ловкости для добавления.
     * @param float $intellectIncrement Количество очков интеллекта для добавления.
     * @return bool Возвращает true, если обновление прошло успешно, иначе false.
     */
    public function updateAgilityAndIntellect(int $characterId, float $agilityIncrement, float $intellectIncrement): bool
    {
        $character = $this->find($characterId);
        if (!$character) {
            log_message('error', "Персонаж с ID $characterId не найден при попытке обновления ловкости и интеллекта.");
            return false;
        }

        // Обновление ловкости и интеллекта
        $newAgility = $character['agility'] + $agilityIncrement;
        $newIntellect = $character['intellect'] + $intellectIncrement;

        // Округление до двух десятичных знаков
        $newAgility = round($newAgility, 2);
        $newIntellect = round($newIntellect, 2);

        return $this->update($characterId, [
            'agility' => $newAgility,
            'intellect' => $newIntellect
        ]);
    }
}

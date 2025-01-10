<?php

namespace App\Models;

use CodeIgniter\Model;

class CharacterResourceModel extends Model
{
    protected $table = 'character_resources'; // Укажите здесь название вашей таблицы связей
    protected $primaryKey = 'id'; // Первичный ключ таблицы

    protected $useAutoIncrement = true; // Использовать автоинкремент для первичного ключа
    protected $returnType = 'array'; // Тип возвращаемых данных из таблицы

    protected $allowedFields = [
        'id_characters', // Вместо character_id
        'id_resources', // Вместо resource_id
        'quantity',
        'created_at',
        'updated_at'
    ];


    protected $useTimestamps = true; // Включение автоматической установки меток времени
    protected $createdField  = 'created_at'; // Поле для метки времени создания записи
    protected $updatedField  = 'updated_at'; // Поле для метки времени обновления записи
    protected $dateFormat    = 'datetime'; // Формат даты и времени

    protected $validationRules    = []; // Правила валидации данных
    protected $validationMessages = []; // Сообщения валидации
    protected $skipValidation     = false; // Не использовать валидацию по умолчанию

    public function increaseResources($characterId, $resourceId, $amount)
    {
        $existingResource = $this->where([
            'id_characters' => $characterId,
            'id_resources' => $resourceId
        ])->first();

        if ($existingResource) {
            // Если запись с ресурсом существует, увеличиваем количество
            $newQuantity = $existingResource['quantity'] + $amount;
            return $this->update($existingResource['id'], ['quantity' => $newQuantity]);
        } else {
            // Если запись не найдена, создаём новую
            return $this->insert([
                'id_characters' => $characterId,
                'id_resources' => $resourceId,
                'quantity' => $amount
            ]);
        }
    }

    public function decreaseResources($characterId, $resourceId, $amount)
    {
        $existingResource = $this->where([
            'id_characters' => $characterId,
            'id_resources' => $resourceId
        ])->first();

        if ($existingResource) {
            $newQuantity = $existingResource['quantity'] - $amount;

            if ($newQuantity <= 0) {
                // Если количество ресурса становится меньше или равно нулю, можно удалить запись
                return $this->delete($existingResource['id']);
            } else {
                // Иначе обновляем количество ресурса
                return $this->update($existingResource['id'], ['quantity' => $newQuantity]);
            }
        } else {
            // Если запись не найдена, вероятно, это ошибка в логике программы
            // Возвращаем ошибку или логируем ситуацию
            log_message('error', "Attempt to decrease non-existing resource for character $characterId and resource $resourceId");
            return false;
        }
    }

    public function getTotalResourcesByRarity($characterId, $resourceRarity)
    {
        // Сначала нужно присоединить таблицу ресурсов к таблице ресурсов персонажа,
        // чтобы можно было фильтровать по редкости ресурса. Для этого нужна связь
        // между этими таблицами по id ресурса.
        // Предполагается, что в таблице ресурсов есть колонка `rarity`, указывающая на редкость ресурса.

        $db = \Config\Database::connect();
        $builder = $db->table($this->table);
        $builder->select('character_resources.id_characters, SUM(character_resources.quantity) as total_quantity');
        $builder->join('resources', 'character_resources.id_resources = resources.id', 'inner');
        $builder->where('character_resources.id_characters', $characterId);
        $builder->where('resources.rarity', $resourceRarity);
        $builder->groupBy('character_resources.id_characters');

        $query = $builder->get();

        // Возвращаем результат запроса. Если ресурсов нет, вернется null или 0.
        // Этот код возвращает общее количество ресурсов указанной редкости у персонажа.
        $result = $query->getRow();

        return $result ? (int)$result->total_quantity : 0;
    }

    public function addOrIncreaseResource($characterId, $resourceId, $amount)
    {
        $existingResource = $this->where([
            'id_characters' => $characterId,
            'id_resources' => $resourceId
        ])->first();

        if ($existingResource) {
            // Увеличиваем количество существующего ресурса
            return $this->update($existingResource['id'], [
                'quantity' => $existingResource['quantity'] + $amount,
            ]);
        } else {
            // Создаем новую запись с ресурсом
            return $this->insert([
                'id_characters' => $characterId,
                'id_resources' => $resourceId,
                'quantity' => $amount,
            ]);
        }
    }

    /**
     * Получение ресурса по имени и ID персонажа.
     *
     * @param string $resourceName Имя ресурса.
     * @param int $characterId ID персонажа.
     * @return array|null Данные ресурса или null, если не найден.
     */
    public function getResourceByNameAndCharacterId($resourceName, $characterId)
    {
        // Здесь должен быть ваш код для поиска ресурса по имени и ID персонажа.
        // Это пример запроса, который можно использовать:
        return $this->select('character_resources.*, resources.*')
            ->join('resources', 'resources.id = character_resources.id_resources')
            ->where('resources.name', $resourceName)
            ->where('character_resources.id_characters', $characterId)
            ->first();
    }

    /**
     * Списание ресурсов с учетом имеющегося количества.
     *
     * @param string $resourceName Имя ресурса.
     * @param int $characterId ID персонажа.
     * @param int $amount Количество для списания.
     * @return bool True, если списание прошло успешно, иначе false.
     * @throws \ReflectionException
     */
    public function deductResource($resourceName, $characterId, $amount)
    {
        $this->db->transStart(); // Начало транзакции
        $resource = $this->getResourceByNameAndCharacterId($resourceName, $characterId);

        if ($resource && $resource['quantity'] >= $amount) {
            $newQuantity = $resource['quantity'] - $amount;

            // Преобразовать ID в int, если необходимо
            if (!is_int($resource['id'])) {
                $resourceId = (int) $resource['id'];
            } else {
                $resourceId = $resource['id'];
            }

            $affectedRows = $this->update($resourceId, ['quantity' => $newQuantity]);

            if ($affectedRows > 0) {
                log_message('info', "Ресурс успешно списан: $resourceName, ID: $characterId, новое количество: $newQuantity");
                return true;
            } else {
                log_message('error', "Ошибка обновления количества ресурса: $resourceName, ID: $characterId");
                return false;
            }
        }
        log_message('error', "Недостаточно ресурса для списания: $resourceName, ID: $characterId, требуется: $amount, наличие: " . ($resource ? $resource['quantity'] : '0'));
        $this->db->transComplete(); // Фиксация транзакции
        return false;
    }

    public function getResourceByCharacterAndResourceId($characterId, $resourceId)
    {
        return $this->where('character_id', $characterId)
            ->where('resource_id', $resourceId)
            ->first();
    }
}


<?php

namespace App\Models;

use CodeIgniter\Model;

class ResourcesBankModel extends Model
{
    protected $table = 'resources_bank'; // Указываем имя таблицы
    protected $primaryKey = 'id'; // Указываем первичный ключ

    protected $useAutoIncrement = true; // Используем автоинкремент для первичного ключа
    protected $returnType = 'array'; // Тип возвращаемых данных (можно использовать 'object')

    protected $allowedFields = [
        'resource_id',
        'current_quantity',
        'resources_purchased',
        'resources_sold',
        'last_update',
    ]; // Поля, разрешенные для массового назначения

    protected $useTimestamps = false; // Если true, модель будет автоматически заполнять поля created_at и updated_at
    protected $createdField  = ''; // Имя поля для даты создания записи
    protected $updatedField  = ''; // Имя поля для даты обновления записи
    protected $deletedField  = ''; // Имя поля для даты удаления записи (при использовании SoftDeletes)

    protected $validationRules    = []; // Правила валидации для входящих данных
    protected $validationMessages = []; // Сообщения валидации, соответствующие правилам
    protected $skipValidation     = false; // Пропускать ли валидацию при сохранении

    /**
     * Обновляет или добавляет данные ресурса в банке ресурсов.
     *
     * @param int $resourceId ID ресурса
     * @param int $quantity Количество ресурса
     * @param int $purchased Количество приобретенных ресурсов
     * @param int $sold Количество проданных ресурсов
     * @return bool Результат выполнения операции
     */
    public function updateOrInsertResource(int $resourceId, int $quantity, int $purchased, int $sold): bool
    {
        $existing = $this->where('resource_id', $resourceId)->first();

        if ($existing) {
            return $this->update($existing['id'], [
                'current_quantity' => $quantity,
                'resources_purchased' => $purchased,
                'resources_sold' => $sold,
                'last_update' => date('Y-m-d H:i:s'),
            ]);
        } else {
            return $this->insert([
                'resource_id' => $resourceId,
                'current_quantity' => $quantity,
                'resources_purchased' => $purchased,
                'resources_sold' => $sold,
                'last_update' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function updatePurchasedQuantity($resourceId, $quantity)
    {
        $existing = $this->where('resource_id', $resourceId)->first();

        if ($existing) {
            // Если запись найдена, обновляем количество приобретенных ресурсов
            $newPurchasedQuantity = $existing['resources_purchased'] + $quantity;
            return $this->update($existing['id'], [
                'resources_purchased' => $newPurchasedQuantity,
                'last_update' => date('Y-m-d H:i:s'), // Обновляем время последнего изменения
            ]);
        } else {
            // Если запись не найдена, создаем новую с начальным количеством приобретенных ресурсов
            return $this->insert([
                'resource_id' => $resourceId,
                'current_quantity' => $quantity, // Задаем текущее количество как количество приобретенных
                'resources_purchased' => $quantity,
                'resources_sold' => 0, // Поскольку это покупка, количество проданных ресурсов = 0
                'last_update' => date('Y-m-d H:i:s'),
            ]);
        }
    }

}

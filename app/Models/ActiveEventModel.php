<?php

namespace App\Models;

use CodeIgniter\Model;

class ActiveEventModel extends Model
{
    protected $table = 'active_events'; // Имя таблицы, с которой работает модель
    protected $primaryKey = 'id'; // Первичный ключ таблицы

    protected $useAutoIncrement = true; // Использование автоинкремента для первичного ключа
    protected $returnType = 'array'; // Формат возвращаемых данных

    protected $allowedFields = [
        'event_id', 'start_time', 'end_time', 'status', 'effect_applied'
    ]; // Поля, разрешенные для массового назначения

    protected $useTimestamps = true; // Использование автоматических меток времени
    protected $createdField  = 'created_at'; // Имя поля для метки времени создания
    protected $updatedField  = 'updated_at'; // Имя поля для метки времени обновления

    // Методы для работы с моделью могут быть добавлены здесь
    public function isActive($eventId)
    {
        return $this->where([
                'event_id' => $eventId,
                'status'   => 'active'
            ])->first() !== null;
    }

}

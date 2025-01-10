<?php

namespace App\Models;

use CodeIgniter\Model;

class EventEffectsLogModel extends Model
{
    protected $table = 'event_effects_log'; // Указываем имя таблицы
    protected $primaryKey = 'id'; // Указываем имя первичного ключа

    protected $useAutoIncrement = true; // Использование автоинкремента для первичного ключа

    protected $returnType = 'array'; // Возвращаемые данные будут в виде массива
    protected $useSoftDeletes = false; // Мягкое удаление не используется

    protected $allowedFields = [
        'character_id',
        'event_id',
        'cell_number',
        'biome_id',
        'effect_details',
        'event_time',
        'additional_info',
    ]; // Список полей, разрешенных для массового назначения

    protected $useTimestamps = false; // Отключаем автоматическое заполнение полей даты создания и обновления

    // Правила валидации данных
    protected $validationRules = [];

    // Сообщения об ошибках валидации
    protected $validationMessages = [];

    // Callback-методы для валидации
    protected $skipValidation = false;

    /**
     * Добавить лог эффекта события
     *
     * @param array $data Ассоциативный массив с данными для лога
     * @return bool|int ID созданной записи или false в случае неудачи
     */
    public function addLog($data)
    {
        if ($this->insert($data)) {
            return $this->getInsertID();
        }

        return false;
    }
}

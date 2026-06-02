<?php

namespace App\Models;

use CodeIgniter\Model;

class NpcModel extends Model
{
    /**
     * Название таблицы в БД.
     *
     * @var string
     */
    protected $table = 'npcs';

    /**
     * Первичный ключ таблицы.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Включить ли автоинкремент для поля $primaryKey.
     *
     * @var bool
     */
    protected $useAutoIncrement = true;

    /**
     * Тип возвращаемых данных:
     * 'array' или 'object' (можно задать свой класс).
     *
     * @var string
     */
    protected $returnType = 'array';

    /**
     * Использовать ли "мягкое" удаление (soft deletes).
     * Если true, в таблице должно быть поле `deleted_at`.
     *
     * @var bool
     */
    protected $useSoftDeletes = false;

    /**
     * Разрешённые поля для массового заполнения (insert, update).
     *
     * @var array
     */
    protected $allowedFields = [
        'npc_name_en',
        'npc_name_ru',
        'npc_type',
        'description',
        'level',
        'health',
        'strength',
        'agility',
        'intellect',
        'damage_type',
        'damage_value',
        'attack_speed',
        'armor',
        'aggression_range',
        'is_boss',
        'faction_id',
        'loot_table_id',
        'ai_behavior',
        'custom_settings',
        'is_rage', // ✅ Добавили флаг "ярости"
        'offers_quest_title_en', // ADR-089 Ф4: квест, который NPC предлагает (NULL = не квестгивер)
        // Обычно 'created_at' и 'updated_at' можно не указывать,
        // если они работают автоматически. Но при желании можно оставить и здесь:
        'created_at',
        'updated_at',
    ];

    /**
     * Включаем автозаполнение полей created_at и updated_at.
     *
     * @var bool
     */
    protected $useTimestamps = true;

    /**
     * Поля, куда фреймворк будет писать дату создания/обновления записи.
     *
     * @var string
     */
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    // Ниже — опциональные правила валидации, если нужно:
    protected $validationRules    = [
        'npc_name_en' => 'required|max_length[100]',
        'npc_name_ru' => 'required|max_length[100]',
        // При необходимости можно добавить больше
        // например, 'level' => 'required|integer|min_length[1]',
    ];

    protected $validationMessages = [
        'npc_name_en' => [
            'required'   => 'Поле «npc_name_en» обязательно.',
            'max_length' => 'Длина «npc_name_en» не может превышать 100 символов.',
        ],
        'npc_name_ru' => [
            'required'   => 'Поле «npc_name_ru» обязательно.',
            'max_length' => 'Длина «npc_name_ru» не может превышать 100 символов.',
        ],
    ];

    /**
     * Пропускать ли валидацию при insert / update.
     *
     * @var bool
     */
    protected $skipValidation = false;
}

<?php
namespace App\Models;

use CodeIgniter\Model;

class EventModel extends Model
{
    protected $table = 'events';
    protected $primaryKey = 'event_id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $allowedFields = [
        'name',
        'name_english',
        'handler_key',  // Phase B6 (ADR-023) — registry dispatch key, NULL = legacy fallback path.
        'description',
        'biome_ids',
        'event_type',
        'random_coverage',
        'duration',
        'frequency_per_week',
        'effect_type',
        'effect_value',
        'protection_item_id',
        'img_path',
        'start_time',
        'end_time'
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'name' => 'required|max_length[255]',
        'name_english' => 'required|max_length[255]',
        'description' => 'permit_empty|max_length[65535]',
        'biome_ids' => 'permit_empty',
        'event_type' => 'required|in_list[local,global]',
        'random_coverage' => 'required|in_list[0,1]',
        'duration' => 'permit_empty|integer',
        'frequency_per_week' => 'permit_empty|integer',
        'effect_type' => 'required|in_list[damage,heal,buff,debuff,none]',
        'effect_value' => 'permit_empty|integer',
        'protection_item_id' => 'permit_empty|integer',
        'img_path' => 'permit_empty|max_length[65535]',
        'start_time' => 'permit_empty|regex_match[/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/]',
        'end_time' => 'permit_empty|regex_match[/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/]',
    ];

    protected $validationMessages = [
        'name' => [
            'required' => 'Название события обязательно к заполнению.',
            'max_length' => 'Название события не должно превышать 255 символов.'
        ],
        'name_english' => [
            'required' => 'Название события обязательно к заполнению.',
            'max_length' => 'Название события не должно превышать 255 символов.'
        ],
        'description' => [
            'max_length' => 'Описание события не должно превышать 65535 символов.'
        ],

        'event_type' => [
            'required' => 'Тип события обязателен к заполнению.',
            'in_list' => 'Указан недопустимый тип события.'
        ],
        'random_coverage' => [
            'required' => 'Укажите, является ли покрытие событием случайным.',
            'in_list' => 'Указано недопустимое значение для случайного покрытия.'
        ],
        'duration' => [
            'integer' => 'Длительность события должна быть целым числом.'
        ],
        'frequency_per_week' => [
            'integer' => 'Частота встречаемости события в день должна быть целым числом.'
        ],
        'effect_type' => [
            'required' => 'Тип эффекта события обязателен к заполнению.',
            'in_list' => 'Указан недопустимый тип эффекта события.'
        ],
        'effect_value' => [
            'integer' => 'Значение эффекта должно быть целым числом.'
        ],
        'protection_item_id' => [
            'integer' => 'ID предмета защиты должен быть целым числом.'
        ],
        'img_path' => [
            'max_length' => 'Адрес к картинке не должен превышать 65535 символов.'
        ],
        'start_time' => [
            'valid_time' => 'Укажите корректное время начала события.'
        ],
        'end_time' => [
            'valid_time' => 'Укажите корректное время окончания события.'
        ],
        // Дополнительные сообщения валидации можно добавить по мере необходимости
    ];


    // F1.7 — кеширование статичного справочника событий (24 строки, меняются редко).
    private const CACHE_KEY = 'ref_events_all';
    private const CACHE_TTL = 3600;

    protected $afterInsert = ['bustListCache'];
    protected $afterUpdate = ['bustListCache'];
    protected $afterDelete = ['bustListCache'];

    /**
     * F1.7 — закешированный findAll. Используется внутри Worker'а на каждом
     * тике для подбора активных событий.
     */
    public function findAllCached(): array
    {
        $cache = \Config\Services::cache();
        $cached = $cache->get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        $rows = $this->findAll();
        $cache->save(self::CACHE_KEY, $rows, self::CACHE_TTL);
        return $rows;
    }

    /** F1.7 — bust callback. */
    protected function bustListCache(array $data): array
    {
        \Config\Services::cache()->delete(self::CACHE_KEY);
        return $data;
    }
}

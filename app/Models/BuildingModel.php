<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * 🔴 Две колонки этой таблицы ДЕКОРАТИВНЫЕ — рантайм их не читает, они остаются только
 * ради схемы и админского редактирования:
 *   - `min_character_level` — гейт уровня живёт в `Config\Buildings.level_required`
 *     ({@see \App\Services\Buildings\BuildingGateService}, ADR-155);
 *   - `construction_time`  — время стройки живёт в строке `tasks` задачи постройки
 *     ({@see \App\Services\Buildings\BuildDurationService::publishedMinutes}, ADR-161).
 * Обе успели разойтись с настоящим источником и врали игроку на публичной вики. Не
 * возвращать их в player-facing поверхности.
 */
class BuildingModel extends Model
{
    protected $table = 'buildings';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'name_ru',
        'name_en',
        'description',
        'building_type',
        'hp',
        'construction_time',
        'tax',
        'level',
        'usage',
        'required_resources',
        'min_character_level',
        'effects',
        'days_until_disappearance',
        'usage_count'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';

    protected $validationRules = [
        'name_ru' => 'required|string|max_length[255]',
        'name_en' => 'required|string|max_length[255]',
        'description' => 'permit_empty|string',
        'building_type' => 'required|in_list[military,residential,farming,resource,engineering]',
        'hp' => 'required|integer',
        'construction_time' => 'required|integer',
        'tax' => 'required|integer',
        'level' => 'required|integer|in_list[1,2,3]',
        'usage' => 'required|in_list[personal,collective,all]',
        'required_resources' => 'permit_empty|string',
        'min_character_level' => 'required|integer',
        'effects' => 'permit_empty|string',
        'days_until_disappearance' => 'required|integer',
        'usage_count' => 'permit_empty|integer|max_length[1000000]'
    ];

    protected $validationMessages = [
        'name_ru' => [
            'required' => 'Russian name is required.',
            'string' => 'Russian name must be a string.',
            'max_length' => 'Russian name cannot exceed 255 characters.'
        ],
        'name_en' => [
            'required' => 'English name is required.',
            'string' => 'English name must be a string.',
            'max_length' => 'English name cannot exceed 255 characters.'
        ],
        'building_type' => [
            'required' => 'Building type is required.',
            'in_list' => 'Building type must be one of: military, residential, farming, resource, engineering.'
        ],
        'hp' => [
            'required' => 'HP is required.',
            'integer' => 'HP must be an integer.'
        ],
        'construction_time' => [
            'required' => 'Construction time is required.',
            'integer' => 'Construction time must be an integer.'
        ],
        'tax' => [
            'required' => 'Tax is required.',
            'integer' => 'Tax must be an integer.'
        ],
        'level' => [
            'required' => 'Level is required.',
            'integer' => 'Level must be an integer.',
            'in_list' => 'Level must be one of: 1, 2, 3.'
        ],
        'usage' => [
            'required' => 'Usage is required.',
            'in_list' => 'Usage must be one of: personal, collective, all.'
        ],
        'min_character_level' => [
            'required' => 'Minimum character level is required.',
            'integer' => 'Minimum character level must be an integer.'
        ],
        'days_until_disappearance' => [
            'required' => 'Days until disappearance is required.',
            'integer' => 'Days until disappearance must be an integer.'
        ],
        'usage_count' => [
            'integer' => 'Usage count must be an integer.',
            'max_length' => 'Usage count cannot exceed 1000000.'
        ]
    ];

    protected $skipValidation = false;

    /**
     * Кэш на процесс: name_en → строка таблицы buildings (или null, если нет).
     *
     * @var array<string, array<array-key, mixed>|null>
     */
    private static array $byNameEnCache = [];

    /**
     * Находит здание по СТАБИЛЬНОМУ `name_en` (источник правды — таблица buildings).
     *
     * Заменяет хрупкий хардкод `$buildingId = N` в *Handler'ах (E28, NAVIGATION_MAP #25):
     * позиционные id дрейфят при реседе/реордере миграций — например ArsenalHandler
     * когда-то держал `$buildingId = 15`, а реальный id «Арсенала» = 11 (id 15 сейчас
     * у «Дозорной вышки»), т.е. хардкод грузил бы чужое здание. `name_en` стабилен.
     *
     * Кэш на процесс безопасен: buildings — статичные seed-данные определений
     * (вайп их KEEP'ит, см. Config\WipeManifest). Негативный результат тоже кэшируется.
     *
     * @return array<array-key, mixed>|null
     */
    public function findByNameEn(string $nameEn): ?array
    {
        if (array_key_exists($nameEn, self::$byNameEnCache)) {
            return self::$byNameEnCache[$nameEn];
        }

        $row = $this->where('name_en', $nameEn)->first();

        return self::$byNameEnCache[$nameEn] = is_array($row) ? $row : null;
    }

    /**
     * Резолвит id здания по `name_en` (0 — если здания нет). Возвращает чистый int,
     * чтобы на местах вызова не было `(int)$mixed` (строгий phpstan, cast.int).
     */
    public function idByNameEn(string $nameEn): int
    {
        $row = $this->findByNameEn($nameEn);
        $id  = $row['id'] ?? null;

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * Русское имя постройки для player-facing текста.
     *
     * 🔴 Колонка в таблице `buildings` называется `name_ru`, а ключ в `Config\Buildings`
     * (и в рецептах крафта) — `name_rus`. Экраны, читавшие строку БД по `name_rus`,
     * молча падали в английский fallback и показывали игроку «BlastFurnace» /
     * «Laboratory» — постройки, которых он «не встречал». Резолвер знает оба ключа;
     * читать строку `buildings` напрямую по имени больше не нужно.
     *
     * @param mixed  $row      строка `buildings` (или recipe-массив из Config\Buildings)
     * @param string $fallback чем подписать, если имени нет вообще (обычно name_en)
     */
    public static function rusName($row, string $fallback = ''): string
    {
        if (is_array($row)) {
            foreach (['name_ru', 'name_rus'] as $key) {
                $value = $row[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return $fallback;
    }

    /**
     * Сброс кэша name_en (для тестов и админ-правок справочника buildings).
     */
    public static function clearNameEnCache(): void
    {
        self::$byNameEnCache = [];
    }
}

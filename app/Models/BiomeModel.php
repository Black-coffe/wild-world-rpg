<?php

namespace App\Models;

use App\Entities\BiomeEntity;
use CodeIgniter\Model;

/**
 * F1.4.1 — Model returnType = BiomeEntity. Аннотации @method
 * нужны потому что CI4 Model::find()/findAll() сигнатуры
 * generic (`array|object|null`) и PHPStan не пропагирует $returnType.
 *
 * @method BiomeEntity|null find($id = null)
 * @method list<BiomeEntity> findAll(?int $limit = null, int $offset = 0)
 * @method BiomeEntity|null first()
 */
class BiomeModel extends Model
{
    protected $table = 'biomes';
    protected $primaryKey = 'id';
    protected $allowedFields = ['name', 'description', 'biome_type', 'danger_level_text', 'danger_level', 'survival_difficulty_text', 'survival_difficulty', 'occurrence_rate', 'created_at', 'updated_at'];
    protected $useTimestamps = true;
    protected $returnType = BiomeEntity::class; // F1.4.1 (v0.44.0) — типизированная Entity замість 'array'

    // Валидация данных
    protected $validationRules = [
        'name' => 'required|max_length[100]',
        'description' => 'required',
        'biome_type' => 'required|in_list[hot,cold,wet,dry,volcanic,cave,jungle,desert,plain]', // Правила валидации для нового поля
        'danger_level_text' => 'permit_empty|max_length[100]',
        'danger_level' => 'permit_empty|integer|greater_than_equal_to[1]|less_than_equal_to[10]',
        'survival_difficulty_text' => 'permit_empty|max_length[100]',
        'survival_difficulty' => 'permit_empty|integer|greater_than_equal_to[1]|less_than_equal_to[10]',
        'occurrence_rate' => 'required|decimal|max_length[5]',
    ];

    protected $validationMessages = [
        'name' => [
            'required' => 'Поле "Название" является обязательным.',
            'max_length' => 'Поле "Название" не может содержать более 100 символов.',
        ],
        'description' => [
            'required' => 'Поле "Описание" является обязательным.',
        ],
        'biome_type' => [
            'required' => 'Поле "Тип биома" является обязательным.',
            'in_list' => 'Поле "Тип биома" содержит недопустимое значение.',
        ],
        'occurrence_rate' => [
            'required' => 'Поле "Частота встречаемости" является обязательным.',
            'decimal' => 'Поле "Частота встречаемости" должно быть числом с плавающей запятой.',
            'max_length' => 'Поле "Частота встречаемости" не может содержать более 5 символов.',
        ],
    ];

    // F1.7 — bust кеша при любом изменении справочника биомов.
    protected $afterInsert = ['bustListCache'];
    protected $afterUpdate = ['bustListCache'];
    protected $afterDelete = ['bustListCache'];

    /** Cache key и TTL — выровнены с другими reference-моделями. */
    private const CACHE_KEY = 'ref_biomes_all';
    private const CACHE_TTL = 3600; // 1 час; bust происходит при любой правке через админку.

    // Callback валидации для проверки допустимости уровня опасности
    protected $validationCallbacks = [
        'beforeInsert' => 'validateDangerLevel',
        'beforeUpdate' => 'validateDangerLevel',
    ];

    protected function validateDangerLevel(array $data): bool
    {
        if (isset($data['danger_level'])) {
            if ($data['danger_level'] < 1 || $data['danger_level'] > 10) {
                $this->validationErrors['danger_level'] = 'Уровень опасности должен быть целым числом от 1 до 10.';
                return false;
            }
        }

        return true;
    }

    // Метод для получения названия биома по его ID
    public function getBiomeNameById(int|string $id): ?string
    {
        $biome = $this->find($id);
        return $biome ? $biome->name : null;
    }

    /**
     * F1.7 — закешированный findAll для справочника.
     * Биомов всего 9, статичны 99% времени; вызывается из Worker'а сотни
     * раз в минуту → переходим из БД в кеш.
     *
     * @return array<int, BiomeEntity>
     */
    public function findAllCached(): array
    {
        $cache = \Config\Services::cache();
        $cached = $cache->get(self::CACHE_KEY);
        if (is_array($cached)) {
            /** @var list<BiomeEntity> $cached */
            return $cached;
        }
        $rows = $this->findAll();
        $cache->save(self::CACHE_KEY, $rows, self::CACHE_TTL);
        return $rows;
    }

    /**
     * F1.7 — закешированный find по id.
     */
    public function findCached(int $id): ?BiomeEntity
    {
        foreach ($this->findAllCached() as $row) {
            if ($row->id === $id) {
                return $row;
            }
        }
        return null;
    }

    /** F1.7 — bust callback для afterInsert/Update/Delete. */
    protected function bustListCache(array $data): array
    {
        \Config\Services::cache()->delete(self::CACHE_KEY);
        return $data;
    }
}

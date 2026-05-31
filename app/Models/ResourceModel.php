<?php

namespace App\Models;

use App\Entities\ResourceEntity;
use CodeIgniter\Model;

/**
 * F1.4.2 — Model returnType = ResourceEntity. @method анотації потрібні бо
 * CI4 Model::find()/findAll() generic mixed без template propagation.
 *
 * @method ResourceEntity|null find($id = null)
 * @method list<ResourceEntity> findAll(?int $limit = null, int $offset = 0)
 * @method ResourceEntity|null first()
 */
class ResourceModel extends Model
{
    protected $table = 'resources';
    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;
    protected $returnType = ResourceEntity::class; // F1.4.2 (v0.45.0) — типизованная Entity замість 'array'
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'name',
        'name_en',
        'description',
        'biome_id',
        'is_tradeable',
        'type',
        'price',
        'buy_price',
        'sell_price',
        'rarity',
        'weight',
        'level_required',
        'initial_quantity',
        'icon_text',
        'keyword',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = '';

    protected $validationRules = [
        'name' => 'required|max_length[255]',
        'name_en' => 'required|max_length[255]',
        'description' => 'permit_empty|max_length[65535]',
        'biome_id' => 'permit_empty',
        'is_tradeable' => 'required|in_list[0,1]',
        'type' => 'required',
        'price' => 'required|integer',
        'buy_price' => 'permit_empty|decimal',
        'sell_price' => 'permit_empty|decimal',
        'rarity' => 'required|integer|greater_than[0]|less_than[11]',
        'level_required' => 'required|integer|greater_than[0]',
        'initial_quantity' => 'required|integer|greater_than[0]',
        'icon_text' => 'permit_empty|max_length[255]',
        'keyword' => 'permit_empty|alpha_numeric_space|max_length[100]',
    ];

    protected $validationMessages = [
        'name' => [
            'required' => 'Поле Название ресурса обязательно для заполнения.',
            'max_length' => 'Название ресурса не может быть более 255 символов.'
        ],
        'name_en' => [
            'required' => 'Поле Название на английском ресурса обязательно для заполнения.',
            'max_length' => 'Название ресурса не может быть более 255 символов.'
        ],
        'description' => [
            'max_length' => 'Описание ресурса не может быть более 65535 символов.'
        ],
        'is_tradeable' => [
            'required' => 'Необходимо указать, возможна ли торговля ресурсом.',
            'in_list' => 'Указано неверное значение для возможности торговли.'
        ],
        'price' => [
            'required' => 'Поле Цена обязательно для заполнения.',
            'integer' => 'Цена должна быть числом.'
        ],
        'buy_price' => [
            'decimal' => 'Цена должна быть числом.'
        ],
        'sell_price' => [
            'decimal' => 'Цена должна быть числом.'
        ],
        'rarity' => [
            'required' => 'Поле Редкость обязательно для заполнения.',
            'integer' => 'Редкость должна быть числом.',
            'greater_than' => 'Редкость должна быть больше 0.',
            'less_than' => 'Редкость должна быть меньше или равна 10.'
        ],
        'level_required' => [
            'required' => 'Поле Требуемый уровень обязательно для заполнения.',
            'integer' => 'Требуемый уровень должен быть числом.',
            'greater_than' => 'Требуемый уровень должен быть больше 0.'
        ],
        'initial_quantity' => [
            'required' => 'Поле ресырсы торговца  обязательно для заполнения.',
            'integer' => 'Требуемый уровень должен быть числом.',
            'greater_than' => 'Требуемый уровень должен быть больше 0.'
        ],
        'icon_text' => [
            'max_length' => 'Иконка ресурса не может быть более 255 символов.'
        ],
        'keyword' => [
            'alpha_numeric_space' => 'Ключевое слово должно содержать только буквы и цифры.',
            'max_length' => 'Ключевое слово не может быть длиннее 100 символов.'
        ],
    ];

    protected $skipValidation = false;

    public function getCharacterResources($characterId)
    {
        return $this->select('resources.*, character_resources.id AS charResId, character_resources.quantity')
            ->join('character_resources', 'resources.id = character_resources.id_resources')
            ->where('character_resources.id_characters', $characterId)
            ->findAll();
    }

    public function subtractResourceQuantity($characterId, $resourceId, $quantity)
    {
        $builder = $this->db->table('character_resources');
        $query = $builder->where('id_characters', $characterId)
            ->where('id_resources', $resourceId)
            ->get();
        if ($query === false) {
            return false;
        }
        $characterResource = $query->getRowArray();

        if ($characterResource && $characterResource['quantity'] >= $quantity) {
            $builder->where('id_characters', $characterId)
                ->where('id_resources', $resourceId)
                ->set('quantity', 'quantity - ' . $quantity, FALSE)
                ->update();
            return true;
        }
        return false;
    }

    public function getResourceByName($name)
    {
        return $this->where('name', $name)->first();
    }

    public function getResourceByNameEn($name)
    {
        return $this->where('name_en', $name)->first();
    }

    public function getResourceById($id)
    {
        return $this->where('id', $id)->first();
    }

    public function addOrIncreaseResource($characterId, $resourceName, $quantity) {
        $resource = $this->where('name_en', $resourceName)->first();

        if (!$resource) {
            return false;
        }

        $builder = $this->db->table('character_resources');
        $query = $builder->where('id_characters', $characterId)->where('id_resources', $resource['id'])->get();
        if ($query === false) {
            return false;
        }
        $existing = $query->getRow();

        if ($existing) {
            $newQuantity = $existing->quantity + $quantity;
            $builder->where('id', $existing->id)->update(['quantity' => $newQuantity]);
        } else {
            $builder->insert([
                'id_characters' => $characterId,
                'id_resources' => $resource['id'],
                'quantity' => $quantity
            ]);
        }

        return true;
    }

    // F1.7 — кеш справочника ресурсов (70 строк, меняется редко).
    private const CACHE_KEY = 'ref_resources_all';
    private const CACHE_TTL = 3600;

    protected $afterInsert = ['bustListCache'];
    protected $afterUpdate = ['bustListCache'];
    protected $afterDelete = ['bustListCache'];

    /**
     * F1.4.2: returns list of ResourceEntity (cache stores serialized entities).
     *
     * @return list<ResourceEntity>
     */
    public function findAllCached(): array
    {
        $cache = \Config\Services::cache();
        $cached = $cache->get(self::CACHE_KEY);
        if (is_array($cached)) {
            /** @var list<ResourceEntity> $cached */
            return $cached;
        }
        $rows = $this->findAll();
        $cache->save(self::CACHE_KEY, $rows, self::CACHE_TTL);
        return $rows;
    }

    protected function bustListCache(array $data): array
    {
        \Config\Services::cache()->delete(self::CACHE_KEY);
        return $data;
    }
}

<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Модель для работы с таблицей `characters_outfits`,
 * которая хранит экземпляры (инвентарь) предметов амуниции для персонажей.
 *
 * Таблица `characters_outfits` содержит поля:
 * - id                  (int, PK)   : Автоинкрементный первичный ключ
 * - character_id        (int, FK)   : Ссылка на id из таблицы `characters`
 * - outfit_id           (int, FK)   : Ссылка на id из таблицы `outfits`
 * - quantity            (int)       : Количество единиц предмета
 * - current_durability  (int)       : Текущее значение прочности (износа)
 * - equipped            (tinyint)   : Флаг экипировки (0 = не надет, 1 = надет)
 * - slot                (varchar)   : Название слота (body, head, legs и т.д.)
 * - created_at          (datetime)  : Когда запись была создана
 * - updated_at          (datetime)  : Когда запись была изменена
 */
class CharactersOutfitsModel extends Model
{
    /**
     * @var string $table Название таблицы в БД.
     */
    protected $table = 'characters_outfits';

    /**
     * @var string $primaryKey Поле, являющееся PK (первичным ключом).
     */
    protected $primaryKey = 'id';

    /**
     * @var bool $useAutoIncrement Использовать ли автоинкремент (true/false).
     */
    protected $useAutoIncrement = true;

    /**
     * @var string $returnType Формат возвращаемых данных.
     * Можно 'array' или 'object'. По умолчанию — 'array'.
     */
    protected $returnType = 'array';

    /**
     * @var bool $useSoftDeletes Использовать ли мягкое удаление (маркеры deleted_at).
     * В данном случае — false, чтобы физически удалять записи.
     */
    protected $useSoftDeletes = false;

    /**
     * @var array $allowedFields Разрешённые поля для массового заполнения
     * (insert, update).
     */
    protected $allowedFields = [
        'character_id',
        'outfit_id',
        'quantity',
        'current_durability',
        'equipped',
        'slot',
        'created_at',
        'updated_at',
    ];

    /**
     * Включать ли автоматическую проставку меток времени (created_at, updated_at).
     * Если true, CodeIgniter будет автоматически заполнять эти поля (при условии,
     * что они заданы в $createdField / $updatedField).
     */
    protected $useTimestamps = true;

    /**
     * Название поля для "created at". Если в таблице поле названо иначе —
     * можно переопределить. По умолчанию: created_at.
     */
    protected $createdField  = 'created_at';

    /**
     * Название поля для "updated at". Если в таблице поле названо иначе —
     * можно переопределить. По умолчанию: updated_at.
     */
    protected $updatedField  = 'updated_at';

    /**
     * @var string $deletedField Поле для мягкого удаления (deleted_at).
     * Так как $useSoftDeletes = false, можно оставить пустым.
     */
    protected $deletedField  = '';

    /**
     * @var array $validationRules Правила валидации (если используем встроенную валидацию).
     */
    protected $validationRules = [
        'character_id'       => 'required|is_natural_no_zero',
        'outfit_id'          => 'required|is_natural_no_zero',
        'quantity'           => 'required|integer|greater_than[0]',
        'current_durability' => 'required|integer',
        'equipped'           => 'required|in_list[0,1]',
        'slot'               => 'permit_empty|max_length[50]',
    ];

    /**
     * @var array $validationMessages Сообщения об ошибках, если не проходят validationRules.
     */
    protected $validationMessages = [
        'character_id' => [
            'required'              => 'Не указан ID персонажа.',
            'is_natural_no_zero'    => 'ID персонажа должно быть положительным целым.',
        ],
        'outfit_id' => [
            'required'              => 'Не указан ID амуниции (outfit_id).',
            'is_natural_no_zero'    => 'ID амуниции должно быть положительным целым.',
        ],
        'quantity' => [
            'required'              => 'Количество предметов не может быть пустым.',
            'integer'               => 'Количество должно быть целым числом.',
            'greater_than'          => 'Количество должно быть больше 0.',
        ],
        'current_durability' => [
            'required'              => 'Не указана текущая прочность (износ).',
            'integer'               => 'Прочность должна быть целым числом.',
        ],
        'equipped' => [
            'required'              => 'Не указано состояние экипировки (0 или 1).',
            'in_list'               => 'equipped может быть только 0 или 1.',
        ],
        'slot' => [
            'max_length'            => 'Название слота не может быть длиннее 50 символов.',
        ],
    ];

    /**
     * @var bool $skipValidation Если true, пропускается валидация.
     */
    protected $skipValidation = false;

    /**
     * Пример вспомогательного метода: получить все предметы, которые экипированы у персонажа.
     *
     * @param int $characterId
     * @return array|null
     */
    public function getEquippedItemsByCharacter(int $characterId): ?array
    {
        return $this->where('character_id', $characterId)
            ->where('equipped', 1)
            ->findAll();
    }

    /**
     * Пример вспомогательного метода: добавить/увеличить количество
     * указанного outfit_id для персонажа. Если записи нет, создать,
     * если есть — прибавить.
     *
     * @param int $characterId
     * @param int $outfitId
     * @param int $qty
     * @return bool
     */
    public function addOrIncreaseOutfit(int $characterId, int $outfitId, int $qty = 1): bool
    {
        // Ищем запись
        $row = $this->where('character_id', $characterId)
            ->where('outfit_id', $outfitId)
            ->first();

        if (!$row) {
            // Если нет, создаём новую
            $data = [
                'character_id'       => $characterId,
                'outfit_id'          => $outfitId,
                'quantity'           => $qty,
                'current_durability' => 0, // или же 100, если хотите ставить макс. прочность
                'equipped'           => 0,
            ];
            return (bool) $this->insert($data);
        } else {
            // Если запись уже есть, увеличиваем quantity
            $newQty = $row['quantity'] + $qty;
            return $this->update($row['id'], ['quantity' => $newQty]);
        }
    }

    /**
     * Пример метода: снять/собрать все экипированные предметы
     * (полезно при смене брони/костюмов).
     *
     * @param int $characterId
     * @return bool
     */
    public function unequipAll(int $characterId): bool
    {
        $this->where('character_id', $characterId)
            ->where('equipped', 1)
            ->set(['equipped' => 0, 'slot' => null])
            ->update();

        // Проверка, что запрос не завершился ошибкой
        return ($this->db->affectedRows() >= 0);
    }

    /**
     * Пример метода: найти запись предмета и уменьшить durability на X.
     *
     * @param int $charOutfitId  ID записи в characters_outfits
     * @param int $damage        Сколько прочности снимаем
     * @return bool
     */
    public function applyDamage(int $charOutfitId, int $damage): bool
    {
        $item = $this->find($charOutfitId);
        if (!$item) {
            return false;
        }

        $newDurability = max(0, $item['current_durability'] - $damage);
        return $this->update($charOutfitId, ['current_durability' => $newDurability]);
    }

    /**
     * Другие методы по необходимости:
     * - repairItem(...)            // чинить вещь
     * - equipItemToSlot(...)       // надеть конкретную вещь
     * - moveQuantityToAnother(...) // передать другому персонажу
     * - ... и т.д.
     */
}

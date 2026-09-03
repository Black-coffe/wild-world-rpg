<?php

namespace App\Models;

use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
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
     * exploit-fix-16 (ADR-181 §M3) — ленивый инстанс, не конструкторная инъекция:
     * `ResourcesBankModel` создаётся по всему `app/` голым `new ResourcesBankModel()`
     * (без аргументов), менять сигнатуру конструктора модели ради DI здесь избыточно
     * и рискует конфликтом с параметрами `CodeIgniter\Model::__construct()`.
     */
    private ?ConditionalWriteService $conditionalWrite = null;

    private function conditionalWrite(): ConditionalWriteService
    {
        return $this->conditionalWrite ??= new ConditionalWriteService();
    }

    public function updatePurchasedQuantity($resourceId, $quantity): WriteOutcome
    {
        return $this->createOrBumpCounter((int) $resourceId, (int) $quantity, 'resources_purchased', (int) $quantity);
    }

    /**
     * exploit-fix-16 (ADR-181 §M3) — единственная точка создания строки `resources_bank`
     * во всём `app/` (продажа, оптовая продажа, покупка). `UNIQUE(resource_id)` (story 10)
     * означает, что две одновременные первые сделки одного ресурса гонятся за одной
     * строкой — `insertUnique()` (story 18, дубль не портит `transStatus` вызывающего)
     * ловит проигравшую сторону как `Refused`, а не как 1062/500. На `Refused` строку уже
     * создал конкурент — перечитываем её и обновляем счётчик штатно, не трогая чужие поля.
     *
     * `$initialCurrentQuantity` — покупка исторически заводит новую строку с
     * `current_quantity = $quantity` (а не 0, как продажа) — сохраняем эту семантику как
     * есть (Non-goals story 16: не менять формулы), только способ вставки меняется.
     *
     * exploit-fix-25 (R2-major reviewer 1) — ветка `Refused` раньше перечитывала строку
     * (`where()->first()`) и писала абсолютное значение прочитанного счётчика + `$qty`:
     * внутри чужой транзакции (оптовая продажа, `bulkSellResources()` держит
     * `transStart()`/`transComplete()` вокруг цикла) этот `SELECT` идёт по снимку
     * REPEATABLE READ вызывающего, снятому ДО того, как конкурент вставил строку между
     * `Refused`-коллизией и этим перечитыванием — строку не видно, `$existingId <= 0`,
     * бамп терялся молча (ревьюер 1 воспроизвёл двумя реальными соединениями). `UPDATE`,
     * в отличие от обычного `SELECT`, в InnoDB — read ПОСЛЕДНЕЙ зафиксированной версии
     * (locking read), а не снимка транзакции, поэтому `increment()` видит строку
     * конкурента независимо от момента её коммита. `last_update` на этой ветке больше не
     * обновляется — `increment()` (контракт story 27, не меняется здесь) бампает только
     * одну колонку; это принятый компромисс, не задача этой story.
     */
    public function createOrBumpCounter(int $resourceId, int $qty, string $counterColumn, int $initialCurrentQuantity = 0): WriteOutcome
    {
        if (! in_array($counterColumn, ['resources_sold', 'resources_purchased'], true)) {
            throw new \InvalidArgumentException("createOrBumpCounter: недопустимая колонка {$counterColumn}");
        }

        $now = date('Y-m-d H:i:s');
        $row = [
            'resource_id'         => $resourceId,
            'current_quantity'    => $initialCurrentQuantity,
            'resources_purchased' => 0,
            'resources_sold'      => 0,
            'last_update'         => $now,
        ];
        $row[$counterColumn] = $qty;

        $outcome = $this->conditionalWrite()->insertUnique('resources_bank', $row);
        if ($outcome !== WriteOutcome::Refused) {
            return $outcome;
        }

        return $this->conditionalWrite()->increment('resources_bank', ['resource_id' => $resourceId], $counterColumn, $qty);
    }
}

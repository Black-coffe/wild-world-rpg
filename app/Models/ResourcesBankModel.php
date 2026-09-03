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
        }

        // exploit-fix-16: этот метод пишет абсолютные значения всех трёх счётчиков разом
        // (не относительный bump одной колонки, как createOrBumpCounter ниже) — не подходит
        // под общий примитив по форме, поэтому вставка через insertUnique() инлайн, с тем же
        // Refused → перечитать → обновить, что и у остальных вызывающих.
        $now      = date('Y-m-d H:i:s');
        $outcome  = $this->conditionalWrite()->insertUnique('resources_bank', [
            'resource_id'         => $resourceId,
            'current_quantity'    => $quantity,
            'resources_purchased' => $purchased,
            'resources_sold'      => $sold,
            'last_update'         => $now,
        ]);
        if ($outcome !== WriteOutcome::Refused) {
            return $outcome === WriteOutcome::Applied;
        }

        $row = $this->where('resource_id', $resourceId)->first();
        if (! is_array($row) || ! is_numeric($row['id'] ?? null)) {
            return false;
        }

        return (bool) $this->update((int) $row['id'], [
            'current_quantity'    => $quantity,
            'resources_purchased' => $purchased,
            'resources_sold'      => $sold,
            'last_update'         => $now,
        ]);
    }

    public function updatePurchasedQuantity($resourceId, $quantity)
    {
        $this->createOrBumpCounter((int) $resourceId, (int) $quantity, 'resources_purchased', (int) $quantity);

        return true;
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
     */
    public function createOrBumpCounter(int $resourceId, int $qty, string $counterColumn, int $initialCurrentQuantity = 0): void
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
            return;
        }

        $existing   = $this->where('resource_id', $resourceId)->first();
        $existingId = is_array($existing) && is_numeric($existing['id'] ?? null) ? (int) $existing['id'] : 0;
        if ($existingId <= 0) {
            return;
        }

        $currentRaw = $existing[$counterColumn] ?? 0;
        $current    = is_numeric($currentRaw) ? (int) $currentRaw : 0;
        $this->update($existingId, [
            $counterColumn => $current + $qty,
            'last_update'  => $now,
        ]);
    }
}

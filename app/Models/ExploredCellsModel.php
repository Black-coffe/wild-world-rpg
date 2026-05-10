<?php

namespace App\Models;

use CodeIgniter\Model;

class ExploredCellsModel extends Model
{
    /**
     * Туман войны (ADR-019 §1): раскрывает квадратное окно `(2·radius+1)²` вокруг
     * клетки с координатами `($centerX, $centerY)` — центр + соседи по метрике
     * Чебышёва — в personal `explored_cells` персонажа. Идемпотентно: уже
     * раскрытые клетки пропускаются (де-дуп по `map_cell_id` = `cell_number`).
     * За границами карты (1..1000) клеток нет — окно обрезается.
     *
     * Вызывается при любой смене позиции: одиночный шаг (`MoveCharacterToDirectionAction`),
     * тик марша (ADR-019 §3), телепорт, респаун, создание персонажа.
     *
     * @return int количество НОВО раскрытых клеток
     */
    public function revealAround(
        int $characterId,
        int $telegramUserId,
        int $centerX,
        int $centerY,
        ?int $characterLevel = null,
        int $radius = 1
    ): int {
        $xMin = max(1, $centerX - $radius);
        $xMax = min(1000, $centerX + $radius);
        $yMin = max(1, $centerY - $radius);
        $yMax = min(1000, $centerY + $radius);

        // 3×3-окно из map: cell_number → biome_id (raw query — типизированный getResultArray).
        $windowQuery = $this->db->query(
            'SELECT cell_number, biome_id FROM map
             WHERE coordinate_x BETWEEN ? AND ? AND coordinate_y BETWEEN ? AND ?',
            [$xMin, $xMax, $yMin, $yMax]
        );
        if (! $windowQuery instanceof \CodeIgniter\Database\BaseResult) {
            return 0;
        }
        $windowCells = $windowQuery->getResultArray();
        if ($windowCells === []) {
            return 0;
        }

        $cellNumbers = array_map(static fn (array $c): int => (int) $c['cell_number'], $windowCells);

        // Какие из них уже раскрыты для этого персонажа.
        $placeholders = implode(',', array_fill(0, count($cellNumbers), '?'));
        $alreadyQuery = $this->db->query(
            "SELECT map_cell_id FROM explored_cells WHERE character_id = ? AND map_cell_id IN ({$placeholders})",
            array_merge([$characterId], $cellNumbers)
        );
        $already = [];
        if ($alreadyQuery instanceof \CodeIgniter\Database\BaseResult) {
            foreach ($alreadyQuery->getResultArray() as $r) {
                $already[(int) $r['map_cell_id']] = true;
            }
        }

        $count = 0;
        foreach ($windowCells as $c) {
            $cn = (int) $c['cell_number'];
            if (isset($already[$cn])) {
                continue;
            }
            $this->insert([
                'character_id'     => $characterId,
                'telegram_user_id' => $telegramUserId,
                'map_cell_id'      => $cn,
                'biome_id'         => (int) $c['biome_id'],
                'character_level'  => $characterLevel,
            ]);
            $count++;
        }

        return $count;
    }

    protected $table = 'explored_cells'; // Имя таблицы
    protected $primaryKey = 'id'; // Первичный ключ таблицы

    protected $useAutoIncrement = true; // Использовать ли автоинкремент для первичного ключа
    protected $returnType     = 'array'; // Тип возвращаемых данных (массив)
    protected $useSoftDeletes = false; // Использовать ли мягкое удаление

    protected $allowedFields = [
        'character_id',
        'telegram_user_id',
        'map_cell_id',
        'biome_id',
        'character_level',
        'cell_status',
        'notes'
    ]; // Поля, разрешенные для массового назначения

    protected $useTimestamps = true; // Использовать ли автоматическое заполнение полей даты создания и редактирования
    protected $createdField  = 'created_at'; // Имя поля для даты создания
    protected $updatedField  = 'updated_at'; // Имя поля для даты редактирования
    protected $deletedField  = ''; // Имя поля для мягкого удаления, если используется

    protected $validationRules    = []; // Правила валидации
    protected $validationMessages = []; // Сообщения валидации
    protected $skipValidation     = false; // Пропускать ли валидацию
}

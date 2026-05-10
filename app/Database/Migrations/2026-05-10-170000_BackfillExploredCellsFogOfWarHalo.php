<?php

namespace App\Database\Migrations;

use App\Models\ExploredCellsModel;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Database\Migration;

/**
 * ADR-019 Step 2 — бэкфилл тумана войны вокруг текущей позиции каждого персонажа.
 *
 * После Step 1 (`ExploredCellsModel::revealAround()` на каждом перемещении) у уже
 * существующих персонажей 3×3-окно вокруг ИХ ТЕКУЩЕЙ клетки может быть раскрыто не
 * полностью — старый стоячий explore раскрывал кольцами от позиции и мог не покрыть
 * непосредственных соседей; в итоге игрок видит ⬛️ прямо рядом с собой. Раскрываем
 * сейчас, одноразово, чтобы карта не была странно-пустой вокруг самого персонажа.
 *
 * Идемпотентно: `revealAround()` — check-then-insert, повторный прогон ничего не
 * дублирует. Не меняет схему. Прогон по всем персонажам с валидной позицией
 * (~358 active, max id ~992) × ≤9 INSERT каждый — секунды.
 *
 * down() = no-op: бэкфилл необратим — backfilled-клетку нельзя отличить от
 * органически раскрытой.
 */
class BackfillExploredCellsFogOfWarHalo extends Migration
{
    public function up()
    {
        $db            = \Config\Database::connect();
        $exploredModel = new ExploredCellsModel();

        $charsQuery = $db->query(
            'SELECT id, telegram_user_id, cell_number, level FROM characters
             WHERE cell_number IS NOT NULL AND cell_number > 0'
        );
        if (! $charsQuery instanceof BaseResult) {
            return;
        }

        foreach ($charsQuery->getResultArray() as $c) {
            $cellQuery = $db->query(
                'SELECT coordinate_x, coordinate_y FROM map WHERE cell_number = ?',
                [(int) $c['cell_number']]
            );
            if (! $cellQuery instanceof BaseResult) {
                continue;
            }
            $cell = $cellQuery->getRowArray();
            if ($cell === null) {
                continue;
            }

            $exploredModel->revealAround(
                (int) $c['id'],
                (int) $c['telegram_user_id'],
                (int) $cell['coordinate_x'],
                (int) $cell['coordinate_y'],
                isset($c['level']) ? (int) $c['level'] : null
            );
        }
    }

    public function down()
    {
        // Бэкфилл необратим — backfilled-клетки неотличимы от органически раскрытых.
    }
}

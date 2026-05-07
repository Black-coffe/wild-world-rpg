<?php

namespace App\Services\Player\Relocation;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * v0.51.48 (BaseShiftingCommand decomp Step 2) — extract exponential ring search
 * для пошуку найближчої вивченої + вільної ячейки.
 *
 * Алгоритм: радіус починається з 16, експоненційно × 2 до 1024. На кожному
 * radius: SQL bounding-box SELECT з explored_cells JOIN map. Filter через
 * RelocationValidator. Euclidean distance sort. Return найближчий match.
 *
 * Bonus cleanup: original method у BaseShiftingCommand мав dead `$taskModel` +
 * `$frTaskId` lookup (set but never used — leftover refactor). Скинуто.
 */
class ClosestCellFinder
{
    private RelocationValidator $validator;
    private BaseConnection $db;

    public function __construct(
        ?RelocationValidator $validator = null,
        ?BaseConnection $db = null
    ) {
        $this->validator = $validator ?? new RelocationValidator();
        $this->db        = $db ?? Database::connect();
    }

    /**
     * Знаходить найближчу свободну вивчену ячейку у радіусі $maxRadius.
     *
     * @return array{map_id: int, x: int, y: int, distance: float}|null
     */
    public function findClosest(int $charId, int $targetX, int $targetY, int $maxRadius = 1024): ?array
    {
        $r           = 16;
        $closest     = null;
        $closestDist = PHP_INT_MAX;

        while ($r <= $maxRadius) {
            $sql = "SELECT m.id AS map_id, m.coordinate_x, m.coordinate_y
                    FROM explored_cells ec
                    JOIN map m ON m.id = ec.map_cell_id
                    WHERE ec.character_id=:charId:
                      AND m.coordinate_x BETWEEN :xmin: AND :xmax:
                      AND m.coordinate_y BETWEEN :ymin: AND :ymax:";
            $bind = [
                'charId' => $charId,
                'xmin'   => $targetX - $r,
                'xmax'   => $targetX + $r,
                'ymin'   => $targetY - $r,
                'ymax'   => $targetY + $r,
            ];
            $query = $this->db->query($sql, $bind);
            if (!$query instanceof \CodeIgniter\Database\BaseResult) {
                return null;
            }
            $results = $query->getResultArray();

            if (!empty($results)) {
                foreach ($results as $row) {
                    $mapId  = (int) $row['map_id'];
                    $reason = '';
                    if (!$this->validator->isCellAvailableForRelocation($charId, $mapId, $reason)) {
                        continue;
                    }
                    $dx   = $row['coordinate_x'] - $targetX;
                    $dy   = $row['coordinate_y'] - $targetY;
                    $dist = sqrt($dx * $dx + $dy * $dy);
                    if ($dist < $closestDist) {
                        $closestDist = $dist;
                        $closest = [
                            'map_id'   => $mapId,
                            'x'        => (int) $row['coordinate_x'],
                            'y'        => (int) $row['coordinate_y'],
                            'distance' => $dist,
                        ];
                    }
                }
                if ($closest) {
                    break;
                }
            }
            $r *= 2;
        }
        return $closest;
    }
}

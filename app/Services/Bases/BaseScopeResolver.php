<?php

namespace App\Services\Bases;

use App\Models\ClaimedCellModel;
use App\Services\Coverage\CommunicationTowerCoverageService;

/**
 * story angela-second-base-bugs-07 — единственное место, где принимается решение
 * «с какой базой работает этот экран». Раньше четырнадцать карточек зданий,
 * валидатор апгрейда и generic-апгрейд звали `ClaimedCellModel::resolveTargetBaseCell()`
 * напрямую и на `null` отвечали ОДНИМ текстом на ДВА разных состояния — «баз нет» и
 * «баз несколько» — что для игрока без единой базы было прямой ложью.
 *
 * Правило резолва (см. `## Contracts` плана angela-second-base-bugs):
 *  - игрок стоит на своей активной базе → эта клетка;
 *  - иначе, если активных баз нет → отказ `no_bases`;
 *  - иначе, если игрока покрывает сигнал Вышки связи → первая активная база по `id`
 *    (`ClaimedCellModel::findAllActiveCells()` уже даёт этот порядок) — та же база,
 *    которую после фикса выбирает `DetailedBaseInfoAction`;
 *  - иначе → отказ `ambiguous`.
 *
 * `resolve()` возвращает `cell` (map_cell_id целевой базы) либо `null` с типизированной
 * `reason` и готовым текстом отказа `text` — единым для всех дверей.
 */
class BaseScopeResolver
{
    public const REASON_NO_BASES = 'no_bases';
    public const REASON_AMBIGUOUS = 'ambiguous';

    public const TEXT_NO_BASES = 'Базы у тебя сейчас нет. Разбей лагерь — и постройки появятся на этом экране.';
    public const TEXT_AMBIGUOUS = 'Баз у тебя несколько. Встань на ту базу, с которой работаешь, — и открой экран снова.';

    private ClaimedCellModel $claimedCellModel;
    private CommunicationTowerCoverageService $towerService;

    public function __construct(
        ?ClaimedCellModel $claimedCellModel = null,
        ?CommunicationTowerCoverageService $towerService = null
    ) {
        $this->claimedCellModel = $claimedCellModel ?? new ClaimedCellModel();
        $this->towerService     = $towerService ?? new CommunicationTowerCoverageService();
    }

    /**
     * @return array{cell: int|null, reason: string|null, text: string|null}
     */
    public function resolve(int $characterId, int $currentCell): array
    {
        $targetCell = $this->claimedCellModel->resolveTargetBaseCell($characterId, $currentCell);
        if ($targetCell !== null) {
            return ['cell' => $targetCell, 'reason' => null, 'text' => null];
        }

        $activeCells = $this->claimedCellModel->findAllActiveCells($characterId);
        if ($activeCells === []) {
            return ['cell' => null, 'reason' => self::REASON_NO_BASES, 'text' => self::TEXT_NO_BASES];
        }

        $coverage = $this->towerService->checkCoverage($characterId);
        if (($coverage['isCovered'] ?? false) === true) {
            $firstActiveCell = $activeCells[0]['map_cell_id'] ?? null;
            if (is_numeric($firstActiveCell)) {
                return ['cell' => (int) $firstActiveCell, 'reason' => null, 'text' => null];
            }
        }

        return ['cell' => null, 'reason' => self::REASON_AMBIGUOUS, 'text' => self::TEXT_AMBIGUOUS];
    }
}

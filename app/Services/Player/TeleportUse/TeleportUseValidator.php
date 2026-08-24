<?php

namespace App\Services\Player\TeleportUse;

use App\Entities\CharacterEntity;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\MapModel;
use App\Services\Player\TeleportCostService;
use DateTime;

/**
 * v0.51.63 (TeleportUseAction decomp Step 1) — extract повну validation
 * для 4 teleport variants (Backpack/Gold/Portable/Experience).
 *
 * Public API (per variant):
 *   validateBackpack(character, ?claimedCellId)   : array{ok:bool, error?:string, reason?:string, bases?:array, context?:array}
 *   validateGold(character, ?claimedCellId)       : array{ok:bool, error?:string, reason?:string, bases?:array, context?:array}
 *   validatePortable(character, ?claimedCellId)   : array{ok:bool, error?:string, reason?:string, bases?:array, context?:array}
 *   validateExperience(character, ?claimedCellId) : array{ok:bool, error?:string, reason?:string, bases?:array, context?:array}
 *
 * story backpack-teleport-base-choice-01 — $claimedCellId выбирает целевую базу явно
 * (при нескольких активных базах); без него и ≥2 активных баз возвращается
 * {ok:false, reason:'choose_base', bases:[...]} (см. listActiveBases()).
 *
 * Context payloads (when ok=true):
 *   backpack:   {backpackItem, backpackLog, claimedCell, mapRow, customData}
 *   gold:       {charRow, claimedCell, mapRow, cost}
 *   portable:   {portableItem, portableLog, claimedCell, mapRow}
 *   experience: {charRow, claimedCell, mapRow}
 *
 * Behavior preservation: 1:1 з legacy TeleportUseAction (lines 77-435 у v0.51.62).
 * Помилки-тексти ідентичні. Quirks збережено:
 *   - Portable: всі fail-paths повертають той самий generic error
 *     («У тебя нет портативного телепорта») незалежно чи поломка у
 *     item lookup, log lookup, claimed cell або map row.
 *   - Experience: fail-paths повертають єдиний generic error
 *     («У тебя недостаточно опыта»).
 */
class TeleportUseValidator
{
    public const BACKPACK_COOLDOWN_MIN = 60;
    public const EXPERIENCE_COST       = 1.0;
    public const EXPERIENCE_THRESHOLD  = 1.01;

    private CraftedItemsModel $craftedItemModel;
    private CraftedItemsLogModel $craftedItemLogModel;
    private CharacterModel $characterModel;
    private ClaimedCellModel $claimedCellModel;
    private MapModel $mapModel;
    private TeleportCostService $teleportCostService;

    public function __construct(
        ?CraftedItemsModel $craftedItemModel = null,
        ?CraftedItemsLogModel $craftedItemLogModel = null,
        ?CharacterModel $characterModel = null,
        ?ClaimedCellModel $claimedCellModel = null,
        ?MapModel $mapModel = null,
        ?TeleportCostService $teleportCostService = null
    ) {
        $this->craftedItemModel    = $craftedItemModel    ?? new CraftedItemsModel();
        $this->craftedItemLogModel = $craftedItemLogModel ?? new CraftedItemsLogModel();
        $this->characterModel      = $characterModel      ?? new CharacterModel();
        $this->claimedCellModel    = $claimedCellModel    ?? new ClaimedCellModel();
        $this->mapModel            = $mapModel            ?? new MapModel();
        $this->teleportCostService = $teleportCostService ?? new TeleportCostService();
    }

    /**
     * @param array<string,mixed>|CharacterEntity $character
     * @return array<string,mixed>
     */
    public function validateBackpack(array|CharacterEntity $character, ?int $claimedCellId = null): array
    {
        $backpackItem = $this->craftedItemModel->where('name_eng', 'TeleportBackpack')->first();
        if (!$backpackItem) {
            return ['ok' => false, 'error' => "Ошибка: предмет 'TeleportBackpack' не найден в базе."];
        }

        $backpackLog = $this->craftedItemLogModel
            ->where('crafted_item_id', $backpackItem['id'])
            ->where('character_id', $character['id'])
            ->first();
        if (!$backpackLog) {
            return ['ok' => false, 'error' => "У тебя нет рюкзака для телепорта!"];
        }

        if (((int) $backpackLog['quantity'] < 1) || ((int) $backpackLog['durability_count'] < 1)) {
            return ['ok' => false, 'error' => "Рюкзак больше не пригоден для телепортации (нет зарядов)."];
        }

        $customData = [];
        if (!empty($backpackLog['custom_setting'])) {
            $decoded = json_decode((string) $backpackLog['custom_setting'], true);
            if (is_array($decoded)) {
                $customData = $decoded;
            }
        }

        $lastUsedAtStr = $customData['lastUsedAt'] ?? null;
        if ($lastUsedAtStr) {
            $lastUsedTime = new DateTime((string) $lastUsedAtStr);
            $now          = new DateTime();
            $diff         = $now->diff($lastUsedTime);
            $minutesPassed = $diff->days * 24 * 60 + $diff->h * 60 + $diff->i;

            if ($minutesPassed < self::BACKPACK_COOLDOWN_MIN) {
                $remaining = self::BACKPACK_COOLDOWN_MIN - $minutesPassed;
                return [
                    'ok'    => false,
                    'error' => "Ты уже использовал рюкзак! Повторный телепорт будет доступен через ~{$remaining} мин.",
                ];
            }
        }

        $location = $this->findBaseLocation((int) $character['id'], $claimedCellId);
        if (!$location['ok']) {
            if (isset($location['reason'])) {
                return $this->chooseBaseResult($location);
            }
            $err = $location['error'] ?? "Ошибка базы.";
            // Backpack: legacy text "У тебя нет базы, куда телепортироваться!" замість generic.
            if ($err === 'no_claimed_cell') {
                return ['ok' => false, 'error' => "У тебя нет базы, куда телепортироваться!"];
            }
            if ($err === 'no_map_row') {
                return ['ok' => false, 'error' => "Ошибка: не найдена ячейка базы на карте."];
            }
            return ['ok' => false, 'error' => $err];
        }

        return [
            'ok'      => true,
            'context' => [
                'backpackItem' => $backpackItem,
                'backpackLog'  => $backpackLog,
                'claimedCell'  => $location['claimedCell'],
                'mapRow'       => $location['mapRow'],
                'customData'   => $customData,
            ],
        ];
    }

    /**
     * @param array<string,mixed>|CharacterEntity $character
     * @return array<string,mixed>
     */
    public function validateGold(array|CharacterEntity $character, ?int $claimedCellId = null): array
    {
        $charRow = $this->characterModel->find((int) $character['id']);
        if (!$charRow) {
            return ['ok' => false, 'error' => "Ошибка! Персонаж не найден."];
        }

        $cost = $this->teleportCostService->calculateTeleportCost((int) $charRow['level'], (int) $charRow['id']);
        if ((int) $charRow['gold'] < $cost) {
            return [
                'ok'    => false,
                'error' => "Недостаточно золота! Нужно {$cost}, а у тебя всего {$charRow['gold']}.",
            ];
        }

        $location = $this->findBaseLocation((int) $charRow['id'], $claimedCellId);
        if (!$location['ok']) {
            if (isset($location['reason'])) {
                return $this->chooseBaseResult($location);
            }
            $err = $location['error'] ?? "Ошибка базы.";
            if ($err === 'no_claimed_cell') {
                return ['ok' => false, 'error' => "У тебя нет базы для телепорта!"];
            }
            if ($err === 'no_map_row') {
                return ['ok' => false, 'error' => "Ошибка: не найдена ячейка базы на карте."];
            }
            return ['ok' => false, 'error' => $err];
        }

        return [
            'ok'      => true,
            'context' => [
                'charRow'     => $charRow,
                'claimedCell' => $location['claimedCell'],
                'mapRow'      => $location['mapRow'],
                'cost'        => $cost,
            ],
        ];
    }

    /**
     * Portable: legacy-quirk preservation — всі fail-paths повертають той самий
     * generic error "У тебя нет портативного телепорта."
     *
     * @param array<string,mixed>|CharacterEntity $character
     * @return array<string,mixed>
     */
    public function validatePortable(array|CharacterEntity $character, ?int $claimedCellId = null): array
    {
        $portableItem = $this->craftedItemModel->where('name_eng', 'PortableTeleport')->first();
        if (!$portableItem) {
            return ['ok' => false, 'error' => "У тебя нет портативного телепорта."];
        }

        $portableLog = $this->craftedItemLogModel
            ->where('crafted_item_id', $portableItem['id'])
            ->where('character_id', $character['id'])
            ->first();
        if (!$portableLog) {
            return ['ok' => false, 'error' => "У тебя нет портативного телепорта."];
        }

        // 2026-08-06: пока предмет был недостижим (рецепта не существовало), проверки
        // зарядов не было вовсе — пустое устройство сработало бы «бесплатно» и исчезло.
        // Теперь его крафтят, поэтому заряды проверяем явно.
        $portableQty     = (is_array($portableLog) && is_numeric($portableLog['quantity'] ?? null))
            ? (int) $portableLog['quantity'] : 0;
        $portableCharges = (is_array($portableLog) && is_numeric($portableLog['durability_count'] ?? null))
            ? (int) $portableLog['durability_count'] : 0;
        if ($portableQty < 1 || $portableCharges < 1) {
            return ['ok' => false, 'error' => "Портативный телепорт разряжен — заряды кончились."];
        }

        $location = $this->findBaseLocation((int) $character['id'], $claimedCellId);
        if (!$location['ok']) {
            if (isset($location['reason'])) {
                return $this->chooseBaseResult($location);
            }
            return ['ok' => false, 'error' => "У тебя нет портативного телепорта."];
        }

        return [
            'ok'      => true,
            'context' => [
                'portableItem' => $portableItem,
                'portableLog'  => $portableLog,
                'claimedCell'  => $location['claimedCell'],
                'mapRow'       => $location['mapRow'],
            ],
        ];
    }

    /**
     * Experience: legacy-quirk preservation — всі fail-paths повертають той самий
     * generic error "У тебя недостаточно опыта для телепортации."
     *
     * @param array<string,mixed>|CharacterEntity $character
     * @return array<string,mixed>
     */
    public function validateExperience(array|CharacterEntity $character, ?int $claimedCellId = null): array
    {
        $charRow = $this->characterModel->find((int) $character['id']);
        if (!$charRow || (float) $charRow['experience'] <= self::EXPERIENCE_THRESHOLD) {
            return ['ok' => false, 'error' => "У тебя недостаточно опыта для телепортации."];
        }

        $location = $this->findBaseLocation((int) $charRow['id'], $claimedCellId);
        if (!$location['ok']) {
            if (isset($location['reason'])) {
                return $this->chooseBaseResult($location);
            }
            return ['ok' => false, 'error' => "У тебя недостаточно опыта для телепортации."];
        }

        return [
            'ok'      => true,
            'context' => [
                'charRow'     => $charRow,
                'claimedCell' => $location['claimedCell'],
                'mapRow'      => $location['mapRow'],
            ],
        ];
    }

    /**
     * story backpack-teleport-base-choice-01 — активные базы персонажа, в порядке id.
     * Заброшенные (`status='abandoned'`) базы в список не попадают.
     *
     * story backpack-teleport-base-choice-04 (ревью №4) — счёт баз и цель для ровно
     * одной активной базы идут через канон `ClaimedCellModel::countActiveBases()` /
     * `findFirstActiveCell()` (ADR-102). Этот метод — список ВСЕХ активных баз,
     * обогащённый координатами из `map` — остаётся здесь, а не в модели: обогащение
     * чужой таблицей (`map`) не забота `ClaimedCellModel`, у него нет зависимости
     * на `MapModel`, и это разовый список для одного экрана телепорта, а не общий
     * канон-запрос, переиспользуемый другими сервисами.
     *
     * @return array<int, array{id:int, map_cell_id:int, camp_name:mixed, coordinate_x:mixed, coordinate_y:mixed}>
     */
    public function listActiveBases(int $characterId): array
    {
        $cells = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->orderBy('id', 'ASC')
            ->findAll();

        $bases = [];
        foreach ($cells as $cell) {
            if (!is_array($cell)) {
                continue;
            }
            $cell = $this->normalizeRow($cell);

            $mapRowRaw = $this->mapModel->where('cell_number', $cell['map_cell_id'] ?? null)->first();
            $mapRow    = is_array($mapRowRaw) ? $this->normalizeRow($mapRowRaw) : [];

            $idRaw    = $cell['id'] ?? null;
            $cellIdRaw = $cell['map_cell_id'] ?? null;

            $bases[] = [
                'id'           => is_numeric($idRaw) ? (int) $idRaw : 0,
                'map_cell_id'  => is_numeric($cellIdRaw) ? (int) $cellIdRaw : 0,
                'camp_name'    => $cell['camp_name'] ?? null,
                'coordinate_x' => $mapRow['coordinate_x'] ?? null,
                'coordinate_y' => $mapRow['coordinate_y'] ?? null,
            ];
        }

        return $bases;
    }

    /**
     * story backpack-teleport-base-choice-01 — унифицированный wrap для `reason=no_base`
     * и `reason=choose_base` из findBaseLocation() в форму, которую отдают validate*.
     *
     * @param array{reason:string, bases?:array<int,array<string,mixed>>} $location
     * @return array<string,mixed>
     */
    private function chooseBaseResult(array $location): array
    {
        $result = ['ok' => false, 'reason' => $location['reason']];
        if (isset($location['bases'])) {
            $result['bases'] = $location['bases'];
        }
        return $result;
    }

    /**
     * Common helper: find claimed_cell + map_row for given character.
     *
     * story backpack-teleport-base-choice-01 — только `status='active'`, с явным
     * выбором по $claimedCellId (id должен принадлежать персонажу и быть active,
     * иначе reason=no_base); без id и ≥2 активных баз — reason=choose_base + bases
     * (см. listActiveBases()); без id и ровно 1 активная база — поведение как раньше;
     * без id и 0 активных баз — тот же error=no_claimed_cell, что и до story (Non-goal:
     * не трогаем PlayerRespawner и другие bare-first() по claimed_cells).
     *
     * @return array{ok:bool, error?:string, reason?:string, bases?:array<int,array<string,mixed>>, claimedCell?:array<string,mixed>, mapRow?:array<string,mixed>}
     */
    private function findBaseLocation(int $characterId, ?int $claimedCellId = null): array
    {
        if ($claimedCellId !== null) {
            $claimedCellRaw = $this->claimedCellModel
                ->where('id', $claimedCellId)
                ->where('character_id', $characterId)
                ->where('status', 'active')
                ->first();
            if (!is_array($claimedCellRaw)) {
                return ['ok' => false, 'reason' => 'no_base'];
            }

            return $this->resolveMapRow($this->normalizeRow($claimedCellRaw));
        }

        // story backpack-teleport-base-choice-04 (ревью №4) — канон-хелперы
        // ClaimedCellModel::countActiveBases()/findFirstActiveCell() (ADR-102)
        // вместо дублирующих сырых where()->first()/countAllResults().
        $activeCount = $this->claimedCellModel->countActiveBases($characterId);

        if ($activeCount === 0) {
            return ['ok' => false, 'error' => 'no_claimed_cell'];
        }

        if ($activeCount >= 2) {
            return ['ok' => false, 'reason' => 'choose_base', 'bases' => $this->listActiveBases($characterId)];
        }

        $claimedCell = $this->claimedCellModel->findFirstActiveCell($characterId);
        if ($claimedCell === null) {
            return ['ok' => false, 'error' => 'no_claimed_cell'];
        }

        return $this->resolveMapRow($claimedCell);
    }

    /**
     * story backpack-teleport-base-choice-01 — вынесенный общий хвост findBaseLocation():
     * map-строка по claimed_cell + сборка успешного результата.
     *
     * @param array<string,mixed> $claimedCell
     * @return array{ok:bool, error?:string, claimedCell?:array<string,mixed>, mapRow?:array<string,mixed>}
     */
    private function resolveMapRow(array $claimedCell): array
    {
        $mapRowRaw = $this->mapModel->where('cell_number', $claimedCell['map_cell_id'] ?? null)->first();
        if (!is_array($mapRowRaw)) {
            return ['ok' => false, 'error' => 'no_map_row'];
        }

        return [
            'ok'          => true,
            'claimedCell' => $claimedCell,
            'mapRow'      => $this->normalizeRow($mapRowRaw),
        ];
    }

    /**
     * story backpack-teleport-base-choice-04 (ревью №11) — CI4 `Model::first()/findAll()`
     * возвращают `array<int|string,mixed>|object` статически; после `is_array()`-сужения
     * приводим ключи к `string`, чтобы дальше обращаться к офсетам без phpstan-подавлений
     * (тот же паттерн, что `ClaimedCellModel::normalizeKeys()`, локальная копия — Non-goal
     * story 01 запрещает трогать `ClaimedCellModel`).
     *
     * @param array<int|string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[(string) $key] = $value;
        }
        return $out;
    }
}

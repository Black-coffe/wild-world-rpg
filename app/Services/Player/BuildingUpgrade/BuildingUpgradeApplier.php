<?php

namespace App\Services\Player\BuildingUpgrade;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\ResourceModel;
use App\Services\Player\ResourcePoolService;
use App\Services\PVE\DefenseStructureService;

/**
 * v0.51.60 (UpgradeBuildingAction decomp Step 3) — extract DB write block
 * у dedicated applier service: gold subtract + resources decrease + level bump.
 *
 * Public API:
 *   apply(character, charBuilding, nextLevel, requirements): void
 *
 * Pre-conditions: caller already validated through BuildingUpgradeValidator —
 * character has gold/level/resources, building exists, nextLevel reachable.
 *
 * Current sequence (insurance-06: ресурсы теперь первым шагом, было золото →
 * ресурсы → level):
 *  1. for each (name_en, qty) in resources: `ResourcePoolService::consume(...)`
 *     (рюкзак сначала, остаток со склада; при нехватке бросает `RuntimeException`
 *     и не списывает ничего)
 *  2. characters.gold -= requirements.gold (через `CharacterStatsService::adjust()`)
 *  3. character_buildings.level = nextLevel
 *
 * ADR-171 + ревью-фикс: все шаги — под одной `$db->transStart()`/`transComplete()`
 * (тот же приём, что и `GenericCraftActionStart` в story -05). `ResourcePoolService::consume()`
 * атомарен: при нехватке НЕ списывает ничего и бросает `RuntimeException`. Раньше это глушилось
 * логом внутри цикла — level bump применялся, ничего не заплатив за ресурс (хуже прежнего
 * удалённого `CharacterResourceModel::decreaseResources()`, который хотя бы недоплачивал, но
 * списывал что было — метод убран в exploit-fix-06, заменён условной записью).
 *
 * insurance-06: порядок шагов — РЕСУРСЫ → золото → level, а не золото → ресурсы → level, как
 * было раньше. При отказе на ресурсах (гонка/нехватка) golden-adjust и level-bump физически не
 * достигаются — не только не коммитятся транзакцией, но и не выполняются вовсе. Обратный порядок
 * (золото первым) был бы зеркалом исходной дыры: не «эффект без оплаты», а «оплата без эффекта» —
 * игрок платит золотом за апгрейд, который не состоялся. Гонка ломает `apply()` целиком: откат
 * транзакции, исключение улетает вызывающему, ничего не применено и не списано.
 *
 * Resource lookup tolerates missing name_en (skip silently — same as legacy).
 */
class BuildingUpgradeApplier
{
    private ResourceModel $resourceModel;
    private CharacterBuildingModel $characterBuildingModel;
    private BuildingModel $buildingModel;
    private DefenseStructureService $defenseService;
    private ResourcePoolService $resourcePool;
    private \App\Services\Player\CharacterStatsService $statsService;

    public function __construct(
        ?ResourceModel $resourceModel = null,
        ?CharacterBuildingModel $characterBuildingModel = null,
        ?BuildingModel $buildingModel = null,
        ?DefenseStructureService $defenseService = null,
        ?ResourcePoolService $resourcePool = null,
        ?\App\Services\Player\CharacterStatsService $statsService = null
    ) {
        $this->resourceModel          = $resourceModel          ?? new ResourceModel();
        $this->characterBuildingModel = $characterBuildingModel ?? new CharacterBuildingModel();
        $this->buildingModel          = $buildingModel          ?? new BuildingModel();
        $this->defenseService         = $defenseService         ?? new DefenseStructureService();
        $this->resourcePool           = $resourcePool           ?? new ResourcePoolService();
        $this->statsService           = $statsService           ?? new \App\Services\Player\CharacterStatsService();
    }

    /**
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character    Row/Entity з 'id' і 'gold'
     * @param array<string,mixed>     $charBuilding Row from character_buildings з 'id'
     * @param int                     $nextLevel    Target level (currentLevel + 1)
     * @param array{gold:int,resources:array<string,int>} $requirements
     *
     * @throws \RuntimeException при гонке на списании ресурса (пул успел забрать остаток
     *                           между Validator и этим вызовом) — транзакция уже откачена.
     */
    public function apply(array|\App\Entities\CharacterEntity $character, array $charBuilding, int $nextLevel, array $requirements): void
    {
        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        $db = \Config\Database::connect();
        $db->transStart();

        // insurance-06: ресурсы — ПЕРВЫЙ шаг. Если пул бросает (гонка/нехватка),
        // ловим здесь же и выходим ДО того, как золото или уровень тронуты —
        // строки ниже физически не выполняются, не только откатываются.
        try {
            $this->deductResources($charId, $requirements['resources']);
        } catch (\RuntimeException $e) {
            $db->transRollback();
            log_message('error', "[BuildingUpgradeApplier] пул словил гонку при списании для character {$charId}: " . $e->getMessage());
            throw $e;
        }

        // Fix 2026-07-13 (класс lost-update): атомарное относительное списание
        // от СВЕЖЕГО золота (CharacterStatsService), floor 0 — дефолтный. Своя
        // вложенная транзакция CharacterStatsService::adjust() физически не
        // коммитится, пока не завершится наша внешняя (CI4 nested transDepth).
        $this->statsService->adjust($charId, ['gold' => -(int) $requirements['gold']]);

        $update = ['level' => $nextLevel];

        // ADR-041: апгрейд оборонной структуры восстанавливает hp до НОВОГО maxHp
        // (выше уровень = крепче + полный ремонт «в подарок» при апгрейде).
        if (($charBuilding['building_type'] ?? '') === 'defensive') {
            $bidRaw     = $charBuilding['building_id'] ?? null;
            $tmpl       = $this->buildingModel->find(is_numeric($bidRaw) ? (int) $bidRaw : 0);
            $hpRaw      = is_array($tmpl) ? ($tmpl['hp'] ?? null) : null;
            $templateHp = is_numeric($hpRaw) ? (int) $hpRaw : 0;
            if ($templateHp > 0) {
                $update['hp'] = $this->defenseService->maxHpFor($templateHp, $nextLevel);
            }
        }

        $this->characterBuildingModel->update($charBuilding['id'], $update);

        $db->transComplete();

        // story-09 (ревью team-lead, дефект 3): `transComplete()` откатывает
        // без исключения на сбойном запросе / дедлоке / чужой более ранней
        // неудаче в этом же request'е под `transStrict` — раньше это не
        // проверялось, и `apply()` возвращала void независимо от исхода:
        // `UpgradeBuildingAction` рапортовала «уровень повышен» при прежнем
        // уровне и уже начисленном faction-score hook'е. Бросаем так же, как
        // и при гонке на ресурсах выше — вызывающий отвечает игроку текстом.
        if ($db->transStatus() === false) {
            log_message('error', "[BuildingUpgradeApplier] транзакция апгрейда откачена без исключения для character {$charId}");
            throw new \RuntimeException('Транзакция апгрейда откачена без исключения (оплата + level).');
        }
    }

    /**
     * Списание через тот же пул, что и проверка достаточности в
     * `BuildingUpgradeValidator` — рюкзак сначала, остаток со склада.
     *
     * Резолвит id ВСЕХ ресурсов ОДНИМ `whereIn()`-запросом до цикла, а не
     * `where()->first()` на переиспользуемой модели внутри цикла — CI4 Model
     * builder state не гарантированно сбрасывается между вызовами в loop'е
     * (см. memory `ci4-model-builder-state-quirk`, живой инцидент S5b: второй
     * ресурс в цикле молча не находился из-за накопленного `AND` в builder'е —
     * для рецепта с 2+ ресурсами это тихо ронявшая оплата вторых-и-далее строк).
     *
     * Исключение из `consume()` НЕ глушится — пробрасывается вызывающему
     * (`apply()`), который откатывает транзакцию целиком.
     *
     * @param array<string,int> $resources name_en => qty
     */
    private function deductResources(int $characterId, array $resources): void
    {
        $names = [];
        foreach ($resources as $resNameEn => $needQty) {
            if ($needQty > 0) {
                $names[] = $resNameEn;
            }
        }
        if ($names === []) {
            return;
        }

        $idByName = [];
        foreach ($this->resourceModel->whereIn('name_en', $names)->findAll() as $row) {
            $r      = $row->toArray();
            $nameEn = is_string($r['name_en'] ?? null) ? $r['name_en'] : null;
            $id     = is_numeric($r['id'] ?? null) ? (int) $r['id'] : null;
            if ($nameEn !== null && $id !== null) {
                $idByName[$nameEn] = $id;
            }
        }

        foreach ($resources as $resNameEn => $needQty) {
            if ($needQty <= 0) {
                continue;
            }
            $resourceId = $idByName[$resNameEn] ?? null;
            if ($resourceId === null) {
                continue; // неизвестный ресурс — молчаливый skip, как и раньше
            }
            $this->resourcePool->consume($characterId, $resourceId, $needQty);
        }
    }
}

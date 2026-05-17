<?php

declare(strict_types=1);

namespace App\Services\Player\Gather;

use App\Libraries\ToolManager;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;

/**
 * F2.7b — батч-загрузка инструментов персонажа + расход прочности.
 *
 * Решает N+1 в GatherTaskHandler::calculateFoundResources:
 *   - legacy: 2 SQL × ~20 (resources × tools) × ~23 блока = ~920 запросов на одну добычу.
 *   - после: 2 SQL единожды + 1 SQL refresh после расхода (всего ~2 + 5×blocks).
 *
 * Контракт совпадает с `CraftedItemsLogModel::getItemByNameEngAndCharacterId`:
 *   возвращает первую найденную запись лога инструмента или null.
 *
 * Зависимости инжектятся опционально для тестируемости (DI контракт F2.6/F2.8).
 */
final class ToolDurabilityProcessor
{
    private CraftedItemsLogModel $craftedItemsLogModel;
    private CraftedItemsModel $craftedItemsModel;
    private ToolManager $toolManager;

    /**
     * S4 (v0.51.186+): имена инструментов, у которых в течение текущей сессии
     * закончилась последняя единица (durability=0 при quantity=1 → row deleted
     * у `crafted_items_log`). Используется `GatherTaskHandler` для рендера
     * уведомления «Инструменты сломались» в reply gather.
     *
     * @var array<string, bool>
     */
    private array $brokenTools = [];

    public function __construct(
        ?CraftedItemsLogModel $craftedItemsLogModel = null,
        ?CraftedItemsModel $craftedItemsModel = null,
        ?ToolManager $toolManager = null,
    ) {
        $this->craftedItemsLogModel = $craftedItemsLogModel ?? new CraftedItemsLogModel();
        $this->craftedItemsModel    = $craftedItemsModel    ?? new CraftedItemsModel();
        $this->toolManager          = $toolManager          ?? new ToolManager();
    }

    /**
     * S4: список имён инструментов, сломавшихся в текущей сессии.
     *
     * @return array<string, bool>
     */
    public function getBrokenTools(): array
    {
        return $this->brokenTools;
    }

    /**
     * S4: сбросить список сломанных в начале новой сессии.
     */
    public function clearBrokenTools(): void
    {
        $this->brokenTools = [];
    }

    /**
     * Один batch для всех инструментов персонажа, релевантных хотя бы для одного
     * ресурса из списка. Возвращает map [name_eng => log_row]. Если у персонажа
     * нет ни одного релевантного инструмента — возвращает [].
     */
    public function loadAvailableTools(int $characterId, array $resourceNames): array
    {
        $allToolNames = [];
        foreach ($resourceNames as $resourceName) {
            $mapping = $this->toolManager->getToolsForResource($resourceName);
            foreach ($mapping as $toolName => $bonus) {
                $allToolNames[$toolName] = true;
            }
        }
        if (empty($allToolNames)) {
            return [];
        }

        $names = array_keys($allToolNames);

        $items = $this->craftedItemsModel
            ->whereIn('name_eng', $names)
            ->findAll();
        if (empty($items)) {
            return [];
        }

        $idByName = [];
        foreach ($items as $item) {
            $idByName[$item['name_eng']] = $item['id'];
        }
        $idToName = array_flip($idByName);

        $logRows = $this->craftedItemsLogModel
            ->whereIn('crafted_item_id', array_values($idByName))
            ->where('character_id', $characterId)
            ->findAll();

        $byName = [];
        foreach ($logRows as $row) {
            $name = $idToName[$row['crafted_item_id']] ?? null;
            if ($name === null) {
                continue;
            }
            if (!isset($byName[$name])) {
                $byName[$name] = $row;
            }
        }
        return $byName;
    }

    /**
     * Расходует 1 единицу прочности у инструмента и обновляет cache по имени.
     * Контракт идентичен `ToolManager::updateToolDurability`:
     *   true  — инструмент еще цел / переключился на следующий стек,
     *   false — инструмент закончился и удалён.
     *
     * После расхода cache переcинхронизируется ОДНИМ свежим чтением
     * (вместо повторного inner-loop fetch'а в legacy).
     *
     * Mutates $availableTools by reference.
     */
    public function consumeAndRefresh(string $toolName, array &$availableTools, int $characterId): bool
    {
        if (!isset($availableTools[$toolName])) {
            return false;
        }
        $row = $availableTools[$toolName];

        $ok = $this->toolManager->updateToolDurability($row);
        if (!$ok) {
            // S4: инструмент окончательно сломался (последняя единица стека
            // исчерпала прочность → row удалён). Фиксируем для уведомления.
            $this->brokenTools[$toolName] = true;
            unset($availableTools[$toolName]);
            return false;
        }

        $fresh = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($toolName, $characterId);
        if ($fresh) {
            $availableTools[$toolName] = $fresh;
        } else {
            unset($availableTools[$toolName]);
        }
        return true;
    }

    /**
     * Чистая функция выбора лучшего инструмента из in-memory cache.
     * Возвращает [name_eng, bonus] либо null если ни один инструмент из mapping
     * не доступен в cache.
     */
    public function pickBestTool(string $resourceName, array $availableTools): ?array
    {
        $mapping = $this->toolManager->getToolsForResource($resourceName);
        if (empty($mapping)) {
            return null;
        }

        $bestName  = null;
        $bestBonus = 0.0;
        foreach ($mapping as $toolName => $bonus) {
            if (!isset($availableTools[$toolName])) {
                continue;
            }
            if ($bonus > $bestBonus) {
                $bestBonus = $bonus;
                $bestName  = $toolName;
            }
        }
        if ($bestName === null) {
            return null;
        }
        return ['name' => $bestName, 'bonus' => $bestBonus];
    }
}

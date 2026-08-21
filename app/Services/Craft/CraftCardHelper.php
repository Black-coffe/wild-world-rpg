<?php

declare(strict_types=1);

namespace App\Services\Craft;

use App\Models\ResourceModel;
use App\Services\Player\ResourcePoolService;

/**
 * ADR-171 — единственное место, где карточка крафта считает доступное
 * количество ресурса. Использует тот же пул (рюкзак, а на базе при
 * `storage.pool_enabled` ещё и склад), которым потом считает старт крафта
 * `GenericCraftActionStart::checkResources()` — карточка перестаёт врать
 * игроку, стоящему на своей базе.
 *
 * Второе назначение — единый выход из тупика «нельзя собрать ни одной
 * штуки»: до этой стори карточки в этом случае прятали все кнопки количества
 * и оставляли игрока с текстом без единого действия вперёд.
 *
 * Story craft-shortfall-buy-01 — tracer, применён к одной карточке (Топор
 * дровосека из `LumberjackAxeCraft1Action`). У 27 карточек нет общего предка
 * (`getAvailableQuantityButtons()`/`makeQuantityButtons()` копируются в
 * каждой), поэтому помощник вызывается явно из каждой формы — остальные 26
 * применений едут отдельными story второй волны (`docs/specs/craft-shortfall-buy/plan.md`).
 */
class CraftCardHelper
{
    private ResourcePoolService $resourcePool;
    private ResourceModel $resourceModel;

    public function __construct(
        ?ResourcePoolService $resourcePool = null,
        ?ResourceModel $resourceModel = null
    ) {
        $this->resourcePool = $resourcePool ?? new ResourcePoolService();
        $this->resourceModel = $resourceModel ?? new ResourceModel();
    }

    /**
     * Сколько ресурса доступно персонажу прямо сейчас — по имени ресурса,
     * как их несёт `requiredResources` карточки. Считает `ResourcePoolService`:
     * тот же результат, что увидит `GenericCraftActionStart::checkResources()`
     * для того же персонажа и ресурса, и на базе, и в поле.
     *
     * @param  array<string,int> $requiredResources имя ресурса → нужно на 1 шт.
     * @return list<array{name:string,quantity:int,rarity:int|string}>
     */
    public function available(int $characterId, array $requiredResources): array
    {
        $results = [];
        foreach ($requiredResources as $name => $need) {
            $resource = $this->resourceModel->getResourceByName($name);
            $qty      = 0;
            $rarity   = 0;

            if ($resource !== null) {
                $rarity = is_object($resource) ? ($resource->rarity ?? 0) : ($resource['rarity'] ?? 0);
                $qty    = $this->resourcePool->availableByName($characterId, $name);
            }

            $results[] = [
                'name'     => $name,
                'quantity' => $qty,
                'rarity'   => $rarity,
            ];
        }

        return $results;
    }

    /**
     * Единственная кнопка-выход из тупика «нельзя собрать ни одной штуки».
     * Ведёт на тот же callback, что и обычная кнопка «Крафт 1 шт.» —
     * `GenericCraftActionStart` сам обнаружит нехватку и покажет экран
     * `CraftShortageService` (карта пути «чего не хватает» и где добыть/купить),
     * а не тупик. Показывать эту кнопку нужно ТОЛЬКО когда собрать нельзя
     * даже одну штуку — иначе она дублирует обычные кнопки количества.
     *
     * @return array{text:string,callback_data:string}
     */
    public function fallbackButton(string $recipeKey): array
    {
        return [
            'text'          => '🛒 Чего не хватает?',
            'callback_data' => 'genericCraft_' . $recipeKey . '_1',
        ];
    }
}

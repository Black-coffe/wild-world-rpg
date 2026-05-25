<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Player\RobotRepairService;
use Longman\TelegramBot\Entities\CallbackQuery;

/**
 * V19 (ADR-050) — общая логика ремонта роботов (preview + confirm).
 *
 * Ремонт восстанавливает durability ТЕКУЩЕГО (частично израсходованного) робота
 * до базового за долю стоимости крафта (RobotRepairService). Контекст (робот →
 * рецепт → log-строка → стоимость) собирается здесь; подклассы рендерят превью /
 * выполняют списание.
 */
abstract class RobotRepairBaseAction extends BaseAction
{
    protected CraftedItemsModel      $itemsModel;
    protected CraftedItemsLogModel   $logModel;
    protected CharacterResourceModel $charResources;
    protected RobotRepairService     $repairService;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->itemsModel    = new CraftedItemsModel();
        $this->logModel      = new CraftedItemsLogModel();
        $this->charResources = new CharacterResourceModel();
        $this->repairService = new RobotRepairService();
    }

    /** robot crafted_item_id из callback по префиксу. */
    protected function robotIdFrom(string $prefix): int
    {
        $raw = str_replace($prefix, '', (string) $this->callbackQuery->getData());
        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Собирает контекст ремонта. Все ключи присутствуют ВСЕГДА (на ошибке —
     * безопасные дефолты), чтобы caller'ы не упирались в optional-offset.
     *
     * @return array{ok:bool, full:bool, message:string, item:array<array-key,mixed>, recipe:array<array-key,mixed>, logRow:array<array-key,mixed>, current:int, base:int, cost:array{gold:int,resources:array<string,int>,restored:int}}
     */
    protected function buildContext(int $characterId, int $robotId): array
    {
        $fail = static function (string $message, bool $full = false): array {
            return [
                'ok' => false, 'full' => $full, 'message' => $message,
                'item' => [], 'recipe' => [], 'logRow' => [], 'current' => 0, 'base' => 0,
                'cost' => ['gold' => 0, 'resources' => [], 'restored' => 0],
            ];
        };

        if (! $this->repairService->enabled()) {
            return $fail('Ремонт роботов сейчас недоступен.');
        }

        $item = $this->itemsModel->find($robotId);
        if (! is_array($item)) {
            return $fail('Робот не найден.');
        }
        $type = is_string($item['type'] ?? null) ? $item['type'] : '';
        if ($type !== 'robots') {
            return $fail('Робот не найден.');
        }

        $nameEn = is_string($item['name_eng'] ?? null) ? $item['name_eng'] : '';
        $recipe = config(\Config\CraftRecipes::class)->get($nameEn);
        if (! is_array($recipe)) {
            return $fail('Рецепт этого робота не найден — ремонт невозможен.');
        }

        $logRow = $this->logModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $robotId)
            ->where('quantity >', 0)
            ->orderBy('id', 'ASC')
            ->first();
        if (! is_array($logRow)) {
            return $fail('У тебя нет роботов этого типа.');
        }

        $current = isset($logRow['durability_count']) && is_numeric($logRow['durability_count']) ? (int) $logRow['durability_count'] : 0;
        $base    = isset($item['durability_count']) && is_numeric($item['durability_count']) ? (int) $item['durability_count'] : 0;
        if ($base <= 0) {
            return $fail('Ошибка: у робота не задана базовая прочность.');
        }
        if ($current >= $base) {
            return $fail('Этот робот уже в полной исправности — ремонт не нужен.', true);
        }

        return [
            'ok'      => true,
            'full'    => false,
            'message' => '',
            'item'    => $item,
            'recipe'  => $recipe,
            'logRow'  => $logRow,
            'current' => $current,
            'base'    => $base,
            'cost'    => $this->repairService->computeCost($recipe, $current, $base),
        ];
    }

    /** Сколько ресурса у персонажа. */
    protected function resourceHave(string $name, int $characterId): int
    {
        $row = $this->charResources->getResourceByNameAndCharacterId($name, $characterId);
        return is_array($row) && isset($row['quantity']) && is_numeric($row['quantity']) ? (int) $row['quantity'] : 0;
    }

    /**
     * Хватает ли ресурсов + золота на ремонт.
     *
     * @param array{gold:int,resources:array<string,int>,restored:int} $cost
     * @param array<array-key,mixed> $character
     */
    protected function canAfford(array $cost, array $character, int $characterId): bool
    {
        $gold = is_numeric($character['gold'] ?? null) ? (int) $character['gold'] : 0;
        if ($gold < $cost['gold']) {
            return false;
        }
        foreach ($cost['resources'] as $name => $need) {
            if ($this->resourceHave($name, $characterId) < $need) {
                return false;
            }
        }
        return true;
    }
}

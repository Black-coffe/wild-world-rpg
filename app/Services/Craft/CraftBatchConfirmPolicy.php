<?php

declare(strict_types=1);

namespace App\Services\Craft;

use App\Services\GameSettings\GameSettingsService;

/**
 * craft-batch-price-confirm — когда партия крафта требует явного подтверждения игрока.
 *
 * Жалоба 05.10.2026: «Крафт 50шт» одним нажатием списал 300 000 золота и занял 59 ч, игрок не понял,
 * куда ушли деньги. Порог проверяет ЯДРО ({@see CraftOrderService::start()}), а не рендерер: старт
 * вызывают и бот, и `/play`, и правило в одном клиенте было бы дырой в другом.
 *
 * Подтверждение нужно, если выполнено ЛЮБОЕ условие: штук ≥ `craft.confirm.min_qty` или золота за
 * партию ≥ `craft.confirm.min_gold`. Значение 0 выключает своё условие; оба 0 — подтверждения нет.
 */
class CraftBatchConfirmPolicy
{
    public const KEY_MIN_QTY  = 'craft.confirm.min_qty';
    public const KEY_MIN_GOLD = 'craft.confirm.min_gold';

    /** Страховка, если ключи ещё не засеяны (миграция не применена). */
    private const DEFAULT_MIN_QTY  = 25;
    private const DEFAULT_MIN_GOLD = 50000;

    private GameSettingsService $gameSettings;

    public function __construct(?GameSettingsService $gameSettings = null)
    {
        $this->gameSettings = $gameSettings ?? new GameSettingsService();
    }

    public function needsConfirm(int $qty, int $goldTotal): bool
    {
        $minQty  = $this->threshold(self::KEY_MIN_QTY, self::DEFAULT_MIN_QTY);
        $minGold = $this->threshold(self::KEY_MIN_GOLD, self::DEFAULT_MIN_GOLD);

        return ($minQty > 0 && $qty >= $minQty)
            || ($minGold > 0 && $goldTotal >= $minGold);
    }

    private function threshold(string $key, int $default): int
    {
        $raw = $this->gameSettings->get($key, $default);

        return is_numeric($raw) ? max(0, (int) $raw) : $default;
    }
}

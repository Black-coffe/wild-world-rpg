<?php

declare(strict_types=1);

namespace App\Services\Craft;

/**
 * Story `craft-shortfall-buy-06` — единственный DTO расчёта докупки недостающего
 * сырья у торговца. Контракт зафиксирован в `docs/specs/craft-shortfall-buy/plan.md`
 * §Contracts: story 08 (экран) и story 09 (сделка) строятся против ЭТИХ полей,
 * не выдумывая своих.
 *
 * `markupPct` — **фактический** процент, посчитанный обратно из `total`, а не тот,
 * что подставлен в формулу. На `base = 1` округление `ceil()` и `min_markup_gold`
 * вместе дают факт +100% при номинальных +15% — печатать номинал значило бы соврать
 * игроку вдвое в ровно этом (самом частом) случае «не хватает одной штуки».
 */
final class CraftShortfallQuote
{
    /**
     * @param list<array{
     *     resourceId: int,
     *     name: string,
     *     need: int,
     *     have: int,
     *     gap: int,
     *     unitPrice: float,
     *     lineTotal: float,
     *     buyable: bool,
     *     blockReason: string|null
     * }> $lines только позиции с недостачей (`gap > 0`); что уже в достатке — не строка
     * @param float $baseCost Σ gap_i × unitPrice_i по покупаемым позициям
     * @param float $fullCost Σ need_i × unitPrice_i по всем сырьевым позициям рецепта
     * @param float $share доля рецепта (в деньгах), которую закрывает докупка; потолка нет
     * @param int $markupPct фактическая наценка в процентах, посчитанная из `total`
     * @param int $total сколько золота спишет сделка
     * @param int $goldAfter золото персонажа после списания (может быть отрицательным —
     *     честный сигнал нехватки, а не повод округлять)
     * @param bool $available можно ли провести докупку прямо сейчас
     * @param string|null $refusal причина отказа: `not_tradeable`|`level_required`|
     *     `not_on_sale`|`profit_gate`|`killswitch_off`|`min_gold`; `null` — отказа нет
     */
    public function __construct(
        public readonly array $lines,
        public readonly float $baseCost,
        public readonly float $fullCost,
        public readonly float $share,
        public readonly int $markupPct,
        public readonly int $total,
        public readonly int $goldAfter,
        public readonly bool $available,
        public readonly ?string $refusal
    ) {
    }
}

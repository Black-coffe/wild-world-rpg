<?php

declare(strict_types=1);

namespace App\Services\Economy;

use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * ADR-157 (шаг 2) — суточный лимит выкупа у NPC-торговца.
 *
 * ADR-157 закрыл разгон цены продажи, но базовые цены части предметов сами по себе
 * остались арбитражем: ВСЕ входы ряда рецептов покупаются у того же торговца, причём
 * без лимита стока. Худший случай на 2026-07-27 — «Рыбные консервы»: 5 рыбы + 2 воды
 * обходятся в ~5.6 золота, а готовые консервы торговец забирает за ~374. Так печатают
 * золото 22 позиции из 39.
 *
 * Правка цен обрушила бы доход честных поваров (цена еды отражает пользу, а не
 * себестоимость), поэтому выбран рычаг с минимальным радиусом поражения: **торговец
 * тратит на одного выжившего не больше N золота в сутки**. Игрок, сдающий улов или
 * партию крафта, лимита не замечает; промышленный цикл упирается в потолок в тот же
 * день.
 *
 * Учитываются только продажи КРАФТОВЫХ предметов (таблица `transactions`) — именно
 * там арбитраж. Продажа сырья идёт мимо `transactions` и под лимит не подпадает:
 * у сырья честный спред (buy 1.05 / sell 0.95), петли нет.
 *
 * Окно — скользящие 24 часа, а не календарный день: иначе цикл удваивается на стыке
 * полуночи.
 */
class VendorDailyLimitService
{
    public const KEY_ENABLED = 'economy.trade.daily_buyback_enabled';
    public const KEY_CAP     = 'economy.trade.daily_buyback_cap';

    private const DEFAULT_CAP = 50000.0;

    private GameSettingsService $settings;

    /** @var BaseConnection<\mysqli, \mysqli_result> */
    private BaseConnection $db;

    /** @param BaseConnection<\mysqli, \mysqli_result>|null $db */
    public function __construct(?GameSettingsService $settings = null, ?BaseConnection $db = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
        $this->db       = $db ?? Database::connect();
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get(self::KEY_ENABLED, true);
    }

    /** Потолок выкупа за скользящие сутки. */
    public function cap(): float
    {
        $value = $this->settings->get(self::KEY_CAP, self::DEFAULT_CAP);

        return is_numeric($value) ? max(0.0, (float) $value) : self::DEFAULT_CAP;
    }

    /**
     * Сколько золота торговец уже отдал этому персонажу за последние 24 часа.
     *
     * Единственная точка чтения БД — seam для тестов (класс намеренно не final,
     * чтобы лимитную арифметику можно было проверять без наполнения таблицы).
     */
    public function soldLast24h(int $characterId): float
    {
        $query = $this->db->query(
            'SELECT COALESCE(SUM(price), 0) AS total
             FROM transactions
             WHERE character_id = ? AND type = ? AND created_at >= (NOW() - INTERVAL 1 DAY)',
            [$characterId, 'sell']
        );

        if (! $query instanceof \CodeIgniter\Database\BaseResult) {
            return 0.0;
        }
        $row = $query->getRowArray();

        return isset($row['total']) && is_numeric($row['total']) ? (float) $row['total'] : 0.0;
    }

    /** Сколько торговец ещё может потратить на этого персонажа. Без лимита — INF. */
    public function remaining(int $characterId): float
    {
        if (! $this->isEnabled()) {
            return INF;
        }

        return max(0.0, $this->cap() - $this->soldLast24h($characterId));
    }

    /** Помещается ли сделка на эту сумму в остаток суток. */
    public function allows(int $characterId, float $amount): bool
    {
        return $amount <= $this->remaining($characterId);
    }
}

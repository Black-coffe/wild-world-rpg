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

    /**
     * Можно ли ещё продавать торговцу.
     *
     * Лимит запрещает СЛЕДУЮЩУЮ сделку после исчерпания, а не сделку, которая
     * потолок превысит. Иначе дорогой штучный предмет становится непродаваемым
     * навсегда: «Верстак 1» стоит 132 000 при потолке 50 000 — проверка «сделка
     * должна помещаться в остаток» отрезала бы его владельца от лавки без всякого
     * пути обхода. Перерасход ограничен стоимостью одного предмета, поток всё
     * равно упирается в потолок.
     *
     * Это гейт «лавка вообще открыта». Сколько штук пройдёт — считает
     * {@see allowedQuantity()}: до 2026-08-06 стек уходил целиком, и «Верстак 1» ×5
     * проносил через потолок 660 000 одной сделкой (замер прода: персонаж 1115 делал
     * ровно такую сделку раз в сутки, 13 потолков за проход).
     *
     * `$amount` в расчёте не участвует и оставлен для читаемости вызова.
     */
    public function allows(int $characterId, float $amount): bool
    {
        unset($amount);

        return $this->remaining($characterId) > 0;
    }

    /**
     * Сколько штук из запрошенных торговец возьмёт этой сделкой.
     *
     * Зажим до остатка **с полом в одну штуку** — решение владельца 2026-08-06.
     * Обе крайности плохи по отдельности:
     *  - «сделка должна помещаться целиком» отрезала бы владельца дорогой штучной
     *    вещи от лавки навсегда: «Верстак 1» при потолке 50 000 не влезает никогда,
     *    а разбить его на части нельзя;
     *  - «пропускать стек целиком» (как было) превращало потолок в формальность:
     *    умножь количество — и вынеси сколько угодно.
     *
     * Пол в одну штуку сохраняет продаваемость (перерасход ограничен ценой ОДНОГО
     * предмета — ровно тот радиус, на который и рассчитывали), а всё сверх остатка
     * остаётся у игрока и ждёт открытия лавки.
     *
     * @param float $unitPrice цена ЗА ШТУКУ, уже посчитанная сервисом цены
     *
     * @return int 0 — лимит выбран, сделки нет вовсе
     */
    public function allowedQuantity(int $characterId, int $requested, float $unitPrice): int
    {
        if ($requested <= 0) {
            return 0;
        }
        if (! $this->isEnabled()) {
            return $requested;
        }

        $remaining = $this->remaining($characterId);
        if ($remaining <= 0) {
            return 0;
        }

        // Бесплатное золото не тратит — лимит к таким предметам не применяется.
        if ($unitPrice <= 0) {
            return $requested;
        }

        $fits = (int) floor($remaining / $unitPrice);

        return max(1, min($requested, $fits));
    }

    /**
     * Когда торговец снова начнёт скупать, если лимит уже выбран.
     *
     * Окно скользящее, поэтому «завтра» — неправда: лимит освобождается не разом в
     * полночь, а по мере того как старые сделки выпадают из последних 24 часов.
     * Считаем честно: идём по сделкам окна от самой старой и ищем, выпадение какой
     * из них опустит сумму ниже потолка. Её время + 24 часа и есть момент, когда
     * дверь снова откроется.
     *
     * @return int|null unix-время открытия; null — лимит не выбран либо выключен
     */
    public function reliefAt(int $characterId): ?int
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $cap  = $this->cap();
        $sold = $this->soldLast24h($characterId);
        if ($sold < $cap) {
            return null;
        }

        $remainingSum = $sold;
        foreach ($this->salesInWindow($characterId) as $sale) {
            $remainingSum -= $sale['price'];
            if ($remainingSum < $cap) {
                return $sale['at'] + 86400;
            }
        }

        return null;
    }

    /**
     * Сделки продажи внутри скользящего окна, от самой старой к свежей.
     *
     * Второй (и последний) seam чтения БД в классе — рядом с {@see soldLast24h()}:
     * без него момент открытия лавки нельзя проверить тестом, не наполняя
     * `transactions` живыми строками.
     *
     * @return list<array{price: float, at: int}>
     */
    protected function salesInWindow(int $characterId): array
    {
        $query = $this->db->query(
            'SELECT price, created_at
             FROM transactions
             WHERE character_id = ? AND type = ? AND created_at >= (NOW() - INTERVAL 1 DAY)
             ORDER BY created_at ASC',
            [$characterId, 'sell']
        );

        if (! $query instanceof \CodeIgniter\Database\BaseResult) {
            return [];
        }

        $sales = [];
        foreach ($query->getResultArray() as $row) {
            $when = isset($row['created_at']) && is_string($row['created_at']) ? strtotime($row['created_at']) : false;
            if ($when === false) {
                continue;
            }
            $sales[] = [
                'price' => isset($row['price']) && is_numeric($row['price']) ? (float) $row['price'] : 0.0,
                'at'    => $when,
            ];
        }

        return $sales;
    }

    /**
     * Готовая для игрока строка отказа: почему закрыто, сколько уже выкуплено и
     * когда откроется. Одна на крафт и экипировку — тексты отказа разъезжались бы
     * ровно так же, как разъезжались формулы цены (см. {@see CraftTradeService}).
     *
     * @param string $tail заключительное успокоение целиком — согласование рода у
     *                     «товар не делся» и «экипировка не делась» разное, поэтому
     *                     передаётся фраза, а не существительное
     */
    public function refusalText(int $characterId, string $tail = 'Твой товар никуда не делся.'): string
    {
        $cap   = (int) round($this->cap());
        $sold  = (int) round($this->soldLast24h($characterId));
        $when  = $this->reliefAt($characterId);

        $text = "🛒 Торговец выворачивает пустые карманы: *монеты на сегодня кончились*.\n\n"
            . "На одного выжившего он рассчитывает примерно *{$cap}* 💰 в сутки, "
            . "а тебе за последние 24 часа отдал уже *{$sold}* 💰.\n";

        if ($when !== null) {
            $text .= 'Скупка снова откроется примерно в *' . date('H:i', $when) . "* — счёт идёт по последним 24 часам, а не по календарному дню.\n";
        } else {
            $text .= "Счёт идёт по последним 24 часам, а не по календарному дню: место освободится по мере того, как старые сделки выпадут из окна.\n";
        }

        return $text . "\n_{$tail}_";
    }

    /**
     * Строка для карточки количества: как у игрока обстоит с суточным счётом.
     *
     * Показываем ТОЛЬКО тем, кто за последние сутки уже продавал, — иначе строка про
     * лимит висела бы на каждой карточке у всех 675 персонажей, из которых в потолок
     * за всё время упирались трое. Лимит становится видимым в тот момент, когда
     * начинает касаться игрока, а не раньше.
     *
     * 🔴 Формулировка обязана совпадать с поведением {@see allowedQuantity()}: пока
     * стек уходил целиком, строка честно предупреждала «возьмёт целиком»; после
     * зажима она обещает ровно зажим. Написать здесь что-то, чего сделка не делает,
     * значит повторить ошибку, чинившуюся в ценах лавки
     * (см. memory feedback_screen_price_must_come_from_transaction_service).
     *
     * @return string пустая строка — показывать нечего
     */
    public function budgetHint(int $characterId): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

        $sold = $this->soldLast24h($characterId);
        if ($sold <= 0.0) {
            return '';
        }

        $cap     = (int) round($this->cap());
        $soldInt = (int) round($sold);

        if ($sold >= $this->cap()) {
            $when = $this->reliefAt($characterId);

            return $when !== null
                ? '_Монеты торговца на сегодня кончились — снова начнёт скупать около ' . date('H:i', $when) . '._'
                : '_Монеты торговца на сегодня кончились._';
        }

        $left = (int) round($this->cap() - $sold);

        return "_За сутки торговец отдал тебе *{$soldInt}* из *{$cap}* 💰. "
            . "Больше чем на *{$left}* он сегодня не наберёт: возьмёт столько штук, "
            . 'сколько потянет, остальное останется у тебя._';
    }
}

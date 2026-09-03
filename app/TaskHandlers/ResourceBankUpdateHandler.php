<?php

namespace App\TaskHandlers;

use App\Models\ResourceModel;
use App\Models\ResourcesBankModel;
use App\Services\GameSettings\GameSettingsReaderTrait;

/**
 * market-01 (docs/specs/market-decay) — состаривание `resources_purchased`/`resources_sold`
 * на проде было `-1` за тик против счётчиков в миллионы (аудит brief.md: до 5-9 лет на
 * возврат к базовой цене) — цены оказались заморожены навсегда. Килсвитч
 * `economy.market.proportional_decay_enabled` (default false) переключает на
 * пропорциональное затухание с полураспадом `economy.market.half_life_hours` и потолком
 * `economy.market.counter_cap`; при false поведение байт-идентично прежнему `-1`.
 *
 * Формула цены (ratio/клампы/спред) не тронута вовсе — меняется только шаг состаривания,
 * и он всегда считается ПОСЛЕ того, как цена уже посчитана из счётчиков «до».
 */
class ResourceBankUpdateHandler
{
    use GameSettingsReaderTrait;

    protected $resourceModel;
    protected $resourcesBankModel;

    public function __construct()
    {
        $this->resourceModel = new ResourceModel();
        $this->resourcesBankModel = new ResourcesBankModel();
    }

    public function process(int $intervalMinutes = 1)
    {
        if ($intervalMinutes < 1) {
            $intervalMinutes = 1;
        }

        $proportionalDecay = $this->gsBool('economy.market.proportional_decay_enabled', false);
        $halfLifeHours = $this->gsFloat('economy.market.half_life_hours', 48.0);
        $counterCap = $this->gsInt('economy.market.counter_cap', 2000);

        // Получаем все ресурсы
        $resources = $this->resourceModel->findAll();

        $db = \Config\Database::connect();

        foreach ($resources as $resource) {
            // exploit-fix-32 (R3-major) — раньше SELECT (обычный, снимок вне лока) и
            // UPDATE абсолютным значением шли отдельными вызовами: сделка, вклинившаяся
            // между ними, стиралась целиком (не только один бамп — весь параллельный
            // increment() пропадал под перезаписью абсолютной пары purchased/sold).
            // Транзакция + `SELECT … FOR UPDATE` держат строку до собственного COMMIT —
            // конкурентный increment() из ResourceTradeService либо успевает ДО (крон
            // читает уже свежее значение), либо ждёт эту транзакцию и применяется ПОСЛЕ
            // (на уже состаренном значении), но не может провалиться в щель между чтением
            // и записью крона.
            $db->transStart();

            // FOR UPDATE — только MySQL: SQLite (тестовая группа) синтаксис не знает,
            // тот же приём, что и CharacterStatsService::mutate().
            $sql = 'SELECT id, resources_purchased, resources_sold FROM ' . $db->prefixTable('resources_bank') . ' WHERE resource_id = ?';
            if ($db->DBDriver === 'MySQLi') {
                $sql .= ' FOR UPDATE';
            }
            $lock     = $db->query($sql, [$resource['id']]);
            $bankData = $lock instanceof \CodeIgniter\Database\BaseResult ? $lock->getRowArray() : null;

            // Если записи нет, пропускаем (нет купленных/проданных)
            if (!$bankData) {
                $db->transComplete();
                continue;
            }

            // Получаем показатели спроса/предложения
            $purchased = (int)$bankData['resources_purchased'];
            $sold      = (int)$bankData['resources_sold'];

            // Считаем ratio, зажатый в коридор [0.35 .. 3.5] — из счётчиков ДО состаривания
            $ratio = ($purchased + 1) / ($sold + 1);
            $priceFactor = max(0.35, min(3.5, $ratio));

            // Вычисляем новые цены, исходя из базовой price
            $basePrice = $resource['price'];
            $newPrice  = $basePrice * $priceFactor;

            // Предположим, покупка на 5% дороже, продажа на 5% дешевле
            $buyPrice  = round($newPrice * 1.05, 2);
            $sellPrice = round($newPrice * 0.95, 2);

            // Обновляем в таблице resources
            $this->resourceModel->update($resource['id'], [
                'buy_price'  => $buyPrice,
                'sell_price' => $sellPrice,
            ]);

            if ($proportionalDecay) {
                [$newPurchased, $newSold] = $this->decayCounters($purchased, $sold, $intervalMinutes, $halfLifeHours, $counterCap);
            } else {
                // "Состариваем" (уменьшаем) показатели purchased/sold
                // чтобы при отсутствии сделок цена постепенно возвращалась к базовой
                $newPurchased = max(0, $purchased - 1);
                $newSold      = max(0, $sold - 1);
            }

            // Обновляем банк — всё ещё под локом этой же транзакции (строка не
            // отпускалась с момента SELECT … FOR UPDATE выше).
            $this->resourcesBankModel->update($bankData['id'], [
                'resources_purchased' => $newPurchased,
                'resources_sold'      => $newSold,
                'last_update'         => date('Y-m-d H:i:s'),
            ]);

            $db->transComplete();
        }
    }

    /**
     * Пропорциональное затухание счётчиков с полураспадом + втягивание в потолок.
     * Оба шага — умножение на общий множитель, поэтому пропорция purchased/sold
     * сохраняется на всех этапах.
     *
     * @return array{0: int, 1: int}
     */
    private function decayCounters(int $purchased, int $sold, int $intervalMinutes, float $halfLifeHours, int $counterCap): array
    {
        if ($halfLifeHours <= 0) {
            $halfLifeHours = 48.0;
        }

        $decayFactor = 2 ** (-$intervalMinutes / ($halfLifeHours * 60));

        $decayedPurchased = $purchased * $decayFactor;
        $decayedSold      = $sold * $decayFactor;

        // Втягивание: если после затухания счётчик всё ещё выше потолка — масштабируем
        // оба счётчика вниз одним и тем же множителем, чтобы пропорция purchased/sold
        // сохранилась и максимум из двух стал равен потолку.
        $maxDecayed = max($decayedPurchased, $decayedSold);
        if ($counterCap > 0 && $maxDecayed > $counterCap) {
            $capScale = $counterCap / $maxDecayed;
            $decayedPurchased *= $capScale;
            $decayedSold      *= $capScale;
        }

        // Значение меньше 1 после затухания уходит в 0 (никакого бесконечного хвоста
        // дробей — колонки bigint). Небольшой эпсилон компенсирует погрешность float pow().
        $newPurchased = max(0, (int) floor($decayedPurchased + 1.0e-9));
        $newSold      = max(0, (int) floor($decayedSold + 1.0e-9));

        return [$newPurchased, $newSold];
    }
}

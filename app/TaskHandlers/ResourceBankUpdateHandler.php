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
            //
            // exploit-fix-35 (R4-critical) — раньше это была одна связная пара
            // `transStart()`/`transComplete()` без try/catch: упавший запрос ИЛИ
            // исключение внутри одной итерации оставляли общее соединение с
            // `transStatus=false` (CI4 `transStrict` по умолчанию `true` в этом
            // проекте — флаг липкий, `transComplete()` его не сбрасывает) до конца
            // PHP-процесса. Крон работает в одном процессе с соседними
            // task-handler'ами (`Config\Tasks.php`) на одном разделяемом
            // соединении — их записи после первой сбойной итерации крона молча
            // откатывались бы. Каждая итерация — собственная транзакция с явным
            // `transBegin()`/`transCommit()`/`transRollback()` в try/catch.
            //
            // exploit-fix-39 (R5-critical) — раньше ранний выход «строки банка нет»
            // (`!$bankData`) уходил в `transCommit(); continue;` МИМО единственной
            // проверки `transStatus()`, стоявшей в конце тела итерации. Упавший
            // `SELECT … FOR UPDATE` (lock-wait-timeout и т.п.) тоже даёт
            // `$bankData === null`, неотличимо от штатного «строки нет» — и такой
            // сбой утекал молча, флаг оставался `false` до конца процесса, ничего
            // не логировалось. Теперь весь код итерации (чтение, запись, коммит)
            // идёт через одну точку проверки `transStatus()` ниже — она видит
            // упавший SELECT … FOR UPDATE ровно так же, как упавшую запись, потому
            // что оба случая ветвятся через один и тот же `$bankData===null`/не-null
            // путь, и ни один не выходит из тела итерации раньше неё:
            // любой исход — успех, «строки нет», упавший запрос, брошенное
            // исключение, упавший `transCommit()` — заканчивается в одном месте,
            // которое откатывает и логирует. `resetTransStatus()` вызывается
            // только если ЭТА итерация была транзакцией верхнего уровня
            // (глубина была 0 до `transBegin()`) — иначе (`process()` вызван
            // внутри чужой открытой транзакции — сегодня такого вызывающего в
            // `app/` нет, но контракт не завязан на это) чужой отравленный флаг
            // не стирается: без savepoint'ов в CI4 внешняя транзакция обязана
            // сама решить, откатываться ей или нет.
            $topLevelIteration = ($db->transDepth === 0);
            $db->transBegin();

            try {
                // FOR UPDATE — только MySQL: тестовая группа этого репозитория —
                // тоже MySQLi (phpunit.xml.dist), тот же приём, что и
                // CharacterStatsService::mutate().
                $sql = 'SELECT id, resources_purchased, resources_sold FROM ' . $db->prefixTable('resources_bank') . ' WHERE resource_id = ?';
                if ($db->DBDriver === 'MySQLi') {
                    $sql .= ' FOR UPDATE';
                }
                $lock     = $db->query($sql, [$resource['id']]);
                $bankData = $lock instanceof \CodeIgniter\Database\BaseResult ? $lock->getRowArray() : null;

                // Если записи нет — штатный случай (нет купленных/проданных),
                // но завершаем итерацию через ту же единственную точку ниже.
                if ($bankData) {
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
                }

                // Единственная точка проверки на ВЕСЬ путь итерации — «прочитано» и
                // «не прочитано» (в т.ч. упавший SELECT … FOR UPDATE, который даёт
                // тот же $bankData===null, что и штатное «строки нет») сходятся
                // сюда. Упавший запрос внутри транзакции CI4 не бросает исключение
                // (`BaseConnection::query()` глотает его молча, если
                // `transException` не включён) — он лишь выставляет
                // `transStatus=false`. Проверяем это явно и уходим в catch-ветку,
                // а не полагаемся на `transComplete()`, которая при
                // `transStrict=true` не сбрасывает липкий флаг сама.
                if ($db->transStatus() === false) {
                    throw new \RuntimeException('ResourceBankUpdateHandler: итерация упала для resource_id=' . $resource['id']);
                }

                // Исход коммита тоже проверяется (R5 m10): `false` неотличим от
                // успешного, если результат не читать.
                $committed = $db->transCommit();
                if ($committed === false) {
                    throw new \RuntimeException('ResourceBankUpdateHandler: transCommit() вернул false для resource_id=' . $resource['id']);
                }
            } catch (\Throwable $e) {
                if ($db->transDepth > 0) {
                    $db->transRollback();
                }
                if ($topLevelIteration) {
                    $db->resetTransStatus();
                }
                log_message(
                    'error',
                    'ResourceBankUpdateHandler: итерация для resource_id={resource_id} упала: {message}',
                    ['resource_id' => $resource['id'], 'message' => $e->getMessage()]
                );
            }
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

<?php

namespace App\TaskHandlers;

use App\Attributes\HandlerKey;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\TelegramUserModel;
use App\Models\TeleportBeaconModel;
use DateTime;
use DateInterval;

/**
 * Класс TaxCollectionHandler:
 * Выполняется ежедневно (например, в 03:00).
 * 1. Списывает налог за здания (если второй раз подряд не хватило денег — удаляет здание).
 * 2. Списывает налог за маяки (аналогично, при втором недостатке денег — удаляет маяк).
 * 3. По итогу отправляет сообщение с фото и детальной сводкой:
 *    - Сколько списано за здания
 *    - Сколько списано за маяки
 *    - Итоговое золото
 *    - Короткое описание механики «двойного предупреждения»
 *
 * v0.51.20 (F2.9 batch-2): extends BaseTaskHandler (per F2.9 contract).
 * Раніше extends Controller — handler НЕ контроллер.
 * Request::sendMessage/sendPhoto → safeSendMessage/safeSendPhoto.
 * `handle()` → `handle(array $task = []): void` (TaskHandlerInterface signature).
 */
#[HandlerKey(
    key: 'tax_collection',
    displayName: 'Сбор налогов (раз в сутки)',
    description: 'Recurring (Tasks.php daily 03:00): списывает налог за здания и маяки. При 2-м недостатке gold подряд — удаляет постройку.',
)]
class TaxCollectionHandler extends BaseTaskHandler
{
    /**
     * @param array<string,mixed> $task TaskHandlerInterface signature (recurring tasks
     *                                  не приймають task data).
     */
    public function handle(array $task = []): void
    {
        $currentDateTime = new DateTime();
        $now = $currentDateTime->format('Y-m-d H:i:s');

        // F2.10 wire-in: час сбора налогов через config/GameBalance вместо hardcoded.
        // Дефолт 3 (03:xx Europe/Kiev), можно переопределить через .env
        // переменной `gamebalance.taxCollectionHour`.
        $taxHour = config('GameBalance')->taxCollectionHour ?? 3;
        $currentHour   = (int) $currentDateTime->format('H');
        $currentMinute = (int) $currentDateTime->format('i');
        if ($currentHour !== $taxHour || $currentMinute > 10) {
            // Окно 10 минут после $taxHour:00 — внутри запускаемся, иначе skip.
            return;
        }

        $characterBuildingModel = new CharacterBuildingModel();
        $characterModel         = new CharacterModel();
        $telegramUserModel      = new TelegramUserModel();
        $teleportBeaconModel    = new TeleportBeaconModel();

        // 1) Сначала собираем сводную инфу по ЗДАНИЯМ
        $allBuildings = $characterBuildingModel
            ->select('character_id, SUM(tax) as total_tax, MAX(last_tax_collected) as last_tax_collected, COUNT(*) as building_count')
            ->groupBy('character_id')
            ->findAll();

        // Массив вида (characterId => [total_tax, last_tax_collected, building_count])
        $buildingsTaxMap = [];
        foreach ($allBuildings as $charBld) {
            $cId   = (int) $charBld['character_id'];
            $bTax  = (int) $charBld['total_tax'];
            $bLast = $charBld['last_tax_collected'];
            $count = (int) $charBld['building_count'];

            $buildingsTaxMap[$cId] = [
                'total_tax'         => $bTax,
                'last_tax_collected'=> $bLast,
                'building_count'    => $count
            ];
        }

        // 2) Собираем всех персонажей, у которых есть хотя бы одно здание
        $processedCharacterIds = array_keys($buildingsTaxMap);

        // 3) Для каждого персонажа — списываем налог за здания, потом за маяки
        foreach ($processedCharacterIds as $characterId) {
            $charBuildings = $buildingsTaxMap[$characterId] ?? null;
            if (!$charBuildings) {
                continue;
            }

            $totalTaxBuildings = $charBuildings['total_tax'];
            $lastTaxCollected  = $charBuildings['last_tax_collected'];
            $buildingCount     = (int) $charBuildings['building_count'];

            // Проверяем, прошло ли 24 часа с момента последнего сбора
            if ($lastTaxCollected) {
                $nextAllowedTime = (new DateTime($lastTaxCollected))->add(new DateInterval('PT24H'));
                if ($nextAllowedTime > $currentDateTime) {
                    // Не прошло 24 часа, пропускаем
                    continue;
                }
            }

            // Получаем информацию о персонаже
            $character = $characterModel->find($characterId);
            if (!$character) {
                continue;
            }

            // ADR-095 Фаза 2 (DORMANT) — налог-каскад до уничтожения базы. При killswitch
            // OFF поведение byte-identical (удаление постройки на 2-й FAILURE).
            $lifecycle = new \App\Services\Bases\BaseLifecycleService();
            $cascadeOn = $lifecycle->taxCascadeEnabled();

            // ---------------------
            // 3.1) Списываем налог за здания
            // ---------------------
            // E23/ADR-122: per-base путь (killswitch buildings.tax.per_base_enabled).
            // ON → налог/статус/реакция по каждой базе (map_cell_id) — недофинансированная
            // одна база не морозит производство на других. Для одно-базовых ≡ агрегат.
            // Обе ветки выставляют $newGoldAmount + $collectedTaxBuildings, дальше — общий
            // путь маяков и сводки (без дублирования). Золото читаем один раз.
            $availableGold = (int) $character['gold'];

            // E23/ADR-122 Ф2: при per-base пути собираем разбивку по базам для сводки
            // (какая база оплачена/недофинансирована). Агрегатный путь — пустая разбивка.
            $baseBreakdown = [];

            if ($lifecycle->taxPerBaseEnabled()) {
                [$newGoldAmount, $collectedTaxBuildings, $baseBreakdown] = $this->collectBuildingTaxPerBase(
                    $characterId,
                    $availableGold,
                    $characterModel,
                    $cascadeOn,
                    $lifecycle,
                    $now
                );
            } else {
                // OFF (default) — существующий агрегатный путь (byte-identical).
                $newGoldAmount         = $availableGold - $totalTaxBuildings;
                $taxCollectionStatus  = 'SUCCESS';
                $collectedTaxBuildings = $totalTaxBuildings; // сколько фактически списали

                // Проверка на недостаток золота
                if ($newGoldAmount < 0) {
                    // Не хватает золота на все здания
                    $taxCollectionStatus   = 'FAILURE';
                    $collectedTaxBuildings = $availableGold; // собираем всё, что есть
                    $newGoldAmount         = 0;

                    if ($cascadeOn) {
                        // ADR-095 Фаза 2: налог-каскад. Ведём streak неуплаты; после grace —
                        // сносим наименьшую (наименее застроенную) базу, streak сбрасываем.
                        $streak = $this->unpaidStreak($characterId) + 1;
                        $grace  = $lifecycle->taxCascadeGraceDays();
                        if ($streak >= $grace) {
                            $this->cascadeDestroySmallestBase($characterId, $characterBuildingModel);
                            $streak = 0;
                        } else {
                            $left = $grace - $streak;
                            $this->notifyCharacterById(
                                $characterId,
                                "⚠ Налог за базы не оплачен (*{$streak}* дн. подряд)!\n" .
                                "Ещё *{$left}* дн. без оплаты — и самая маленькая база будет *уничтожена*."
                            );
                        }
                        $characterModel->update($characterId, ['tax_unpaid_streak' => $streak]);
                    } else {
                        // Существующее поведение (dormant): удаление постройки на 2-й FAILURE подряд.
                        $lastFailedBuilding = $characterBuildingModel
                            ->where('character_id', $characterId)
                            ->where('tax_collection_status', 'FAILURE')
                            ->orderBy('created_at', 'DESC')
                            ->first();

                        if ($lastFailedBuilding) {
                            // Второй раз => удаляем самую новую постройку
                            $latestBuilding = $characterBuildingModel
                                ->where('character_id', $characterId)
                                ->orderBy('created_at', 'DESC')
                                ->first();

                            if ($latestBuilding) {
                                $buildingId = $latestBuilding['id'];
                                $characterBuildingModel->delete($buildingId);
                                $this->sendTelegramNotification(
                                    $character,
                                    "🏚 Не хватило золота на налог во второй раз подряд!\n" .
                                    "Поэтому здание (ID={$buildingId}) было *удалено*."
                                );
                            }
                        } else {
                            // Первый раз => лишь предупреждение
                            $this->sendTelegramNotification(
                                $character,
                                "⚠ Недостаточно золота, чтобы оплатить налог за *все здания*!\n" .
                                "Если это произойдёт *снова*, будет удалена твоя последняя постройка!"
                            );
                        }
                    }
                } elseif ($cascadeOn) {
                    // Налог уплачен полностью — сбрасываем streak неуплаты (каскад).
                    if ($this->unpaidStreak($characterId) !== 0) {
                        $characterModel->update($characterId, ['tax_unpaid_streak' => 0]);
                    }
                }

                // Обновляем золото у персонажа — атомарное относительное списание
                // от СВЕЖЕГО золота (fix lost-update 2026-07-13); параллельное
                // начисление/трата за время цикла крона не затирается.
                $paid = (new \App\Services\Player\CharacterStatsService())
                    ->adjust($characterId, ['gold' => -$collectedTaxBuildings]);
                if ($paid !== null && isset($paid['after']['gold'])) {
                    $newGoldAmount = (int) $paid['after']['gold'];
                }

                // Обновляем поля в character_buildings
                $characterBuildingModel
                    ->where('character_id', $characterId)
                    ->set([
                        'last_tax_collected'   => $now,
                        'tax_collection_status'=> $taxCollectionStatus
                    ])
                    ->update();
            }

            // ---------------------
            // 3.2) Списываем налог за маяки
            // ---------------------
            // Ищем все маяки игрока, где remaining_uses >= 1
            $beacons = $teleportBeaconModel
                ->where('character_id', $characterId)
                ->where('remaining_uses >=', 1)
                ->orderBy('created_at', 'ASC')
                ->findAll();

            $totalBeaconTax = 0;
            foreach ($beacons as $b) {
                $totalBeaconTax += (int)$b['tax_cost'];
            }

            $collectedTaxBeacons = 0;

            if (!empty($beacons)) {
                // Проверяем, хватает ли золота на все маяки
                if ($totalBeaconTax <= $newGoldAmount) {
                    // Хватает на все
                    $collectedTaxBeacons = $totalBeaconTax;
                    // Обновляем золото — атомарное относительное списание (fix 2026-07-13)
                    $paid = (new \App\Services\Player\CharacterStatsService())
                        ->adjust($characterId, ['gold' => -$totalBeaconTax]);
                    $newGoldAmount = ($paid !== null && isset($paid['after']['gold']))
                        ? (int) $paid['after']['gold']
                        : $newGoldAmount - $totalBeaconTax;

                    // Ставим маякам статус SUCCESS
                    foreach ($beacons as $b) {
                        $id = $b['id'];
                        $oldSettings = json_decode($b['settings_json'] ?? '{}', true) ?: [];
                        $oldSettings['last_beacon_tax_status'] = 'SUCCESS';
                        $oldSettings['last_beacon_tax_date']   = $now;
                        $teleportBeaconModel->update($id, [
                            'settings_json' => json_encode($oldSettings)
                        ]);
                    }

                } else {
                    // Не хватает на все маяки => идём по одному
                    $remainingGold = $newGoldAmount;
                    $failedBeacons  = [];
                    $warnedBeacons  = [];
                    $deletedBeacons = [];

                    foreach ($beacons as $b) {
                        $id  = $b['id'];
                        $tax = (int)$b['tax_cost'];
                        $oldSet = json_decode($b['settings_json'] ?? '{}', true) ?: [];

                        if ($tax <= $remainingGold) {
                            // Хватает на этот маяк
                            $collectedTaxBeacons += $tax;
                            $remainingGold -= $tax;
                            // Запишем SUCCESS
                            $oldSet['last_beacon_tax_status'] = 'SUCCESS';
                            $oldSet['last_beacon_tax_date']   = $now;
                            $teleportBeaconModel->update($id, [
                                'settings_json' => json_encode($oldSet)
                            ]);
                        } else {
                            // Не хватает
                            if (($oldSet['last_beacon_tax_status'] ?? '') === 'FAILURE') {
                                // Второй раз => удаляем маяк
                                $deletedBeacons[] = $id;
                            } else {
                                // Первый раз => просто предупреждаем
                                $warnedBeacons[] = $id;
                            }
                        }
                    }

                    // Обновляем деньги — атомарное относительное списание собранного
                    // с маяков (fix 2026-07-13)
                    $paid = (new \App\Services\Player\CharacterStatsService())
                        ->adjust($characterId, ['gold' => -$collectedTaxBeacons]);
                    $newGoldAmount = ($paid !== null && isset($paid['after']['gold']))
                        ? (int) $paid['after']['gold']
                        : $remainingGold;

                    // Удаляем маяки, которые fail второй раз
                    foreach ($deletedBeacons as $bId) {
                        $teleportBeaconModel->delete($bId);
                    }

                    // Для маяков, которые fail впервые => обновим settings_json
                    foreach ($warnedBeacons as $bId) {
                        $bRow = $teleportBeaconModel->find($bId);
                        if (!$bRow) {
                            continue;
                        }
                        $oldSet = json_decode($bRow['settings_json'] ?? '{}', true) ?: [];
                        $oldSet['last_beacon_tax_status'] = 'FAILURE';
                        $oldSet['last_beacon_tax_date']   = $now;
                        $teleportBeaconModel->update($bId, [
                            'settings_json' => json_encode($oldSet)
                        ]);
                    }

                    // Если есть маяки, которые не оплатили налог
                    $totalFailCount = count($warnedBeacons) + count($deletedBeacons);
                    if ($totalFailCount > 0) {
                        $msg = "⚠ Недостаточно золота для уплаты налогов за *{$totalFailCount}* маяк(ов)!\n";
                        if (!empty($deletedBeacons)) {
                            $msg .= "Некоторые маяки удалены (второй раз подряд не хватило денег).";
                        } else {
                            $msg .= "При повторном недоборе эти маяки будут *удалены*!";
                        }
                        $this->sendTelegramNotification($character, $msg);
                    }
                }
            }

            // 4) Итоговое уведомление со сводкой:
            $collectedB = number_format($collectedTaxBuildings, 0, '', ' ');
            $collectedM = number_format($collectedTaxBeacons,   0, '', ' ');
            $finalGold  = number_format($newGoldAmount,         0, '', ' ');

            // E23/ADR-122 Ф2: блок «Здания». При per-base — разбивка по базам (какая
            // оплачена ✅, какая недофинансирована ⚠️), иначе агрегат (byte-identical OFF).
            if ($baseBreakdown !== []) {
                $baseCount = count($baseBreakdown);
                $lines     = '';
                foreach ($baseBreakdown as $bd) {
                    $nm   = $this->markdownSafeName($bd['name']);
                    $tx   = number_format($bd['tax'], 0, '', ' ');
                    $mark = $bd['status'] === 'SUCCESS' ? '✅' : '⚠️ недобор';
                    $lines .= "   • {$nm} — налог *{$tx}* {$mark}\n";
                }
                $buildingsBlock = "🏘 Зданий: *{$buildingCount}* на *{$baseCount}* баз(ах)\n"
                    . "   Налог собран: *{$collectedB}*\n"
                    . "   По базам:\n" . $lines;
            } else {
                $buildingsBlock = "🏘 Зданий: *{$buildingCount}*\n"
                    . "   Налог собран: *{$collectedB}*\n";
            }

            // Формируем расширенное сообщение
            // Описываем механику: "сначала налог за здания, потом маяки,
            // если не хватило второй раз подряд — здание/маяк удаляется"
            $summaryMsg = "💰 *Сбор налогов произведён!*\n\n"
                . "1. *Сначала* списан налог за *здания*\n"
                . "   - При первом недоборе лишь предупреждение,\n"
                . "   - При втором подряд — удаляем последнее здание.\n\n"
                . "2. *Затем* налог за *маяки*\n"
                . "   - Логика та же: двойное предупреждение, при повторном — удаляем маяк.\n\n"
                . "Вот твоя статистика:\n\n"
                . $buildingsBlock
                . "🗼 Маяков: *" . count($beacons) . "*\n"
                . "   Налог собран: *{$collectedM}*\n\n"
                . "💎 *Итоговое золото*: {$finalGold}";

            // Отправляем фото + итоговое сообщение
            $this->sendTelegramNotificationPhoto($character, $summaryMsg);
        }
    }

    /**
     * E23/ADR-122 — per-base сбор налога за здания. Золото игрока — единый кошелёк; базы
     * оплачиваются жадно по ВОЗРАСТАНИЮ собственного налога (дешёвые первыми → максимум баз
     * продолжают производить; partial-collect на первой неоплатимой базе делает поведение
     * одно-базового игрока byte-identical со старым агрегатом). Статус tax_collection_status
     * ставится per-base (WHERE character_id AND map_cell_id), реакция на неуплату (legacy-снос
     * новейшей постройки этой базы / cascade-streak) — тоже per-base/per-character соответственно.
     *
     * @return array{0:int,1:int,2:list<array{cell:int,name:string,tax:int,paid:int,status:string}>}
     *   [новое золото игрока, фактически собрано налога за здания, разбивка по базам для сводки]
     */
    private function collectBuildingTaxPerBase(
        int $characterId,
        int $availableGold,
        CharacterModel $characterModel,
        bool $cascadeOn,
        \App\Services\Bases\BaseLifecycleService $lifecycle,
        string $now
    ): array {
        // Базы игрока с собственным налогом, дешёвые первыми (greedy keep-alive).
        $res = \Config\Database::connect()->table('character_buildings')
            ->select('map_cell_id, SUM(tax) AS base_tax')
            ->where('character_id', $characterId)
            ->groupBy('map_cell_id')
            ->orderBy('base_tax', 'ASC')
            ->orderBy('map_cell_id', 'ASC') // детерминизм при равном налоге
            ->get();
        $bases = $res === false ? [] : $res->getResultArray();

        // Имена баз (claimed_cells.camp_name) для разбивки в сводке — один запрос на игрока.
        $baseNames = $this->baseNameMap($characterId);

        $remainingGold  = $availableGold;
        $collectedTotal = 0;
        $anyFailure     = false;
        /** @var list<array{cell:int,name:string,tax:int,paid:int,status:string}> $breakdown */
        $breakdown      = [];

        foreach ($bases as $baseRow) {
            $mapCellId = is_numeric($baseRow['map_cell_id'] ?? null) ? (int) $baseRow['map_cell_id'] : 0;
            $baseTax   = is_numeric($baseRow['base_tax'] ?? null) ? (int) $baseRow['base_tax'] : 0;

            if ($remainingGold >= $baseTax) {
                // База оплачена полностью.
                $remainingGold  -= $baseTax;
                $collectedTotal += $baseTax;
                $this->setBaseTaxStatus($characterId, $mapCellId, 'SUCCESS', $now);
                $breakdown[] = [
                    'cell' => $mapCellId, 'name' => $baseNames[$mapCellId] ?? 'База',
                    'tax' => $baseTax, 'paid' => $baseTax, 'status' => 'SUCCESS',
                ];
            } else {
                // Не хватает на эту базу — списываем остаток частично, база FAILURE.
                $anyFailure       = true;
                $paidThisBase     = $remainingGold; // частичная оплата до обнуления
                $collectedTotal  += $remainingGold;
                $remainingGold    = 0;
                // Legacy-реакция (снос новейшей постройки этой базы на 2-й FAILURE) — только
                // когда каскад выключен; при cascadeOn неоплата ведётся через streak (ниже).
                if (! $cascadeOn) {
                    $this->reactBaseTaxFailureLegacy($characterId, $mapCellId);
                }
                $this->setBaseTaxStatus($characterId, $mapCellId, 'FAILURE', $now);
                $breakdown[] = [
                    'cell' => $mapCellId, 'name' => $baseNames[$mapCellId] ?? 'База',
                    'tax' => $baseTax, 'paid' => $paidThisBase, 'status' => 'FAILURE',
                ];
            }
        }

        // ADR-095 Фаза 2 (dormant) — каскад. Streak per-character по факту «была неоплата
        // хоть одной базы»; после grace сносим наименьшую базу. cascadeDestroySmallestBase
        // уже работает per-base (выбирает наименее застроенную).
        if ($cascadeOn) {
            if ($anyFailure) {
                $streak = $this->unpaidStreak($characterId) + 1;
                $grace  = $lifecycle->taxCascadeGraceDays();
                if ($streak >= $grace) {
                    $this->cascadeDestroySmallestBase($characterId, new CharacterBuildingModel());
                    $streak = 0;
                } else {
                    $left = $grace - $streak;
                    $this->notifyCharacterById(
                        $characterId,
                        "⚠ Налог за базы не оплачен (*{$streak}* дн. подряд)!\n" .
                        "Ещё *{$left}* дн. без оплаты — и самая маленькая база будет *уничтожена*."
                    );
                }
                $characterModel->update($characterId, ['tax_unpaid_streak' => $streak]);
            } elseif ($this->unpaidStreak($characterId) !== 0) {
                $characterModel->update($characterId, ['tax_unpaid_streak' => 0]);
            }
        }

        // Атомарное относительное списание собранного налога (fix 2026-07-13):
        // решения SUCCESS/FAILURE приняты по снапшоту, но золото вычитается от
        // СВЕЖЕГО значения — параллельная торговля/награда не затирается.
        $paid = (new \App\Services\Player\CharacterStatsService())
            ->adjust($characterId, ['gold' => -$collectedTotal]);
        if ($paid !== null && isset($paid['after']['gold'])) {
            $remainingGold = (int) $paid['after']['gold'];
        }

        return [$remainingGold, $collectedTotal, $breakdown];
    }

    /**
     * E23/ADR-122 Ф2 — карта имён баз игрока (map_cell_id → camp_name) для разбивки в сводке.
     * Пустое/отсутствующее имя → 'База'.
     *
     * @return array<int,string>
     */
    private function baseNameMap(int $characterId): array
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('claimed_cells')) {
            return [];
        }
        $q = $db->table('claimed_cells')
            ->select('map_cell_id, camp_name')
            ->where('character_id', $characterId)
            ->get();
        $rows = $q === false ? [] : $q->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $cell = is_numeric($r['map_cell_id'] ?? null) ? (int) $r['map_cell_id'] : 0;
            $name = is_string($r['camp_name'] ?? null) && $r['camp_name'] !== '' ? $r['camp_name'] : 'База';
            $out[$cell] = $name;
        }

        return $out;
    }

    /**
     * Markdown-safe имя базы для caption (parse_mode=Markdown НЕ экранирует — непарные
     * `*`/`_`/`` ` ``/`[` в имени ломают весь caption → HTTP 400 → тихий не-сенд).
     * Убираем значимые символы; пустой результат → 'База'.
     * Урок: memory feedback_legacy_markdown_no_backslash_escape.
     */
    private function markdownSafeName(string $name): string
    {
        // Логика вынесена в общий App\Services\Display\MarkdownSafe (2026-07-10):
        // топ игроков рендерит имена десятками, второй копии не заводим.
        return \App\Services\Display\MarkdownSafe::name($name, 'База');
    }

    /**
     * E23/ADR-122 — ставит tax_collection_status (+last_tax_collected) постройкам ОДНОЙ базы.
     * Свежий Model на вызов — анти builder-state quirk (memory feedback_ci4_model_builder_state).
     */
    private function setBaseTaxStatus(int $characterId, int $mapCellId, string $status, string $now): void
    {
        \Config\Database::connect()->table('character_buildings')
            ->where('character_id', $characterId)
            ->where('map_cell_id', $mapCellId)
            ->update([
                'last_tax_collected'    => $now,
                'tax_collection_status' => $status,
            ]);
    }

    /**
     * E23/ADR-122 — legacy-реакция на неуплату налога ОДНОЙ базы (каскад OFF): если эта база
     * уже была в FAILURE в прошлый прогон — сносим её новейшую постройку, иначе предупреждаем.
     * Зеркало старого character-level поведения, но scoped по map_cell_id.
     */
    private function reactBaseTaxFailureLegacy(int $characterId, int $mapCellId): void
    {
        $db = \Config\Database::connect();

        // Был ли FAILURE на ЭТОЙ базе в прошлый прогон? (статус этого прогона ещё не записан).
        $q = $db->table('character_buildings')
            ->where('character_id', $characterId)
            ->where('map_cell_id', $mapCellId)
            ->where('tax_collection_status', 'FAILURE')
            ->orderBy('created_at', 'DESC')
            ->get();
        $lastFailed = $q !== false ? $q->getRowArray() : null;

        if (is_array($lastFailed)) {
            // Второй раз подряд => удаляем новейшую постройку этой базы.
            $q2 = $db->table('character_buildings')
                ->where('character_id', $characterId)
                ->where('map_cell_id', $mapCellId)
                ->orderBy('created_at', 'DESC')
                ->get();
            $latest = $q2 !== false ? $q2->getRowArray() : null;

            if (is_array($latest) && is_numeric($latest['id'] ?? null)) {
                $buildingId = (int) $latest['id'];
                $db->table('character_buildings')->where('id', $buildingId)->delete();
                $this->notifyCharacterById(
                    $characterId,
                    "🏚 Не хватило золота на налог за базу во второй раз подряд!\n" .
                    "Поэтому здание (ID={$buildingId}) было *удалено*."
                );
            }
        } else {
            // Первый раз => лишь предупреждение.
            $this->notifyCharacterById(
                $characterId,
                "⚠ Недостаточно золота, чтобы оплатить налог за одну из твоих *баз*!\n" .
                "Если это произойдёт *снова*, постройка этой базы будет удалена!"
            );
        }
    }

    /**
     * ADR-095 Фаза 2 — снос наименьшей (наименее застроенной) активной базы персонажа
     * вместе с её постройками + уведомление. Триггер: streak неуплаты ≥ grace при cascade ON.
     */
    private function cascadeDestroySmallestBase(int $characterId, CharacterBuildingModel $buildingModel): void
    {
        $db    = \Config\Database::connect();
        $bases = $db->table('claimed_cells')
            ->select('id, map_cell_id, camp_name')
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->get();
        if ($bases === false) {
            return;
        }
        $baseRows = $bases->getResultArray();
        if ($baseRows === []) {
            return;
        }

        // Наименьшая = с наименьшим числом построек на её ячейке.
        $smallest      = null;
        $smallestCount = PHP_INT_MAX;
        foreach ($baseRows as $b) {
            $mapCellId = is_numeric($b['map_cell_id'] ?? null) ? (int) $b['map_cell_id'] : 0;
            $cntRaw    = $buildingModel->where('character_id', $characterId)->where('map_cell_id', $mapCellId)->countAllResults();
            $cnt       = is_numeric($cntRaw) ? (int) $cntRaw : 0;
            if ($cnt < $smallestCount) {
                $smallestCount = $cnt;
                $smallest      = $b;
            }
        }
        if ($smallest === null) {
            return;
        }

        $cellRowId = is_numeric($smallest['id'] ?? null) ? (int) $smallest['id'] : 0;
        $mapCellId = is_numeric($smallest['map_cell_id'] ?? null) ? (int) $smallest['map_cell_id'] : 0;
        if ($cellRowId === 0) {
            return;
        }

        $buildingModel->where('character_id', $characterId)->where('map_cell_id', $mapCellId)->delete();
        (new ClaimedCellModel())->delete($cellRowId);

        $name = is_string($smallest['camp_name'] ?? null) && $smallest['camp_name'] !== '' ? $smallest['camp_name'] : 'База';
        $this->notifyCharacterById(
            $characterId,
            "🏚 *{$name} уничтожена за неуплату налогов!*\n"
            . "Копи золото — иначе следующая база тоже падёт."
        );
    }

    /**
     * ADR-095 Фаза 2 — текущий streak неуплаты налога (characters.tax_unpaid_streak),
     * скалярным запросом (без Entity/offset-неоднозначности).
     */
    private function unpaidStreak(int $characterId): int
    {
        $q = \Config\Database::connect()->table('characters')
            ->select('tax_unpaid_streak')->where('id', $characterId)->get();
        $row = $q !== false ? $q->getRowArray() : null;
        return is_array($row) && is_numeric($row['tax_unpaid_streak'] ?? null) ? (int) $row['tax_unpaid_streak'] : 0;
    }

    /**
     * ADR-095 Фаза 2 — уведомление игрока по character_id (скалярный telegram_id lookup,
     * без Entity-неоднозначности). Для каскадных сообщений Фазы 2.
     */
    protected function notifyCharacterById(int $characterId, string $message): void
    {
        $q = \Config\Database::connect()->table('characters c')
            ->select('u.telegram_id')
            ->join('telegram_users u', 'u.id = c.telegram_user_id')
            ->where('c.id', $characterId)->get();
        $row = $q !== false ? $q->getRowArray() : null;
        if (is_array($row) && is_numeric($row['telegram_id'] ?? null)) {
            $this->safeSendMessage((int) $row['telegram_id'], $message, ['parse_mode' => 'Markdown']);
        }
    }

    /**
     * Уведомление игрока (character) в Telegram (просто текст).
     */
    protected function sendTelegramNotification(array|\App\Entities\CharacterEntity $character, string $message): void
    {
        $telegramUserModel = new TelegramUserModel();
        $tgUser = $telegramUserModel->find($character['telegram_user_id']);
        if (!$tgUser) {
            return;
        }

        $this->safeSendMessage($tgUser['telegram_id'], $message, ['parse_mode' => 'Markdown']);
    }

    /**
     * Уведомление с фото + текстом (итоговый отчёт о налогах).
     */
    protected function sendTelegramNotificationPhoto(array|\App\Entities\CharacterEntity $character, string $caption): void
    {
        $telegramUserModel = new TelegramUserModel();
        $tgUser = $telegramUserModel->find($character['telegram_user_id']);
        if (!$tgUser) {
            return;
        }

        $imagePath = base_url('uploads/telegram/camp/tax_for_building.png');
        $this->safeSendPhoto($tgUser['telegram_id'], $imagePath, $caption, ['parse_mode' => 'Markdown']);
    }
}

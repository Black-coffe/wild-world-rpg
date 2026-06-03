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
            $availableGold         = (int) $character['gold'];
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

            // Обновляем золото у персонажа
            $characterModel->update($characterId, ['gold' => $newGoldAmount]);

            // Обновляем поля в character_buildings
            $characterBuildingModel
                ->where('character_id', $characterId)
                ->set([
                    'last_tax_collected'   => $now,
                    'tax_collection_status'=> $taxCollectionStatus
                ])
                ->update();

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
                    $newGoldAmount      -= $totalBeaconTax;
                    // Обновляем золото
                    $characterModel->update($characterId, ['gold' => $newGoldAmount]);

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

                    // Обновляем деньги
                    $newGoldAmount = $remainingGold;
                    $characterModel->update($characterId, ['gold' => $newGoldAmount]);

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
                . "🏘 Зданий: *{$buildingCount}*\n"
                . "   Налог собран: *{$collectedB}*\n"
                . "🗼 Маяков: *" . count($beacons) . "*\n"
                . "   Налог собран: *{$collectedM}*\n\n"
                . "💎 *Итоговое золото*: {$finalGold}";

            // Отправляем фото + итоговое сообщение
            $this->sendTelegramNotificationPhoto($character, $summaryMsg);
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
    private function notifyCharacterById(int $characterId, string $message): void
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
    private function sendTelegramNotification(array|\App\Entities\CharacterEntity $character, string $message): void
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
    private function sendTelegramNotificationPhoto(array|\App\Entities\CharacterEntity $character, string $caption): void
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

<?php

namespace App\TaskHandlers;

use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Models\TeleportBeaconModel;
use CodeIgniter\Controller;
use CodeIgniter\I18n\Time;
use DateTime;
use DateInterval;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

class TaxCollectionHandler extends Controller
{
    public function handle()
    {
        $currentDateTime = new DateTime();
        $now = $currentDateTime->format('Y-m-d H:i:s');

        // Допустим, скрипт должен запускаться ровно в 03:xx каждый день
        $currentHour   = (int)$currentDateTime->format('H');
        $currentMinute = (int)$currentDateTime->format('i');
        if ($currentHour !== 3 || $currentMinute > 10) {
            // Если сейчас не 3:xx, выходим
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

        // Массив для (characterId => totalTaxBuildings)
        $buildingsTaxMap = [];
        // Заполним для удобства
        foreach ($allBuildings as $charBld) {
            $cId  = $charBld['character_id'];
            $bTax = (int)$charBld['total_tax']; // сумма по зданиям
            $bLast= $charBld['last_tax_collected'];
            $count= (int)$charBld['building_count'];

            $buildingsTaxMap[$cId] = [
                'total_tax'         => $bTax,
                'last_tax_collected'=> $bLast,
                'building_count'    => $count
            ];
        }

        // 2) Собираем всех персонажей, у которых есть хотя бы одно здание
        $processedCharacterIds = array_keys($buildingsTaxMap);

        // 3) Для каждого персонажа — списываем налог за здания, а затем за маяки
        foreach ($processedCharacterIds as $characterId) {
            $charBuildings = $buildingsTaxMap[$characterId] ?? null;
            if (!$charBuildings) {
                continue;
            }

            $totalTaxBuildings = (int)$charBuildings['total_tax'];
            $lastTaxCollected  = $charBuildings['last_tax_collected'];
            $buildingCount     = (int)$charBuildings['building_count'];

            // Проверяем, прошло ли 24 часа
            if ($lastTaxCollected) {
                $nextAllowedTime = (new DateTime($lastTaxCollected))->add(new DateInterval('PT24H'));
                if ($nextAllowedTime > $currentDateTime) {
                    // Не прошло 24 часа, пропускаем
                    continue;
                }
            }

            // Получаем инфу о персонаже
            $character = $characterModel->find($characterId);
            if (!$character) {
                continue;
            }

            // 3.1) Списываем налог за здания
            $availableGold = (int)$character['gold'];
            $newGoldAmount = $availableGold - $totalTaxBuildings;
            $taxCollectionStatus = 'SUCCESS';
            $collectedTaxBuildings = $totalTaxBuildings; // сколько получилось собрать фактически за здания

            if ($newGoldAmount < 0) {
                // Недостаточно золота на ВСЕ здания
                $taxCollectionStatus = 'FAILURE';
                $collectedTaxBuildings = $availableGold; // собираем всё, что есть
                $newGoldAmount = 0;

                // Проверяем, было ли ранее FAILURE
                $lastFailedBuilding = $characterBuildingModel
                    ->where('character_id', $characterId)
                    ->where('tax_collection_status', 'FAILURE')
                    ->orderBy('created_at', 'DESC')
                    ->first();

                if ($lastFailedBuilding) {
                    // второй раз подряд => удаляем самую "новую" постройку
                    $latestBuilding = $characterBuildingModel
                        ->where('character_id', $characterId)
                        ->orderBy('created_at', 'DESC')
                        ->first();

                    if ($latestBuilding) {
                        $characterBuildingModel->delete($latestBuilding['id']);
                        $this->sendTelegramNotification(
                            $character,
                            "Постройка ID={$latestBuilding['id']} была удалена (второй раз подряд не хватает золота на налог за здания)."
                        );
                    }
                } else {
                    // Первый раз => предупреждение
                    $this->sendTelegramNotification(
                        $character,
                        "Внимание! Недостаточно золота для уплаты налогов за здания. Если в следующий раз не хватит золота, мы удалим последнюю постройку!"
                    );
                }
            }

            // Обновляем золото
            $characterModel->update($characterId, ['gold' => $newGoldAmount]);

            // Обновляем поля в character_buildings
            $characterBuildingModel
                ->where('character_id', $characterId)
                ->set([
                    'last_tax_collected'  => $now,
                    'tax_collection_status'=> $taxCollectionStatus
                ])
                ->update();

            // 3.2) ***Теперь налог за МАЯКИ***
            // Ищем все маяки игрока, у которых remaining_uses>=1
            $beacons = $teleportBeaconModel
                ->where('character_id', $characterId)
                ->where('remaining_uses >=', 1)
                ->orderBy('created_at', 'ASC') // упорядочим от старых к новым или наоборот
                ->findAll();

            $totalBeaconTax = 0;
            foreach ($beacons as $b) {
                $totalBeaconTax += (int)$b['tax_cost'];
            }

            $collectedTaxBeacons = 0;

            // Если нет маяков — просто пропускаем
            if (!empty($beacons)) {
                // Можно ли уплатить ВСЕ?
                if ($totalBeaconTax <= $newGoldAmount) {
                    // Хватает
                    $collectedTaxBeacons = $totalBeaconTax;
                    $newGoldAmount -= $totalBeaconTax;
                    // Обновляем золото персонажа
                    $characterModel->update($characterId, ['gold' => $newGoldAmount]);

                    // Ставим (например) в settings_json маяков что "tax SUCCESS" если хотим
                    foreach ($beacons as $b) {
                        $id  = (int)$b['id'];
                        $oldSettings = json_decode($b['settings_json'] ?? '{}', true) ?: [];
                        $oldSettings['last_beacon_tax_status'] = 'SUCCESS';
                        $oldSettings['last_beacon_tax_date']   = $now;

                        $teleportBeaconModel->update($id, [
                            'settings_json' => json_encode($oldSettings)
                        ]);
                    }
                } else {
                    // Не хватает на все маяки => начинаем идти по маякам
                    // (например, от старых к новым), собирая по одному
                    $remainingGold = $newGoldAmount;
                    $failedBeacons = []; // маяки, которым не хватило денег
                    $warnedBeacons = []; // маяки, которые "первый раз FAIL"
                    $deletedBeacons= []; // маяки, которые "второй раз FAIL" => удаляем

                    foreach ($beacons as $b) {
                        $id     = (int)$b['id'];
                        $tax    = (int)$b['tax_cost'];
                        $uses   = (int)$b['remaining_uses'];
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
                            // Не хватает на этот маяк
                            // Смотрим, был ли раньше FAIL
                            if (($oldSet['last_beacon_tax_status'] ?? '') === 'FAILURE') {
                                // Второй раз => удаляем этот маяк
                                $deletedBeacons[] = $id;
                            } else {
                                // Первый раз => пишем FAIL
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

                    // Для маяков, которые fail первый раз => обновим settings_json
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

                    // Сообщаем игроку, если есть хоть один маяк, по которому не хватило денег
                    $totalFailCount = count($warnedBeacons) + count($deletedBeacons);
                    if ($totalFailCount > 0) {
                        $msg = "Недостаточно золота на оплату налогов за *{$totalFailCount}* маяк(ов).";
                        if (!empty($deletedBeacons)) {
                            $msg .= "\nНекоторые маяки удалены (второй раз подряд не хватало денег).";
                        } else {
                            $msg .= "\nВ следующий раз при повторном FAIL эти маяки будут удалены!";
                        }
                        $this->sendTelegramNotification($character, $msg);
                    }
                }
            }

            // 4) Итоговое уведомление:
            //    Отправим одно сообщение о том, что «Сбор налогов произведён»
            //    где укажем, сколько за здания, сколько за маяки, итоговое золото
            $collectedB = number_format($collectedTaxBuildings, 0, '', ' ');
            $collectedM = number_format($collectedTaxBeacons,   0, '', ' ');
            $finalGold  = number_format($newGoldAmount,         0, '', ' ');

            $summaryMsg = "📣 *Сбор налогов завершён!*\n\n"
                ."Здания: *{$buildingCount}*шт\n"
                ."Золота за здания: *{$collectedB}*\n"
                ."Маяки: *".count($beacons)."*шт\n"
                ."Золота за маяки: *{$collectedM}*\n\n"
                ."Итоговое золото: *{$finalGold}*";

            $this->sendTelegramNotification($character, $summaryMsg);
        }
    }

    /**
     * Уведомление игрока (character) в Telegram
     */
    private function sendTelegramNotification(array $character, string $message)
    {
        $telegramUserModel = new TelegramUserModel();
        $tgUser = $telegramUserModel->find($character['telegram_user_id']);
        if (!$tgUser) {
            return;
        }

        try {
            Request::sendMessage([
                'chat_id' => $tgUser['telegram_id'],
                'text'    => $message,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            log_message('error', 'Telegram API error: ' . $e->getMessage());
        }
    }
}

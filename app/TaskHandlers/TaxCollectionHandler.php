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
 */
class TaxCollectionHandler extends Controller
{
    public function handle()
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

                // Смотрим, был ли уже ранее FAILURE (значит это второй)
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
     * Уведомление игрока (character) в Telegram (просто текст).
     */
    private function sendTelegramNotification(array|\App\Entities\CharacterEntity $character, string $message)
    {
        $telegramUserModel = new TelegramUserModel();
        $tgUser = $telegramUserModel->find($character['telegram_user_id']);
        if (!$tgUser) {
            return;
        }

        try {
            Request::sendMessage([
                'chat_id'    => $tgUser['telegram_id'],
                'text'       => $message,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            log_message('error', 'Telegram API error: ' . $e->getMessage());
        }
    }

    /**
     * Уведомление с фото + текстом (итоговый отчёт о налогах).
     */
    private function sendTelegramNotificationPhoto(array|\App\Entities\CharacterEntity $character, string $caption)
    {
        $telegramUserModel = new TelegramUserModel();
        $tgUser = $telegramUserModel->find($character['telegram_user_id']);
        if (!$tgUser) {
            return;
        }

        $imagePath = base_url('uploads/telegram/camp/tax_for_building.png');

        try {
            Request::sendPhoto([
                'chat_id'    => $tgUser['telegram_id'],
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $caption,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            log_message('error', 'Telegram API error (photo): ' . $e->getMessage());
        }
    }
}

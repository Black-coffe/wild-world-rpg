<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;

use App\Models\TeleportBeaconModel;
use App\Models\TeleportBeaconLogModel;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\MapModel;
use App\Models\BiomeModel;

class TeleportBeaconMoveConfirmAction
{
    protected CallbackQuery $callbackQuery;

    protected TeleportBeaconModel    $teleportBeaconModel;
    protected TeleportBeaconLogModel $teleportBeaconLogModel;
    protected CharacterModel         $characterModel;
    protected ClaimedCellModel       $claimedCellModel;
    protected BuildingModel          $buildingModel;
    protected CharacterBuildingModel $characterBuildingModel;
    protected MapModel               $mapModel;
    protected BiomeModel             $biomeModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery = $callbackQuery;

        $this->teleportBeaconModel    = new TeleportBeaconModel();
        $this->teleportBeaconLogModel = new TeleportBeaconLogModel();
        $this->characterModel         = new CharacterModel();
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->mapModel               = new MapModel();
        $this->biomeModel             = new BiomeModel();
    }

    public function handle(): ServerResponse
    {
        // Очищаем "часики"
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $chatId      = $this->callbackQuery->getMessage()->getChat()->getId();
        $callbackData = $this->callbackQuery->getData();

        // Ожидаем "teleportBeaconMoveGo_id=XX"
        if (!preg_match('/^teleportBeaconMoveGo_id=(\d+)$/', $callbackData, $m)) {
            return $this->sendError($chatId, "Некорректный callback_data: {$callbackData}");
        }

        $beaconId = (int) $m[1];

        // TelegramUser => CharacterId
        $telegramUserId = $this->callbackQuery->getFrom()->getId();
        $characterId = $this->characterModel->getCharacterIdByTelegramId($telegramUserId);
        if (!$characterId) {
            return $this->sendError($chatId, "Ошибка: персонаж не найден.");
        }

        // Проверка базы
        $base = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();
        if (!$base) {
            return $this->sendError($chatId, "У тебя нет активной базы.");
        }

        // Проверка Центра телепорта
        $teleportCenter = $this->buildingModel->where('name_en', 'TeleportationCenter')->first();
        if (!$teleportCenter) {
            return $this->sendError($chatId, "Ошибка: нет 'TeleportationCenter' в базе.");
        }
        $hasCenter = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', $teleportCenter['id'])
            ->first();
        if (!$hasCenter) {
            return $this->sendError($chatId, "У тебя нет постройки 'Центр телепортации'.");
        }

        // Проверка маяка
        $beaconRow = $this->teleportBeaconModel
            ->where('id', $beaconId)
            ->where('character_id', $characterId)
            ->first();
        if (!$beaconRow) {
            return $this->sendError($chatId, "Маяк #{$beaconId} не найден или не твой.");
        }
        if ($beaconRow['remaining_uses'] < 1) {
            return $this->sendError($chatId, "У маяка #{$beaconId} 0 оставшихся телепортов!");
        }

        // Узнаём ячейку (map.id), откуда телепорт
        $charRow           = $this->characterModel->find($characterId);
        $currentCellNumber = $charRow['cell_number'] ?? 0;
        $currentMapRow = $this->mapModel->where('cell_number', $currentCellNumber)->first();
        $fromMapCellId = $currentMapRow ? (int) $currentMapRow['id'] : null;

        // Считаем минуты с последнего телепорта
        $lastLog = $this->teleportBeaconLogModel
            ->where('character_id', $characterId)
            ->orderBy('teleported_at', 'DESC')
            ->first();
        $minutesPassed = 9999;
        if ($lastLog) {
            $now     = new \DateTime();
            $lastT   = new \DateTime($lastLog['teleported_at']);
            $diff    = $now->getTimestamp() - $lastT->getTimestamp();
            $minutesPassed = floor($diff / 60);
        }

        // Рассчитываем cost
        $teleportCost = 0;
        $infoCostText = '';
        if ($minutesPassed >= 60) {
            $teleportCost = 0;
            $infoCostText = "Бесплатно (прошёл час).";
        } else {
            $multiplier = $this->calculateMultiplier($minutesPassed);
            $teleportCost = (60 - $minutesPassed) * $multiplier;
            $infoCostText = "Платный телепорт: {$teleportCost} золота (прошло {$minutesPassed} мин)";
        }

        // Проверка золота
        if ($teleportCost > 0) {
            $cRow = $this->characterModel->find($characterId);
            $gold = (int)$cRow['gold'];
            if ($gold < $teleportCost) {
                $needMore = $teleportCost - $gold;
                $waitMin  = 60 - $minutesPassed;
                return $this->sendError($chatId,
                    "Недостаточно золота!\n"
                    ."Нужно {$teleportCost}, а есть {$gold}.\n"
                    ."Жди ещё {$waitMin} мин для бесплатного телепорта.\n"
                    ."Или раздобудь недостающие *{$needMore}* 💰."
                );
            }
            // Списываем золото
            $this->characterModel->update($characterId, ['gold' => ($gold - $teleportCost)]);
        }

        // Переводим персонажа
        $mapRow = $this->mapModel->find($beaconRow['map_cell_id']);
        if (!$mapRow) {
            return $this->sendError($chatId, "Не найдена карта ID={$beaconRow['map_cell_id']}.");
        }

        $newCellNumber = $mapRow['cell_number'];
        $newBiomeId    = $mapRow['biome_id'] ?? null;
        // Обновляем character
        $this->characterModel->update($characterId, [
            'cell_number' => $newCellNumber,
            'biome_id'    => $newBiomeId,
        ]);

        // Обновляем/удаляем маяк
        $remain = (int)$beaconRow['remaining_uses'] - 1;
        if ($remain <= 0) {
            $this->teleportBeaconModel->delete($beaconRow['id']);
        } else {
            $this->teleportBeaconModel->update($beaconRow['id'], [
                'remaining_uses'    => $remain,
                'last_teleport_at'  => date('Y-m-d H:i:s'),
            ]);
        }

        // **Важная часть**: insert в teleport_beacon_logs
        // Приводим каждое поле к int, чтобы CI точно видел "integer", а не string/float
        $dataForLog = [
            'teleport_beacon_id' => (int) $beaconRow['id'],
            'character_id'       => (int) $characterId,
            'from_map_cell_id'   => $fromMapCellId ? (int)$fromMapCellId : null,
            'teleport_cost'      => (int) $teleportCost,
            'teleported_at'      => date('Y-m-d H:i:s'), // valid_date
        ];

        $result = $this->teleportBeaconLogModel->insert($dataForLog);
        if (!$result) {
            // Логируем: возможно, ошибка валидации
            log_message('error', "Ошибка insert teleport_beacon_logs: Data="
                .json_encode($dataForLog)."\n"
                ."errors=".print_r($this->teleportBeaconLogModel->errors(),true)."\n"
                ."dbError=".print_r($this->teleportBeaconLogModel->db->error(),true)
            );
        }

        // Готовим финальный текст
        $biomeName = "???";
        if ($newBiomeId) {
            $bRow = $this->biomeModel->find($newBiomeId);
            if ($bRow) {
                $biomeName = $bRow['name'] ?? "???";
            }
        }
        $remainText = ($remain > 0) ? (string)$remain : "— маяк исчез!";

        $waitFree = 0;
        if ($minutesPassed < 60) {
            $waitFree = 60 - $minutesPassed;
        }

        $msg = "🌀 *Телепорт завершён!*\n\n"
            ."Ты переместился на 🌎 Маяк (X={$beaconRow['coordinate_x']}, Y={$beaconRow['coordinate_y']}).\n"
            ."Биом: *{$biomeName}*\n\n"
            ."💰 *Стоимость:* {$infoCostText}\n"
            ."🔋 Остаток телепортов: *{$remainText}*\n\n";

        if ($waitFree > 0) {
            $msg .= "⏳ Следующий бесплатный телепорт через: ~{$waitFree} мин.\n";
        } else {
            $msg .= "✅ *Телепорт снова бесплатен*, время ожидания 0 мин.\n";
        }

        // Кнопки
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📡 Маяки',       'callback_data' => 'teleportBeacon'],
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                ],
                [
                    ['text' => '🎉 События',     'callback_data' => 'events'],
                    ['text' => '🧑‍🌾 Действия 🛠️','callback_data' => 'characterActions'],
                ],
            ]
        ];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $msg,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function calculateMultiplier(int $minutesWithinHour): int
    {
        if ($minutesWithinHour >= 50) {
            return 1;
        } elseif ($minutesWithinHour >= 40) {
            return 5;
        } elseif ($minutesWithinHour >= 30) {
            return 10;
        } elseif ($minutesWithinHour >= 20) {
            return 25;
        } elseif ($minutesWithinHour >= 10) {
            return 50;
        } else {
            return 100;
        }
    }

    private function sendError(int $chatId, string $msg): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $msg,
            'parse_mode' => 'Markdown',
        ]);
    }
}

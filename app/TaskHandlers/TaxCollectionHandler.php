<?php

namespace App\TaskHandlers;

use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use CodeIgniter\Controller;
use CodeIgniter\I18n\Time;
use DateTime;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

class TaxCollectionHandler extends Controller
{
    public function handle()
    {
        $currentDateTime = new DateTime();
        $now = $currentDateTime->format('Y-m-d H:i:s');

        // Расширяем интервал выполнения скрипта
        $currentHour = (int)$currentDateTime->format('H');
        $currentMinute = (int)$currentDateTime->format('i');
        if ($currentHour !== 3 || $currentMinute > 10) {
            return;
        }

        $characterBuildingModel = new CharacterBuildingModel();
        $characterModel = new CharacterModel();
        $telegramUserModel = new TelegramUserModel();

        // Получаем все постройки, сгруппированные по персонажам
        $allBuildings = $characterBuildingModel->select('character_id, SUM(tax) as total_tax, MAX(last_tax_collected) as last_tax_collected, COUNT(*) as building_count')
            ->groupBy('character_id')
            ->findAll();

        foreach ($allBuildings as $characterBuildings) {
            $characterId = $characterBuildings['character_id'];
            $totalTax = $characterBuildings['total_tax'];
            $buildingCount = $characterBuildings['building_count'];
            $lastTaxCollected = $characterBuildings['last_tax_collected'];

            // Проверка, прошло ли 24 часа с последнего сбора налога
            if ($lastTaxCollected && (new DateTime($lastTaxCollected))->add(new \DateInterval('PT24H')) > $currentDateTime) {
                continue; // Пропускаем персонажа, если не прошло 24 часа
            }

            // Получаем информацию о персонаже
            $character = $characterModel->find($characterId);

            if (!$character) {
                continue; // Пропускаем, если персонаж не найден
            }

            $availableGold = $character['gold'];
            $newGoldAmount = $availableGold - $totalTax;
            $taxCollectionStatus = 'SUCCESS';

            if ($newGoldAmount < 0) {
                // Недостаточно золота для полной уплаты налога
                $taxCollectionStatus = 'FAILURE';
                $collectedTax = $availableGold;
                $newGoldAmount = 0;

                // Проверяем статус предыдущего сбора налогов
                $lastFailedBuilding = $characterBuildingModel->where('character_id', $characterId)
                    ->where('tax_collection_status', 'FAILURE')
                    ->orderBy('created_at', 'DESC')
                    ->first();

                if ($lastFailedBuilding) {
                    // Если предыдущий сбор был неудачным, удаляем самую новую постройку
                    $latestBuilding = $characterBuildingModel->where('character_id', $characterId)
                        ->orderBy('created_at', 'DESC')
                        ->first();

                    if ($latestBuilding) {
                        $characterBuildingModel->delete($latestBuilding['id']);
                        $this->sendTelegramNotification($character, "Постройка с ID {$latestBuilding['id']} была удалена за неуплату налогов.");
                    }
                } else {
                    // Если это первый неудачный сбор, отправляем предупреждение
                    $this->sendTelegramNotification($character, "Внимание! Недостаточно золота для уплаты налогов. Если до следующего сбора налогов у вас будет недостаточно золота, мы удалим вашу последнюю построенную постройку.");
                }
            } else {
                $collectedTax = $totalTax;
            }

            // Обновляем количество золота у персонажа
            $characterModel->update($characterId, ['gold' => $newGoldAmount]);

            // Обновляем статус сбора налогов для всех построек персонажа
            $characterBuildingModel->where('character_id', $characterId)
                ->set([
                    'last_tax_collected' => $now,
                    'tax_collection_status' => $taxCollectionStatus
                ])
                ->update();

            // Отправляем уведомление о сборе налогов
            $this->sendTaxCollectionNotification($character, $collectedTax, $newGoldAmount, $buildingCount);
        }
    }

    private function sendTelegramNotification($character, $message)
    {
        $telegramUserModel = new TelegramUserModel();
        $telegramUser = $telegramUserModel->find($character['telegram_user_id']);

        if ($telegramUser) {
            try {
                Request::sendMessage([
                    'chat_id' => $telegramUser['telegram_id'],
                    'text' => $message,
                    'parse_mode' => 'Markdown',
                ]);
            } catch (TelegramException $e) {
                log_message('error', 'Telegram API error: ' . $e->getMessage());
            }
        }
    }

    private function sendTaxCollectionNotification($character, $collectedTax, $newGoldAmount, $buildingCount)
    {
        $telegramUserModel = new TelegramUserModel();
        $telegramUser = $telegramUserModel->find($character['telegram_user_id']);

        if ($telegramUser) {
            $formattedCollectedTax = number_format($collectedTax, 0, '', ' ');
            $formattedNewGoldAmount = number_format($newGoldAmount, 0, '', ' ');

            $messageText = "📣 *Сбор налога произведен!*\n\n"
                . "Количество построек: *{$buildingCount}*\n"
                . "Собрано налога: *{$formattedCollectedTax}* золота\n"
                . "Оставшееся золото: *{$formattedNewGoldAmount}*";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🏠 База', 'callback_data' => 'Base'],
                    ],
                ]
            ];

            try {
                $imagePath = base_url('uploads/telegram/camp/tax_for_building.png');

                Request::sendPhoto([
                    'chat_id' => $telegramUser['telegram_id'],
                    'photo'   => Request::encodeFile($imagePath),
                    'caption' => $messageText,
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode($keyboard),
                ]);
            } catch (TelegramException $e) {
                log_message('error', 'Telegram API error: ' . $e->getMessage());
            }
        }
    }
}
<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\CharacterModel;

class UsePharmacyAction extends BaseAction
{
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;
    protected $characterModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->craftedItemsModel = new CraftedItemsModel();
        $this->characterModel = new CharacterModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendResponse('Пользователь не найден в базе данных или персонаж не определён.');
        }

        $callbackData = $this->callbackQuery->getData();
        $parts = explode('_', $callbackData);

        if (count($parts) > 1) {
            $medicineName = $parts[1];
            $itemId = $this->getCraftedItemId($medicineName);
            if (!$itemId) {
                return $this->sendResponse("Препарат '{$medicineName}' не найден.");
            }

            switch ($medicineName) {
                case 'TonicElixir':
                    return $this->applyMedicineEffect($character, $itemId, [
                        'health' => 18,
                        'tired' => 16,
                    ]);
                case 'Antiseptic':
                    return $this->applyMedicineEffect($character, $itemId, [
                        'health' => 4,
                        'tired' => 2,
                    ]);
                case 'Bandage':
                    return $this->applyMedicineEffect($character, $itemId, [
                        'health' => 2,
                        'tired' => 1,
                    ]);
                case 'AnalgesicPowder':
                    return $this->applyMedicineEffect($character, $itemId, [
                        'health' => 18,
                        'tired' => -4,
                    ]);
                case 'Sedative':
                    return $this->applyMedicineEffect($character, $itemId, [
                        'health' => 5,
                        'tired' => 30,
                    ]);
                case 'Stimulator':
                    return $this->applyMedicineEffect($character, $itemId, [
                        'health' => 25,
                        'tired' => 15,
                    ]);
                case 'Regenerator':
                    return $this->applyMedicineEffect($character, $itemId, [
                        'health' => 30,
                        'tired' => 20,
                    ]);
                case 'FirstAidKit':
                    return $this->applyMedicineEffect($character, $itemId, [
                        'health' => 40,
                        'tired' => 20,
                    ]);
                default:
                    return $this->sendResponse('Препарат не найден.');
            }
        } else {
            return $this->sendResponse('Неправильные данные кнопки.');
        }
    }

    private function applyMedicineEffect($character, $itemId, $effects)
    {
        $originalValues = [
            'health' => $character['health'] ?? 0,
            'tired' => $character['tired'] ?? 0,
            'gold' => $character['gold'] ?? 0,
            'experience' => $character['experience'] ?? 0,
            'strength' => $character['strength'] ?? 0,
            'agility' => $character['agility'] ?? 0,
            'intellect' => $character['intellect'] ?? 0
        ];

        $newValues = $originalValues;

        foreach ($effects as $key => $change) {
            if (array_key_exists($key, $newValues)) {
                $newValue = $newValues[$key] + $change;
                if ($key === 'health' || $key === 'tired') {
                    $newValues[$key] = min(100, $newValue);
                } else {
                    $newValues[$key] = $newValue;
                }
            }
        }

        $this->characterModel->update($character['id'], $newValues);

        if (!$this->decrementItemUsage($character['id'], $itemId)) {
            return $this->sendResponse('Ошибка при списании использования препарата.');
        }

        return $this->sendResponse('', $originalValues, $newValues);
    }

    private function getCraftedItemId($itemName)
    {
        $item = $this->craftedItemsModel->where('name_eng', $itemName)->first();
        return $item ? $item['id'] : null;
    }

    private function decrementItemUsage($characterId, $itemId)
    {
        $itemUsage = $this->craftedItemsLogModel->where([
            'character_id' => $characterId,
            'crafted_item_id' => $itemId
        ])->first();

        if (!$itemUsage) {
            log_message('error', "Item usage not found for character_id={$characterId} and item_id={$itemId}");
            return false;
        }

        if ($itemUsage['durability_count'] > 1) {
            $this->craftedItemsLogModel->update($itemUsage['id'], [
                'durability_count' => $itemUsage['durability_count'] - 1
            ]);
        } else {
            if ($itemUsage['quantity'] > 1) {
                $baseDurability = $this->craftedItemsModel->find($itemId)['durability_count'];
                $this->craftedItemsLogModel->update($itemUsage['id'], [
                    'quantity' => $itemUsage['quantity'] - 1,
                    'durability_count' => $baseDurability
                ]);
            } else {
                $this->craftedItemsLogModel->delete($itemUsage['id']);
            }
        }

        return true;
    }

    private function sendResponse($text, $originalValues = [], $newValues = [])
    {
        $formattedText = $text;
        if (!empty($newValues)) {
            $formattedText .= "\n\n🎉 *Твой герой получил бафы!* 🎉\n\n";
            foreach ($newValues as $key => $newValue) {
                if (isset($originalValues[$key]) && $newValue != $originalValues[$key]) {
                    $oldValue = $originalValues[$key];
                    $change = $newValue - $oldValue;
                    $attributeName = $this->getAttributeName($key);
                    $formattedText .= "**$attributeName:** было: $oldValue, стало: $newValue (изменение: $change)\n";
                }
            }
            $formattedText .= "\n**Будь начеку и береги себя!** 🛡️\n\n*P.S.* Не забудь проверить свои новые характеристики! 😉";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🛠️ Крафт', 'callback_data' => 'crafting']
                ],
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']
                ]
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $formattedText,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    private function getAttributeName($key)
    {
        $names = [
            'health' => 'Здоровье',
            'tired' => 'Выносливость',
            'gold' => 'Золото',
            'experience' => 'Опыт',
            'strength' => 'Сила',
            'agility' => 'Ловкость',
            'intellect' => 'Интеллект'
        ];
        return $names[$key] ?? ucfirst($key);
    }

}

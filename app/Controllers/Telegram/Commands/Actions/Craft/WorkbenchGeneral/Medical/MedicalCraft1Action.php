<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CraftedItemsLogModel;           // <-- Модель лога крафта
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class MedicalCraft1Action extends BaseAction
{
    protected $craftedItemsLogModel; // свойство для лога крафта

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        [$user, $character] = $this->getUserAndCharacter();
        $characterId = $character['id'] ?? null;

        // Основной текст шапки
        $text = "*Ты в разделе 💊 Лекарства!* 🏭\n\n"
            . "В этом разделе можно крафтить лекарственные препараты.\n\n"
            . "_Выбирай нужный предмет и приступай к крафту_ 👇\n";

        // Список предметов (как и раньше)
        $medicalItems = [
            [
                [ 'label' => '🧴 Антисептик',            'callback' => 'antiseptic',          'name_eng' => 'Antiseptic',          ],
                [ 'label' => '🌡️ Обезболивающий порошок','callback' => 'painReliefPowder',    'name_eng' => 'AnalgesicPowder',    ],
            ],
            [
                [ 'label' => '🧪 Укрепляющий эликсир',    'callback' => 'strengtheningElixir', 'name_eng' => 'TonicElixir', ],
                [ 'label' => '🩹 Повязка',               'callback' => 'bandage',             'name_eng' => 'Bandage',             ],
            ],
            [
                [ 'label' => '🫖 Успокоительное',         'callback' => 'sedative',            'name_eng' => 'Sedative',            ],
                [ 'label' => '💉 Стимулятор',            'callback' => 'stimulator',          'name_eng' => 'Stimulator',          ],
            ],
            [
                [ 'label' => '🔋 Регенератор',           'callback' => 'regenerator',         'name_eng' => 'Regenerator',         ],
                [ 'label' => '🚑 Аптечка базовая',       'callback' => 'basicMedKit',         'name_eng' => 'FirstAidKit',         ],
            ],
        ];

        // Формируем клавиатуру
        $keyboardRows = [];
        foreach ($medicalItems as $row) {
            $rowButtons = [];
            foreach ($row as $item) {
                // Оставляем кнопку "чистой", без указания количества
                $rowButtons[] = [
                    'text'          => $item['label'],
                    'callback_data' => $item['callback'],
                ];
            }
            $keyboardRows[] = $rowButtons;
        }

        // Если у нас есть ID персонажа, дополнительно сформируем блок "уже имеющихся" предметов
        if ($characterId) {
            $alreadyOwnedText = $this->makeAlreadyOwnedText($characterId, $medicalItems);
            if (!empty($alreadyOwnedText)) {
                // Добавим заголовок и список имеющихся
                $text .= "\n*Уже в наличии:*\n" . $alreadyOwnedText;
            }
        }

        $keyboard = ['inline_keyboard' => $keyboardRows];

        $imagePath = base_url('uploads/telegram/craft/craft_medications_in_the_field.jpg');

        // Ответим на callbackQuery, чтобы убрать "часики"
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправим фото с описанием
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Генерирует текст со списком уже имеющихся у игрока предметов
     * (только для тех, у кого quantity > 0).
     */
    private function makeAlreadyOwnedText(int $characterId, array $medicalItems): string
    {
        $lines = [];
        foreach ($medicalItems as $row) {
            foreach ($row as $item) {
                $quantity = $this->getCraftedItemQuantity($characterId, $item['name_eng']);
                if ($quantity > 0) {
                    $lines[] = "- {$item['label']}: {$quantity} шт.";
                }
            }
        }
        // Собираем в многострочный текст
        return !empty($lines) ? implode("\n", $lines) : '';
    }

    /**
     * Возвращает количество скрафченного предмета (англ. название) у персонажа.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        $logEntry = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $logEntry ? (int) $logEntry['quantity'] : 0;
    }
}

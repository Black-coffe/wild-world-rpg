<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Weapons;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class WeaponsCraft2Select extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // Краткое описание раздела с эмоджи
        $text = "*Ты в разделе ⚔️ Оружие!* 🏭\n\n"
            . "Ниже — 10 менее эффективных образцов (по возрастанию урона), которые можно скрафтить на стандартном верстаке.\n\n"
            . "Выбирай нужную модель и приступай к изготовлению:\n\n"
            . "1) 🗡️ *Металлическое копьё*\n"
            . "2) 🔫 *Трубчатый пистолет (Pipe Gun)*\n"
            . "3) 🏏 *Усовершенствованная бита*\n"
            . "4) 🏹 *Арбалет Mk.I*\n"
            . "5) 🔫 *Полуавтоматический пистолет*\n"
            . "6) ⚡ *Электрошоковая дубинка*\n"
            . "7) 🔫 *Тактический пистолет-пулемёт*\n"
            . "8) 🔫 *Обрез (усиленный)*\n"
            . "9) ⚡ *«Тесла-кастет»*\n"
            . "10) 🔫 *Боевой дробовик*\n";

        // Формируем кнопки (2 в ряду, всего 10 позиций), добавляем эмоджи
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🗡️ Металлич. копьё',   'callback_data' => 'craftMetalSpear'],
                    ['text' => '🔫 Трубч. пистолет',     'callback_data' => 'craftPipeGun'],
                ],
                [
                    ['text' => '🏏 Усоверш. бита',       'callback_data' => 'craftWiredBat'],
                    ['text' => '🏹 Арбалет Mk.I',        'callback_data' => 'craftCrossbowMk1'],
                ],
                [
                    ['text' => '🔫 Полуавт. пистолет',  'callback_data' => 'craftSemiAutoPistol'],
                    ['text' => '⚡ Электрошок дубинка',  'callback_data' => 'craftElectroBaton'],
                ],
                [
                    ['text' => '🔫 Тактический ПП',      'callback_data' => 'craftTacticalSMG'],
                    ['text' => '🔫 Обрез (усил.)',       'callback_data' => 'craftShortShotgun'],
                ],
                [
                    ['text' => '⚡ «Тесла-кастет»',       'callback_data' => 'craftTeslaKnuckles'],
                    ['text' => '🔫 Боевой дробовик',     'callback_data' => 'craftCombatShotgun'],
                ],
            ]
        ];

        // Можно использовать любую подходящую картинку
        $imagePath = base_url('uploads/telegram/craft/standard/all_weapons.jpg');

        // Убираем "часики" на кнопке
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // Возвращаем сообщение с фото и клавиатурой
        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}

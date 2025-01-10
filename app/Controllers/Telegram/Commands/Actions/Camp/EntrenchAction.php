<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ClaimedCellModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class EntrenchAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $claimedCellModel = new ClaimedCellModel();
        $claimedCells = $claimedCellModel->where('character_id', $character['id'])->findAll();
        $campCount = count($claimedCells);

        if ($campCount >= 2) {
            // У персонажа уже 2 базы
            $text = "🤖 Это снова я – *Роби*!\n\n"
                . "У тебя уже есть две базы. В игре можно иметь только две базы, больше не получится.";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                        ['text' => '🎉 События', 'callback_data' => 'events']
                    ],
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                        ['text' => '🛠️ Крафт', 'callback_data' => 'crafting']
                    ],
                    [
                        ['text' => '💊 Аптечка', 'callback_data' => 'pharmacy'],
                        ['text' => '🏠 База', 'callback_data' => 'Base'],
                    ]
                ]
            ];
        } elseif ($campCount == 1 && $character['level'] < 100) {
            // У персонажа один лагерь и уровень меньше 100
            $text = "🤖 Это снова я – *Роби*!\n\n"
                . "У тебя уже есть один лагерь, и ты не можешь построить еще один до достижения 100-го уровня. Желаем тебе быстрее достичь 100-го уровня!";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                        ['text' => '🎉 События', 'callback_data' => 'events']
                    ],
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                        ['text' => '🛠️ Крафт', 'callback_data' => 'crafting']
                    ],
                    [
                        ['text' => '💊 Аптечка', 'callback_data' => 'pharmacy'],
                        ['text' => '🏠 База', 'callback_data' => 'Base'],
                    ]
                ]
            ];
        } else {
            // Либо нет лагерей, либо один лагерь и уровень больше 100
            $text = "🏕 *Разбить лагерь*\n\n"
                . "Сейчас ты стоишь на пороге выбора: оставаться здесь или продолжить свой путь путешественника! "
                . "Как только ты поставишь палатку, это будет место закрепленное за тобой, и дальнейшие постройки ты можешь производить только в этой ячейке. "
                . "Ты сможешь все так же _добывать ресурсы_ и _исследовать территорию_. Чтобы вернуться на базу, тебе нужно будет иметь портативный телепорт или же за каждый возврат терять 1 единицу опыта!\n\n"
                . "*После 100 уровня персонажа* ты сможешь построить еще одну базу в любой другой точке карты. Принимай решение."
                . "\n\n*P.S.* Обязательно прочти о [системе разбивки лагеря](https://t.me/wild_world_info/418) 🌎";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🏕 Разбить лагерь', 'callback_data' => 'Camp'],
                        ['text' => '👤 Персонаж', 'callback_data' => 'character'],
                    ],
                ]
            ];
        }

        $imagePath = base_url('uploads/telegram/camp/entrenchAction.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}

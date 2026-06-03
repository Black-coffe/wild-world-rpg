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

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse(); // Переезд есть, сервис уже отписался
        }

        $claimedCellModel = new ClaimedCellModel();
        $claimedCells = $claimedCellModel->where('character_id', $character['id'])->findAll();
        $campCount = count($claimedCells);

        // ADR-095 Фаза 1a: лимит баз по уровню (admin-tunable, заменяет хардкод «2 / L100»).
        $baseLimit = new \App\Services\Bases\BaseLimitService();
        $level     = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 1;
        $maxBases  = $baseLimit->maxBasesForLevel($level);

        // Management-клавиатура (когда строить новую базу нельзя).
        $manageKeyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events'],
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                    ['text' => '💊 Аптечка', 'callback_data' => 'pharmacy'],
                ],
            ],
        ];

        if ($campCount >= $maxBases) {
            // Лимит баз для текущего уровня достигнут — объясняем игроку (UX-discoverability).
            $nextLevel = $baseLimit->nextBaseLevel($campCount);
            if ($nextLevel === null) {
                $text = "🤖 Это снова я – *Роби*!\n\n"
                    . "У тебя максимально возможное число баз — *{$campCount}*. Больше построить нельзя ни на каком уровне.";
            } else {
                $text = "🤖 Это снова я – *Роби*!\n\n"
                    . "Сейчас тебе доступно баз: *{$maxBases}* (по твоему уровню *{$level}*), и все они заняты.\n\n"
                    . "🔒 Следующая база откроется на *{$nextLevel}-м уровне*. Каждые *{$baseLimit->levelsPerBase()}* уровней "
                    . "открывают ещё одну базу (всего до *{$baseLimit->hardCap()}*).";
            }
            $keyboard = $manageKeyboard;
        } else {
            // Можно построить новую базу.
            $text = "🏕 *Разбить лагерь*\n\n"
                . "Сейчас ты стоишь на пороге выбора: оставаться здесь или продолжить свой путь путешественника! "
                . "Как только ты поставишь палатку, это будет место закрепленное за тобой, и дальнейшие постройки ты можешь производить только в этой ячейке. "
                . "Ты сможешь все так же _добывать ресурсы_ и _исследовать территорию_. Чтобы вернуться на базу, тебе нужно будет иметь портативный телепорт или же за каждый возврат терять 1 единицу опыта!\n\n"
                . ($maxBases > 1
                    ? "Тебе доступно баз: *{$maxBases}* (по уровню *{$level}*). Сейчас занято: *{$campCount}*. Каждые *{$baseLimit->levelsPerBase()}* уровней открывают ещё одну.\n\n"
                    : "")
                . "*P.S.* Обязательно прочти о [системе разбивки лагеря](https://t.me/wild_world_info/418) 🌎";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🏕 Разбить лагерь', 'callback_data' => 'Camp'],
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ],
                ],
            ];
        }

        $imagePath = base_url('uploads/telegram/camp/entrenchAction.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}

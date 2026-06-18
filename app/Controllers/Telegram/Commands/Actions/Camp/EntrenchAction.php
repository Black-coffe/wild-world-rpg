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

        // Лимит баз достигнут — объясняем игроку (UX-discoverability), дальше не идём.
        if ($campCount >= $maxBases) {
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

            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

            return \App\Services\Notifications\MediaSender::sendPhotoOrText([
                'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
                'photo'        => Request::encodeFile(base_url('uploads/telegram/camp/entrenchAction.jpg')),
                'caption'      => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($manageKeyboard),
            ]);
        }

        // Строить можно — сразу ведём на экран подтверждения (showCampCreation),
        // без промежуточного экрана-интро. Путь новичка сократился с 3 тапов
        // (Окопаться → Разбить лагерь → Подтвердить) до 2 (Окопаться → Разбить
        // лагерь здесь). «Что такое лагерь» теперь в самом экране подтверждения
        // (BaseServiceMessageFormatter::campCreationConfirm). showCampCreation
        // делает проверки (клетка занята / карта) и шлёт обогащённый confirm.
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return (new \App\Services\BaseService())->showCampCreation(
            $this->callbackQuery->getMessage()->getChat()->getId(),
            $character
        );
    }
}

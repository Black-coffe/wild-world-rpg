<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Bases\BaseCallbackSuffix;
use App\Services\Buildings\BuildOrderService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;
use App\Services\Tasks\ActiveTasksService;

/**
 * «🏗 Строить» (`Build`[`_b<id>`]) — список построек с налогом.
 *
 * w2-n4-base-02 (ADR-190): каталог (порядок, налог, Навес новичку, замки уровня) — ядро
 * {@see BuildOrderService::catalog()}, общее с вебом; здесь прежний текст и кнопки. База из суффикса
 * переезжает в кнопки карточек (`genericBuildInfo_<Key>_b<id>`) — карточка перепроверит её сама.
 */
class BuildListAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        // 1) answerCallbackQuery — убираем "часики"
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // 2) Получаем user/character
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Ошибка: нет пользователя/персонажа.'
            ]);
        }

        // 3) Проверяем переезд (если есть активная задача переезда, запрещаем строительство)
        $activeTasksService = new ActiveTasksService();
        $blocked = $activeTasksService->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        );
        if ($blocked) {
            // true = переезд активен, сервис уже отправил сообщение об этом, ничего не делаем дальше
            return Request::emptyResponse();
        }

        [, $baseId] = BaseCallbackSuffix::split((string) $this->callbackQuery->getData());
        $catalog    = (new BuildOrderService())->catalog((int) $character['id'], $baseId);

        $buildingList    = "";
        $keyboardButtons = [];
        foreach ($catalog['items'] as $item) {
            if ($item['locked']) {
                // S4 (ADR-139): уровневая постройка — lock-кнопка с уровнем, а не кнопка-обманка.
                $buildingList .= "🔒 *{$item['name']}* | _нужен lvl {$item['required_level']}_\n";
                $keyboardButtons[] = [
                    'text'          => $item['lock_label'],
                    'callback_data' => "buildLocked_{$item['key']}",
                ];
            } else {
                $buildingList .= "*{$item['name']}* | *Налог: {$item['tax']}* 💰\n";
                $callback          = "genericBuildInfo_{$item['key']}";
                $keyboardButtons[] = [
                    'text'          => $item['name'],
                    'callback_data' => $baseId !== null ? BaseCallbackSuffix::append($callback, $baseId) : $callback,
                ];
            }
        }

        // Разбиваем на ряды по 2 кнопки
        $keyboard = array_chunk($keyboardButtons, 2);

        $scope = new \App\Services\Tasks\ActionScopeService();
        $text = "🤖 Это я – *Роби*!\n\n"
            . $scope->legend(\App\Services\Tasks\ActionScopeService::KIND_BUILD) . "\n\n"
            . "Вот список доступных построек с указанием суточного налога:\n\n"
            . "{$buildingList}"
            . "\n_Выбери желаемое здание для строительства._";

        // #12 edit-in-place (ADR-018): меню строительства — навигация → редактируем текущее
        // сообщение (даём ему фото, чтобы editMessageMedia работал и из/в photo-меню базы).
        $imagePath = base_url('uploads/telegram/camp/Construction-by-improvised.jpg');
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }
}

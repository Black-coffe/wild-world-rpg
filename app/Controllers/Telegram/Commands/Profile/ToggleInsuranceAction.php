<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Переключение «страховки от смерти» в инвентаре персонажа.
 *
 * **Fix 2026-05-13 (prod-report Arseny/Arich):** раньше каждый клик отправлял
 * новое короткое сообщение `"✅ Страховка успешно включена."` без обновления
 * исходного экрана `PersonalInsurance`. Игрок не понимал, активирована ли
 * страховка СЕЙЧАС (кнопка в исходном сообщении показывала старый label),
 * и кликал повторно — за минуту получалась цепочка из 4+ спам-сообщений.
 *
 * Теперь:
 * - Flip значения insurance в БД (как было).
 * - `answerCallbackQuery` с toast'ом (короткий popup) — мгновенный feedback
 *   без нового сообщения в ленте.
 * - `MediaSender::editTextOrSend()` редактирует исходное сообщение
 *   `PersonalInsurance` in-place — обновляется и статус («есть/нет страховки»),
 *   и label кнопки («Включить»/«Отключить»). Цепочка спама невозможна.
 *
 * Идея #12 edit-in-place, ADR-018 — этот action классифицирован как
 * **navigation** (обновляет своё же state-view, не уведомление).
 */
class ToggleInsuranceAction extends BaseAction
{
    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();

        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $currentInsurance = (bool) ($character['insurance'] ?? false);
        $newInsurance     = !$currentInsurance;

        $this->characterModel->update($character['id'], ['insurance' => $newInsurance]);

        // Toast в callback'е — короткий popup-feedback без нового сообщения.
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $newInsurance ? '✅ Страховка включена' : '❌ Страховка отключена',
            'show_alert'        => false,
        ]);

        // Локально обновим entity для buildView (не делать second SELECT).
        $character['insurance'] = $newInsurance;

        // Edit-in-place — обновляем то самое сообщение, к которому привязана inline-кнопка.
        return MediaSender::editTextOrSend(self::buildEditPayload($character, $chatId, $this->callbackQuery->getMessage()->getMessageId()));
    }

    /**
     * Сборка payload для editTextOrSend: использует тот же view, что и
     * {@see PersonalInsurance::buildView()}, плюс `message_id` исходного сообщения
     * для edit-in-place.
     *
     * @param array<int|string, mixed>|\App\Entities\CharacterEntity $character
     * @return array{chat_id:int, text:string, parse_mode:string, reply_markup:string, message_id:int}
     */
    private static function buildEditPayload(array|\App\Entities\CharacterEntity $character, int $chatId, int $messageId): array
    {
        return PersonalInsurance::buildView($character, $chatId) + ['message_id' => $messageId];
    }
}

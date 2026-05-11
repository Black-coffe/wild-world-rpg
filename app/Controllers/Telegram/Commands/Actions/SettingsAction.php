<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Entities\CharacterEntity;
use App\Services\Notifications\MediaSender;
use ArrayAccess;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Идея #14 (Yupirex) — экран «⚙️ Настройки» с понятным тумблером «Картинки в сообщениях».
 *
 * Callback-флоу (один class, dispatch по callback_data):
 *   `settings`  → показать экран настроек (текущее состояние + кнопка переключения)
 *   `mediaOff`  → отключить картинки (`characters.disable_media = 1`) + перерисовать экран + toast
 *   `mediaOn`   → включить картинки  (`characters.disable_media = 0`) + перерисовать экран + toast
 *
 * Когда `disable_media = 1` — ВСЕ сообщения бота приходят текстом: все photo-отправки в коде
 * идут через {@see MediaSender} (`sendPhotoOrText`/`editOrSend`/`editTextOrSend`, прямых
 * `Request::sendPhoto(` в app-коде нет). Карта мира текстовая (emoji-сетка), на неё флаг не влияет.
 *
 * Точки входа на экран: callback `settings` (кнопка в меню), `/settings`-команда
 * ({@see \App\Controllers\Telegram\Commands\SettingsCommand}), текст «настройки»/кнопка
 * постоянной клавиатуры ({@see \App\Controllers\Telegram\Commands\SystemCommands\GenericmessageCommand}).
 * Старые текстовые команды `media_off` / `media_on` остаются (backwards-compat).
 *
 * Экран — текстовый (не photo): тумблер должен работать одинаково при любом значении флага.
 */
class SettingsAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId    = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        $character = $this->loadCharacter();
        if ($character === null) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе или персонаж не определён. Попробуйте /start.',
            ]);
        }

        $data  = $this->callbackQuery->getData();
        $toast = null;

        if ($data === 'mediaOff' || $data === 'mediaOn') {
            $disable = ($data === 'mediaOff') ? 1 : 0;
            // Меняем только если значение реально другое (anti «message is not modified» при re-render).
            if (self::disableMediaFlag($character) !== $disable) {
                $this->characterModel->update($character->id, ['disable_media' => $disable]);
                MediaSender::reset();
                // Перечитываем — buildScreen() должен показать новое состояние.
                $reloaded = $this->characterModel->find($character->id);
                if ($reloaded instanceof CharacterEntity) {
                    $character = $reloaded;
                }
            }
            $toast = ($disable === 1)
                ? '🚫 Картинки отключены — теперь только текст'
                : '🖼️ Картинки включены';
        }

        Request::answerCallbackQuery(array_filter([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $toast,
        ], static fn ($v): bool => $v !== null));

        // #12 edit-in-place (ADR-018): экран настроек — навигация → редактируем сообщение,
        // на котором нажата кнопка (fallback на новое при ошибке/клике с photo-экрана).
        return MediaSender::editTextOrSend($this->navTarget() + self::buildScreen($character));
    }

    /**
     * Находит CharacterEntity по telegram_id отправителя callback'а. null если не найдено.
     */
    private function loadCharacter(): ?CharacterEntity
    {
        $telegramId = (int) $this->callbackQuery->getFrom()->getId();
        /** @var array<string,mixed>|null $userRow */
        $userRow = $this->telegramUserModel->where('telegram_id', $telegramId)->first();
        if ($userRow === null) {
            return null;
        }
        /** @var CharacterEntity|null $character */
        $character = $this->characterModel->where('telegram_user_id', $userRow['id'] ?? 0)->first();

        return $character;
    }

    /**
     * Извлекает `disable_media` (0/1) из строки персонажа (CI4 Entity или array). 0 — дефолт.
     *
     * @param array<int|string,mixed>|object|null $character
     */
    public static function disableMediaFlag($character): int
    {
        $raw = 0;
        if ($character instanceof ArrayAccess) {
            // CharacterEntity implements ArrayAccess (trait ArrayAccessibleEntity) → offsetGet: mixed.
            $raw = $character['disable_media'] ?? 0;
        } elseif (is_array($character)) {
            $raw = $character['disable_media'] ?? 0;
        }

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Собирает text + reply_markup экрана настроек по текущему состоянию персонажа.
     * Используется и callback-handler'ом, и `/settings`-командой, и текстом «настройки».
     *
     * @param array<int|string,mixed>|object|null $character
     * @return array<string, string|bool>
     */
    public static function buildScreen($character): array
    {
        $disabled = self::disableMediaFlag($character) === 1;

        $state = $disabled
            ? '🚫 *отключены* — бот шлёт только текст'
            : '✅ *включены* — бот присылает изображения';

        $text = "⚙️ *Настройки*\n\n"
            . "🖼️ Картинки в сообщениях: {$state}\n\n"
            . "_Если у тебя медленный интернет или ты предпочитаешь чистый текст — отключи картинки. "
            . "Содержание и кнопки сообщений не изменятся, только пропадут изображения. "
            . "Карта мира текстовая всегда — на неё это не влияет._";

        $toggleButton = $disabled
            ? ['text' => '🖼️ Включить картинки',  'callback_data' => 'mediaOn']
            : ['text' => '🚫 Отключить картинки', 'callback_data' => 'mediaOff'];

        $keyboard = [
            'inline_keyboard' => [
                [$toggleButton],
                [['text' => '🔙 Назад', 'callback_data' => 'characterActions']],
            ],
        ];

        return [
            'text'                     => $text,
            'parse_mode'               => 'Markdown',
            'reply_markup'             => json_encode($keyboard) ?: '{}',
            'disable_web_page_preview' => true,
        ];
    }
}

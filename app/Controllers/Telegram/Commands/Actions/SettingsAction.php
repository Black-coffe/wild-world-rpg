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
        } elseif ($data === 'dailyTipsOn' || $data === 'dailyTipsOff') {
            // ADR-038 Фаза C — тумблер ежедневного «Совета дня» (opt-out).
            $enabled = ($data === 'dailyTipsOn') ? 1 : 0;
            if (self::dailyTipsFlag($character) !== $enabled) {
                $this->characterModel->update($character->id, ['daily_tips_enabled' => $enabled]);
                $reloaded = $this->characterModel->find($character->id);
                if ($reloaded instanceof CharacterEntity) {
                    $character = $reloaded;
                }
            }
            $toast = ($enabled === 1)
                ? '📌 Совет дня включён — Роби будет писать раз в сутки'
                : '🔕 Совет дня отключён';
        } elseif ($data === 'duelsOpenOn' || $data === 'duelsOpenOff') {
            // W17 (ADR-071) — тумблер «открыт к дуэлям» (opt-in честный PvP).
            $open = ($data === 'duelsOpenOn') ? 1 : 0;
            if (self::duelsOpenFlag($character) !== $open) {
                $this->characterModel->update($character->id, ['duels_open' => $open]);
                $reloaded = $this->characterModel->find($character->id);
                if ($reloaded instanceof CharacterEntity) {
                    $character = $reloaded;
                }
            }
            $toast = ($open === 1)
                ? '⚔️ Ты открыт к дуэлям — соперники на твоей клетке могут вызвать на честный бой'
                : '🛡 Ты закрыт для дуэлей';
        } elseif ($data === 'localeRu' || $data === 'localeEn') {
            // W27 (ADR-082) — переключатель языка интерфейса (gated killswitch i18n.locale_switch.enabled).
            $newLocale = ($data === 'localeEn') ? 'en' : 'ru';
            if (self::localeOf($character) !== $newLocale) {
                $this->characterModel->update($character->id, ['locale' => $newLocale]);
                $reloaded = $this->characterModel->find($character->id);
                if ($reloaded instanceof CharacterEntity) {
                    $character = $reloaded;
                }
            }
            $toast = ($newLocale === 'en') ? '🇬🇧 Language: English' : '🇷🇺 Язык: русский';
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
     * Извлекает `daily_tips_enabled` (0/1) из строки персонажа. 1 — дефолт (opt-out).
     *
     * @param array<int|string,mixed>|object|null $character
     */
    public static function dailyTipsFlag($character): int
    {
        $raw = 1;
        if ($character instanceof ArrayAccess) {
            $raw = $character['daily_tips_enabled'] ?? 1;
        } elseif (is_array($character)) {
            $raw = $character['daily_tips_enabled'] ?? 1;
        }

        return is_numeric($raw) ? (int) $raw : 1;
    }

    /**
     * W17 (ADR-071) — извлекает `duels_open` (0/1). 0 — дефолт (закрыт).
     *
     * @param array<int|string,mixed>|object|null $character
     */
    public static function duelsOpenFlag($character): int
    {
        $raw = 0;
        if ($character instanceof ArrayAccess) {
            $raw = $character['duels_open'] ?? 0;
        } elseif (is_array($character)) {
            $raw = $character['duels_open'] ?? 0;
        }

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * W27 — текущая локаль персонажа (ru/en, default ru).
     *
     * @param array<int|string,mixed>|object|null $character
     */
    private static function localeOf($character): string
    {
        $raw = '';
        if ($character instanceof ArrayAccess) {
            $raw = $character['locale'] ?? '';
        } elseif (is_array($character)) {
            $raw = $character['locale'] ?? '';
        }
        $loc = is_string($raw) ? strtolower(trim($raw)) : '';
        return $loc === 'en' ? 'en' : 'ru';
    }

    /** W27 — killswitch переключателя языка (default OFF dormant до локализации поверхностей W28+). */
    private static function localeSwitchEnabled(): bool
    {
        try {
            $v = (new \App\Services\GameSettings\GameSettingsService())->get('i18n.locale_switch.enabled', false);
        } catch (\Throwable) {
            return false;
        }
        return is_bool($v) ? $v : (is_numeric($v) && (int) $v === 1);
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

        $tipsOn    = self::dailyTipsFlag($character) === 1;
        $tipsState = $tipsOn
            ? '✅ *включён* — Роби пишет совет раз в сутки'
            : '🔕 *отключён*';

        $text = "⚙️ *Настройки*\n\n"
            . "🖼️ Картинки в сообщениях: {$state}\n\n"
            . "📌 Совет дня: {$tipsState}\n\n"
            . "_Если у тебя медленный интернет или ты предпочитаешь чистый текст — отключи картинки. "
            . "Содержание и кнопки сообщений не изменятся, только пропадут изображения. "
            . "Карта мира текстовая всегда — на неё это не влияет._\n\n"
            . "_«Совет дня» — раз в сутки Роби сам пришлёт случайный игровой совет с микро-прокачкой. "
            . "Не нужно — отключи; вызвать совет вручную всегда можно командой /tips._";

        $toggleButton = $disabled
            ? ['text' => '🖼️ Включить картинки',  'callback_data' => 'mediaOn']
            : ['text' => '🚫 Отключить картинки', 'callback_data' => 'mediaOff'];

        $tipsButton = $tipsOn
            ? ['text' => '🔕 Отключить совет дня', 'callback_data' => 'dailyTipsOff']
            : ['text' => '📌 Включить совет дня',  'callback_data' => 'dailyTipsOn'];

        $rows = [[$toggleButton], [$tipsButton]];

        // W17 (ADR-071) — тумблер «открыт к дуэлям» показываем только при активном killswitch.
        if ((new \App\Services\PVE\DuelService())->enabled()) {
            $duelsOpen = self::duelsOpenFlag($character) === 1;
            $duelState = $duelsOpen
                ? '⚔️ *открыт* — соперники могут вызвать на честную дуэль'
                : '🛡 *закрыт* — дуэли отключены';
            $text .= "\n\n⚔️ Открыт к дуэлям: {$duelState}\n\n"
                . "_Дуэль — честный бой на равных статах (без потери здоровья и опыта). Открой, если хочешь принимать вызовы соперников на твоей клетке._";
            $rows[] = [$duelsOpen
                ? ['text' => '🛡 Закрыться от дуэлей', 'callback_data' => 'duelsOpenOff']
                : ['text' => '⚔️ Открыться к дуэлям',  'callback_data' => 'duelsOpenOn']];
        }

        // W27 (ADR-082) — переключатель языка интерфейса (показываем только при killswitch on).
        if (self::localeSwitchEnabled()) {
            $isEn      = self::localeOf($character) === 'en';
            $langState = $isEn ? '🇬🇧 *English*' : '🇷🇺 *русский*';
            $text .= "\n\n🌐 Язык / Language: {$langState}";
            $rows[] = [$isEn
                ? ['text' => '🇷🇺 Русский', 'callback_data' => 'localeRu']
                : ['text' => '🇬🇧 English', 'callback_data' => 'localeEn']];
        }

        $rows[] = [['text' => '🔙 Назад', 'callback_data' => 'characterActions']];
        $keyboard = ['inline_keyboard' => $rows];

        return [
            'text'                     => $text,
            'parse_mode'               => 'Markdown',
            'reply_markup'             => json_encode($keyboard) ?: '{}',
            'disable_web_page_preview' => true,
        ];
    }
}

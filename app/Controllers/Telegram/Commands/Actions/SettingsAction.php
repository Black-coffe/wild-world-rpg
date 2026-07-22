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
        } elseif ($data === 'notifySoundOn' || $data === 'notifySoundOff') {
            // W28 (ADR-083) — тумблер «звук о завершении задач» (gated killswitch
            // notifications.silent_threshold.enabled). notify_sound=1 → рутинные
            // завершения приходят со звуком; 0 → тихо (disable_notification).
            $sound = ($data === 'notifySoundOn') ? 1 : 0;
            if (self::notifySoundFlag($character) !== $sound) {
                $this->characterModel->update($character->id, ['notify_sound' => $sound]);
                \App\Services\Notifications\SilentNotificationPolicy::reset();
                $reloaded = $this->characterModel->find($character->id);
                if ($reloaded instanceof CharacterEntity) {
                    $character = $reloaded;
                }
            }
            $toast = ($sound === 1)
                ? '🔔 Звук завершения задач включён'
                : '🔕 Завершения задач теперь тихие — без звука';
        } elseif ($data === 'mapAccurate' || $data === 'mapBeautiful') {
            // Сигнал игрока (21.07.2026): «режим карты хрен поменять уже, да?». Тип карты
            // менялся ТОЛЬКО слепым вводом `accurate_map`/`beautiful_map`, а узнать о командах
            // можно было лишь на экране «тип не выбран» — который после первого выбора не
            // показывается никогда. Фича без входа (UX-DISCOVERABILITY) → тумблер здесь.
            $newType = ($data === 'mapAccurate') ? 'accurate' : 'beautiful';
            if (self::mapTypeOf($character) !== $newType) {
                $this->characterModel->update($character->id, ['preferred_map_type' => $newType]);
                $reloaded = $this->characterModel->find($character->id);
                if ($reloaded instanceof CharacterEntity) {
                    $character = $reloaded;
                }
            }
            $toast = ($newType === 'accurate')
                ? '🗺 Карта: точная — пиксель в пиксель'
                : '🎨 Карта: художественная';
        } elseif ($data === 'nodeAnnounceOn' || $data === 'nodeAnnounceOff') {
            // WB11 (ADR-137) — тумблер «Сводка с пустоши» (opt-out ежедневного дайджеста узлов).
            $enabled = ($data === 'nodeAnnounceOn') ? 1 : 0;
            if (self::nodeAnnounceFlag($character) !== $enabled) {
                $this->characterModel->update($character->id, ['node_announce_enabled' => $enabled]);
                $reloaded = $this->characterModel->find($character->id);
                if ($reloaded instanceof CharacterEntity) {
                    $character = $reloaded;
                }
            }
            $toast = ($enabled === 1)
                ? '📻 Сводка с пустоши включена — раз в сутки придёт сводка о павших узлах'
                : '🔕 Сводка с пустоши отключена';
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
     * W28 (ADR-083) — извлекает `notify_sound` (0/1). 0 — дефолт (рутинные завершения
     * тихие при активном killswitch). 1 — игрок вернул себе звук.
     *
     * @param array<int|string,mixed>|object|null $character
     */
    public static function notifySoundFlag($character): int
    {
        $raw = 0;
        if ($character instanceof ArrayAccess) {
            $raw = $character['notify_sound'] ?? 0;
        } elseif (is_array($character)) {
            $raw = $character['notify_sound'] ?? 0;
        }

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * WB11 (ADR-137) — извлекает `node_announce_enabled` (0/1). 1 — дефолт (opt-out).
     *
     * @param array<int|string,mixed>|object|null $character
     */
    public static function nodeAnnounceFlag($character): int
    {
        $raw = 1;
        if ($character instanceof ArrayAccess) {
            $raw = $character['node_announce_enabled'] ?? 1;
        } elseif (is_array($character)) {
            $raw = $character['node_announce_enabled'] ?? 1;
        }

        return is_numeric($raw) ? (int) $raw : 1;
    }

    /**
     * Текущий тип карты мира: 'accurate' | 'beautiful' | null (ещё не выбран).
     * Хранится в `characters.preferred_map_type`, читается {@see \App\Services\World\MapService}.
     *
     * @param array<int|string,mixed>|object|null $character
     */
    public static function mapTypeOf($character): ?string
    {
        $raw = null;
        if ($character instanceof ArrayAccess) {
            $raw = $character['preferred_map_type'] ?? null;
        } elseif (is_array($character)) {
            $raw = $character['preferred_map_type'] ?? null;
        }
        $type = is_string($raw) ? strtolower(trim($raw)) : '';

        return match ($type) {
            'accurate'  => 'accurate',
            'beautiful' => 'beautiful',
            default     => null,
        };
    }

    /** WB11 — killswitch анонса узлов (тумблер показываем только при активном анонсе). */
    private static function nodeAnnounceEnabled(): bool
    {
        try {
            $v = (new \App\Services\GameSettings\GameSettingsService())->get('world.nodes.announce_enabled', false);
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

        // Тип карты мира (2026-07-22) — раньше менялся только текстовыми командами
        // `accurate_map`/`beautiful_map`, о которых игрок узнавал единственный раз: на экране
        // «тип не выбран» до первого выбора. Живой сигнал из чата → полноценный тумблер.
        $mapType  = self::mapTypeOf($character);
        $mapState = match ($mapType) {
            'accurate'  => '🗺 *точная* — пиксель в пиксель',
            'beautiful' => '🎨 *художественная* — красивее, но менее точная',
            default     => '❓ *не выбран* — выбери ниже',
        };
        $text .= "\n\n🗺 Вид карты мира: {$mapState}\n\n"
            . "_Так выглядит картинка «🗺 Обзор» на экране карты. Точная — пиксель в пиксель, "
            . "по ней удобно считать координаты. Художественная — живописнее, но менее точная. "
            . "Текстовая карта-сетка и легенда одинаковы при любом выборе; переключать можно сколько угодно раз._";

        $accurateBtn  = ['text' => '🗺 Точная карта',         'callback_data' => 'mapAccurate'];
        $beautifulBtn = ['text' => '🎨 Художественная карта', 'callback_data' => 'mapBeautiful'];
        // Выбор не сделан → показываем оба варианта; иначе — кнопку на противоположный.
        $rows[] = match ($mapType) {
            'accurate'  => [$beautifulBtn],
            'beautiful' => [$accurateBtn],
            default     => [$accurateBtn, $beautifulBtn],
        };

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

        // W28 (ADR-083) — тумблер «звук о завершении задач» (показываем только при killswitch on).
        if (\App\Services\Notifications\SilentNotificationPolicy::enabled()) {
            $soundOn     = self::notifySoundFlag($character) === 1;
            $soundState  = $soundOn
                ? '🔔 *включён* — завершения приходят со звуком'
                : '🔕 *тихо* — завершения приходят без звука/вибро';
            $text .= "\n\n🔔 Звук о завершении задач: {$soundState}\n\n"
                . "_Рутинные завершения (добыча / крафт / стройка / разведка) по умолчанию приходят тихо — "
                . "видны в чате, но телефон не звенит. Важные события (атаки, мировые события, награды) всегда со звуком. "
                . "Хочешь слышать каждое завершение — включи звук._";
            $rows[] = [$soundOn
                ? ['text' => '🔕 Сделать тихими', 'callback_data' => 'notifySoundOff']
                : ['text' => '🔔 Включить звук',  'callback_data' => 'notifySoundOn']];
        }

        // WB11 (ADR-137) — тумблер «Сводка с пустоши» (показываем только при активном анонсе узлов).
        if (self::nodeAnnounceEnabled()) {
            $annOn    = self::nodeAnnounceFlag($character) === 1;
            $annState = $annOn
                ? '📻 *включена* — раз в сутки сводка о павших узлах'
                : '🔕 *отключена*';
            $text .= "\n\n📻 Сводка с пустоши: {$annState}\n\n"
                . "_Раз в сутки радист Корвин шлёт обезличенную сводку о повергнутых узлах (без имён и координат). Не нужно — отключи._";
            $rows[] = [$annOn
                ? ['text' => '🔕 Отключить сводку',  'callback_data' => 'nodeAnnounceOff']
                : ['text' => '📻 Включить сводку',   'callback_data' => 'nodeAnnounceOn']];
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

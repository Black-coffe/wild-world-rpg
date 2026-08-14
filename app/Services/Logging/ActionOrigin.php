<?php

declare(strict_types=1);

namespace App\Services\Logging;

use App\Services\GameSettings\GameSettingsService;

/**
 * ADR-168 — метка источника нажатия (origin) на callback_data.
 *
 * Задача. Firehose ADR-148 пишет `action_name='gather'` одинаково для шести разных экранов,
 * которые эту кнопку рисуют (компас ходьбы, хаб действий, уведомление о голоде, финиш Похода,
 * экран здания, нехватка сырья при крафте). Из-за этого замер слайса «второй шаг» 2026-08-12
 * не смог отделить вклад подписи добычи на компасе от входов из меню — вопрос «откуда игрок
 * зашёл в добычу» технически не имел ответа, и слайс пришлось мерить когортами, которым при
 * притоке ≈1.7 регистрации в сутки нужны месяцы до значимости.
 *
 * Механика. Кнопка может нести хвост `<callback_data>~<origin>`. Хвост снимается ЦЕНТРАЛЬНО в
 * {@see \App\Controllers\Telegram\BotController::webhook()} до того, как апдейт попадёт в Longman,
 * поэтому все ~250 хендлеров видят прежнюю строку и остаются byte-identical. Снятая метка живёт
 * в request-scoped холдере: её читает firehose (колонка `origin`) и экраны, которым нужно
 * протащить источник на следующий тап (меню длительности добычи).
 *
 * 🔴 Снятие метки — БЕЗУСЛОВНОЕ, killswitch на него не влияет. Кнопки живут в истории чата
 * вечно: если выключить простановку меток, уже отправленные `gather~cmp` обязаны продолжать
 * работать. Killswitch гасит только {@see tag()} — простановку новых меток.
 *
 * 🔴 Разделитель `~`, а НЕ `_`: firehose берёт `action_name` как `explode('_', $data)[0]`, и
 * подчёркивание сдвинуло бы разбор, а роутер режет callback_data по нему же. Проверено: `~` не
 * встречается ни в одном существующем callback_data.
 *
 * @see \App\Services\Logging\PlayerActionLogger
 */
final class ActionOrigin
{
    /** Разделитель «действие ~ источник». */
    public const SEPARATOR = '~';

    /** Killswitch простановки меток (снятие безусловно). */
    public const KILLSWITCH = 'logging.action_origin.enabled';

    /**
     * Каталог меток. Держим списком, чтобы коды не расползались по экранам и чтобы SQL-замер
     * читался словами, а не догадками. Метка отвечает на вопрос «с какого ЭКРАНА нажали»,
     * а не «какое действие» — действие уже есть в `action_name`.
     */
    public const FROM_COMPASS  = 'cmp'; // поверхность ходьбы (компас) — слайс «второй шаг»
    public const FROM_HUB      = 'hub'; // хаб «🧑‍🌾 Действия 🛠️»
    public const FROM_NEEDS    = 'fw';  // уведомление о голоде/жажде
    public const FROM_MARCH    = 'mar'; // экран завершения Похода
    public const FROM_BUILDING = 'bld'; // экран постройки на базе
    public const FROM_CRAFT    = 'crf'; // «не хватает сырья» в крафте

    /** Предел Telegram на callback_data — 64 БАЙТА. Метка не имеет права его пробить. */
    private const MAX_CALLBACK_BYTES = 64;

    /** Разумный предел самой метки (колонка `origin` — VARCHAR(16)). */
    private const MAX_ORIGIN_LEN = 12;

    /** Источник текущего апдейта (request-scoped; ставит BotController). */
    private static ?string $current = null;

    /**
     * Оверрайд killswitch'а.
     *
     * @internal ТОЛЬКО для тестов. Класс статический (его зовут из шаблонов клавиатур, где
     *           внедрять зависимость некуда), поэтому seam'а через наследника, как у
     *           {@see PlayerActionLogger}, здесь не построить. В проде значение всегда null
     *           и читается GameSettings.
     */
    private static ?bool $enabledOverride = null;

    // ── request-scoped холдер ────────────────────────────────────────────

    /** Запомнить источник текущего нажатия. */
    public static function set(?string $origin): void
    {
        self::$current = self::normalize($origin);
    }

    /** Источник текущего нажатия (null — метки не было). */
    public static function current(): ?string
    {
        return self::$current;
    }

    /** Сброс холдера (тесты / гигиена). */
    public static function reset(): void
    {
        self::$current = null;
    }

    // ── простановка и снятие метки ───────────────────────────────────────

    /**
     * Пометить callback_data источником: `gather` + `cmp` → `gather~cmp`.
     *
     * 🔴 Defensive — возвращает исходную строку без изменений, если: killswitch выключен,
     * метка пустая/некорректная, в строке уже есть метка, либо помеченная строка пробила бы
     * 64-байтный предел Telegram (иначе отвалилась бы ВСЯ клавиатура, а не телеметрия).
     */
    public static function tag(string $callbackData, ?string $origin): string
    {
        $clean = self::normalize($origin);
        if ($clean === null || $callbackData === '') {
            return $callbackData;
        }
        if (str_contains($callbackData, self::SEPARATOR)) {
            return $callbackData;
        }
        if (! self::enabled()) {
            return $callbackData;
        }

        $tagged = $callbackData . self::SEPARATOR . $clean;

        return strlen($tagged) > self::MAX_CALLBACK_BYTES ? $callbackData : $tagged;
    }

    /** Снять метку: `gather~cmp` → `gather`. Безусловно (см. шапку класса). */
    public static function strip(string $callbackData): string
    {
        $pos = strpos($callbackData, self::SEPARATOR);

        return $pos === false ? $callbackData : substr($callbackData, 0, $pos);
    }

    /** Достать метку: `gather~cmp` → `cmp`; без метки — null. */
    public static function extract(string $callbackData): ?string
    {
        $pos = strpos($callbackData, self::SEPARATOR);
        if ($pos === false) {
            return null;
        }

        return self::normalize(substr($callbackData, $pos + strlen(self::SEPARATOR)));
    }

    /**
     * Снять метку с сырого Telegram-апдейта.
     *
     * 🔴 Третий элемент — «апдейт ИЗМЕНЁН», и он НЕ равен «метка распознана». Мусорный хвост
     * (`gather~ЧУШЬ`) очищается так же, как валидный, но источником не становится. Если
     * связать перезапись ввода с наличием метки, а не с фактом очистки, Longman получит
     * СЫРУЮ строку, которую роутер не знает → мёртвая кнопка, а firehose при этом запишет
     * очищенную. Поймано Tier-3 на testbot 14.08: `gather~ЧУШЬ` → `status='unrouted'` при
     * `raw_input='gather'` — телеметрия разошлась с тем, что реально произошло.
     *
     * @param array<array-key,mixed> $update
     *
     * @return array{0: array<array-key,mixed>, 1: string|null, 2: bool} апдейт + метка + изменён ли
     */
    public static function stripUpdate(array $update): array
    {
        if (! isset($update['callback_query']) || ! is_array($update['callback_query'])) {
            return [$update, null, false];
        }

        $data = $update['callback_query']['data'] ?? null;
        if (! is_string($data) || ! str_contains($data, self::SEPARATOR)) {
            return [$update, null, false];
        }

        $origin = self::extract($data);
        $update['callback_query']['data'] = self::strip($data);

        return [$update, $origin, true];
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * Привести метку к каноничному виду: только `[a-z0-9]`, до 12 знаков. Всё остальное —
     * null (лучше не записать источник, чем записать мусор в телеметрию).
     */
    private static function normalize(?string $origin): ?string
    {
        if ($origin === null) {
            return null;
        }
        $clean = strtolower(trim($origin));
        if ($clean === '' || strlen($clean) > self::MAX_ORIGIN_LEN) {
            return null;
        }

        return preg_match('/^[a-z0-9]+$/', $clean) === 1 ? $clean : null;
    }

    /**
     * Подменить killswitch.
     *
     * @internal ТОЛЬКО для тестов; null возвращает чтение GameSettings.
     */
    public static function overrideEnabled(?bool $value): void
    {
        self::$enabledOverride = $value;
    }

    /** Killswitch простановки. 🔴 Defensive: недоступные настройки → выключено. */
    public static function enabled(): bool
    {
        if (self::$enabledOverride !== null) {
            return self::$enabledOverride;
        }

        try {
            $raw = (new GameSettingsService())->get(self::KILLSWITCH, false);
            if (is_bool($raw)) {
                return $raw;
            }

            return is_numeric($raw) && (int) $raw === 1;
        } catch (\Throwable $e) {
            log_message('error', '[ActionOrigin] enabled failed: ' . $e->getMessage());

            return false;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Services\GameSettings\GameSettingsReaderTrait;

/**
 * health-warning-backoff, story-02 (brief — docs/specs/health-warning-backoff/brief.md).
 *
 * Чистая логика решения «слать/не слать» предупреждение о низком здоровье — без БД и без
 * Telegram. `App\TaskHandlers\LowHealthWarningHandler` собирает вход (состояние персонажа +
 * факт реакции игрока из `player_action_log`, найденный ОДНИМ запросом на весь пакет) и
 * применяет результат к колонкам `characters.low_health_*` (story-01).
 *
 * Правила (владелец: «если человек на одно, два, три сообщения не реагирует — забить»):
 *  1. Затухание: интервал = base × multiplier^streak, обрезан max (числа — GameSettings,
 *     категория `world`, ключи `health.warn.*`).
 *  2. Реакция игрока (запись в player_action_log позже last_notified_at) сбрасывает streak —
 *     следующий интервал снова базовый.
 *  3. Провал в СЛЕДУЮЩУЮ полосу вниз (границы из `health.warn.bands`, по убыванию) — новость:
 *     шлём немедленно, минуя затухание, и сбрасываем streak. Возврат в полосу выше сам по себе
 *     поводом не является — last_band не трогаем, если не шлём.
 *  4. Активное damage-событие укорачивает интервал до критического потолка (не пробивает
 *     затухание безусловно на каждой проверке — иначе спам вернулся бы за 1 минуту).
 *  5. Дневной потолок (`daily_cap`) — только вне критической полосы; растёт только когда
 *     реально отправили некритическое предупреждение.
 *  6. Критическая полоса — САМАЯ НИЖНЯЯ граница из `health.warn.bands` (не зашитая
 *     константа): если админ подвинет список границ, критичность едет с ним, а не
 *     расходится с ним. Свой (короткий) base/max, без дневного потолка.
 *
 * Осознанное решение по нулю/отрицательному здоровью (ревью health-warning-backoff-02):
 * критический режим требует `health > 0` — персонаж РОВНО на нуле (или ниже, до того как
 * рулетка его убьёт) НЕ получает укороченный интервал/обход потолка. Это то же условие,
 * что было в коде до этой истории (`0.0 < health <= 0.10`) — сохранено намеренно, не молча.
 */
final class HealthWarningSchedule
{
    use GameSettingsReaderTrait;

    /** Дедуп лог-спама на мусорном `health.warn.bands` — см. {@see bandBoundaries()}. */
    private static ?string $lastLoggedBadBands = null;

    /**
     * @return array{
     *     should_send: bool,
     *     warn_streak: int,
     *     last_band: float,
     *     warns_today: int,
     *     warns_day: string,
     *     last_notified_at: int,
     * }
     */
    public function decide(
        float $health,
        ?int $lastNotifiedAt,
        int $warnStreak,
        ?float $lastBand,
        int $warnsToday,
        ?string $warnsDay,
        ?int $lastActionAt,
        bool $activeDamageEvent,
        int $now,
    ): array {
        $today = date('Y-m-d', $now);
        if ($warnsDay !== $today) {
            // Смена суток — счётчик потолка обнуляется, независимо от того, шлём ли сейчас.
            $warnsToday = 0;
        }

        $boundaries        = $this->bandBoundaries();
        $criticalThreshold = $boundaries[0]; // ascending — самая нижняя граница
        $isCritical        = $health > 0.0 && $health <= $criticalThreshold;
        $band              = $this->bandFor($health, $boundaries);
        $bandDropped       = $lastBand !== null && $band < $lastBand;

        $reacted = $lastActionAt !== null
            && $lastNotifiedAt !== null
            && $lastActionAt > $lastNotifiedAt;

        $streak = $reacted ? 0 : $warnStreak;

        if ($lastNotifiedAt === null || $bandDropped) {
            // Первое предупреждение вообще, либо ухудшение полосы — пробивает затухание.
            $shouldSend = true;
        } else {
            $intervalMin = $this->intervalMinutes($isCritical, $streak, $activeDamageEvent);
            $elapsedMin  = ($now - $lastNotifiedAt) / 60;
            $shouldSend  = $elapsedMin >= $intervalMin;
        }

        if ($shouldSend && ! $isCritical && $warnsToday >= $this->gsInt('health.warn.daily_cap', 5)) {
            $shouldSend = false;
        }

        $newStreak         = $streak;
        $newLastBand       = $lastBand ?? $band;
        $newLastNotifiedAt = $lastNotifiedAt ?? $now;

        if ($shouldSend) {
            $newLastNotifiedAt = $now;
            $newLastBand       = $band;
            $newStreak         = $bandDropped ? 0 : $streak + 1;
            if (! $isCritical) {
                $warnsToday++;
            }
        }

        return [
            'should_send'      => $shouldSend,
            'warn_streak'      => $newStreak,
            'last_band'        => $newLastBand,
            'warns_today'      => $warnsToday,
            'warns_day'        => $today,
            'last_notified_at' => $newLastNotifiedAt,
        ];
    }

    /**
     * Интервал (минуты) до следующего предупреждения на текущем streak. Активное
     * damage-событие клэмпит его вниз до критического базового — «предупредить чаще»
     * без безусловного пробивания затухания на каждой минутной проверке.
     */
    private function intervalMinutes(bool $isCritical, int $streak, bool $activeDamageEvent): float
    {
        $multiplier = $this->gsFloat('health.warn.backoff_multiplier', 2.0);

        if ($isCritical) {
            $base = $this->gsFloat('health.warn.critical_interval_min', 5.0);
            $max  = $this->gsFloat('health.warn.critical_max_interval_min', 30.0);
        } else {
            $base = $this->gsFloat('health.warn.base_interval_min', 33.0);
            $max  = $this->gsFloat('health.warn.max_interval_min', 360.0);
        }

        $interval = min($base * ($multiplier ** max(0, $streak)), $max);

        if ($activeDamageEvent) {
            $interval = min($interval, $this->gsFloat('health.warn.critical_interval_min', 5.0));
        }

        return $interval;
    }

    /**
     * Нижняя граница полосы, в которую попадает health (самая узкая подходящая).
     *
     * @param list<float> $boundaries по возрастанию — берётся один раз в decide(), не
     *                                перепарсивается здесь (та же причина, что у дедупа
     *                                лога в {@see bandBoundaries()}: один вызов на персонажа).
     */
    private function bandFor(float $health, array $boundaries): float
    {
        foreach ($boundaries as $boundary) {
            if ($health <= $boundary) {
                return $boundary;
            }
        }

        return $boundaries[count($boundaries) - 1];
    }

    /**
     * Границы полос по возрастанию, из одного ключа-строки `health.warn.bands`
     * (`"5,3,1,0.1"`) — в схеме `game_settings` нет колонки под список, а границы всегда
     * читаются вместе как упорядоченный набор. Значение приходит из admin UI, а этот код
     * крутится в кроне раз в минуту — падать на мусорном вводе (пустая строка, лишняя
     * запятая, нечисло) нельзя: берём зашитый дефолт.
     *
     * Лог о деградации — ОДИН раз на конкретное мусорное значение (не на каждого персонажа
     * и не на каждый тик): опечатка в админке иначе даёт непрерывный поток строк в лог,
     * умноженный на число игроков с низким HP. Как только значение снова парсится (админ
     * исправил) или изменилось на другое мусорное — дедуп сбрасывается.
     *
     * @return list<float>
     */
    private function bandBoundaries(): array
    {
        $raw    = $this->gsString('health.warn.bands', '5,3,1,0.1');
        $parsed = self::parseBands($raw);

        if ($parsed === null) {
            if (self::$lastLoggedBadBands !== $raw) {
                log_message('warning', "HealthWarningSchedule: health.warn.bands не распарсен ('{$raw}'), беру дефолт 5,3,1,0.1");
                self::$lastLoggedBadBands = $raw;
            }

            return [0.1, 1.0, 3.0, 5.0];
        }

        self::$lastLoggedBadBands = null;

        return $parsed;
    }

    /**
     * Чистый разбор строки границ ("5,3,1,0.1", пробелы и порядок не важны) в список по
     * возрастанию. Публичный статический — тестируется прямым вызовом, без GameSettings.
     * Мусор (пустая строка, лишняя запятая, нечисловой кусок) → `null`, вызывающий код сам
     * решает, что делать с дефолтом (в проде — {@see bandBoundaries()} логирует и подставляет).
     *
     * @return list<float>|null
     */
    public static function parseBands(string $raw): ?array
    {
        $boundaries = [];
        foreach (explode(',', $raw) as $part) {
            $trimmed = trim($part);
            // Пустой кусок (пустая строка целиком либо лишняя/задвоенная запятая) и
            // нечисловой кусок — оба «мусор», трактуем одинаково: весь список невалиден.
            if ($trimmed === '' || ! is_numeric($trimmed)) {
                return null;
            }
            $boundaries[] = (float) $trimmed;
        }

        sort($boundaries);

        return $boundaries;
    }

    /**
     * Какие колонки `characters.low_health_*` записать после {@see decide()} — вынесено из
     * `LowHealthWarningHandler`, чтобы ветку «не шлём, но состояние всё равно меняется»
     * (реакция игрока сбросила streak, либо сменились сутки) можно было проверить тестом
     * без похода в БД. На входе — предыдущее состояние персонажа + результат `decide()`
     * для НЕГО ЖЕ; на выходе — набор полей к UPDATE (пустой массив = писать нечего вообще,
     * хендлер пропускает запрос).
     *
     * @param array{should_send: bool, warn_streak: int, last_band: float, warns_today: int, warns_day: string, last_notified_at: int} $decision
     * @return array<string, int|float|string>
     */
    public static function fieldsToPersist(
        array $decision,
        int $prevWarnStreak,
        ?float $prevLastBand,
        int $prevWarnsToday,
        ?string $prevWarnsDay,
    ): array {
        if ($decision['should_send']) {
            // Отправили — пишем всё, включая новый last_notified_at.
            return [
                'low_health_notified_at' => date('Y-m-d H:i:s', $decision['last_notified_at']),
                'low_health_warn_streak' => $decision['warn_streak'],
                'low_health_last_band'   => $decision['last_band'],
                'low_health_warns_today' => $decision['warns_today'],
                'low_health_warns_day'   => $decision['warns_day'],
            ];
        }

        $unchanged = $decision['warn_streak'] === $prevWarnStreak
            && $decision['last_band'] === $prevLastBand
            && $decision['warns_today'] === $prevWarnsToday
            && $decision['warns_day'] === $prevWarnsDay;

        if ($unchanged) {
            return [];
        }

        // Не отправили, но что-то из состояния затухания всё же изменилось (реакция игрока
        // сбросила streak, либо сменились сутки) — пишем ЭТИ поля, notified_at не трогаем.
        return [
            'low_health_warn_streak' => $decision['warn_streak'],
            'low_health_last_band'   => $decision['last_band'],
            'low_health_warns_today' => $decision['warns_today'],
            'low_health_warns_day'   => $decision['warns_day'],
        ];
    }
}

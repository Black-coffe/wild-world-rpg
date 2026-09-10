<?php

namespace App\TaskHandlers;

use App\Attributes\HandlerKey;
use App\Models\CharacterModel;
use App\Models\PlayerActionLogModel;
use App\Models\TelegramUserModel;
use App\Services\Player\Death\DeathMessageBuilder;
use App\Services\Player\HealthWarningSchedule;
use App\Services\Telegram\ButtonPacker;

/**
 * v0.51.20 (F2.9 batch-2): extends BaseTaskHandler (per F2.9 contract).
 * Раніше extends Controller — handler НЕ контроллер.
 * Telegram lazy-init через BaseTaskHandler::telegram(), Request::sendPhoto → safeSendPhoto.
 * `process()` → `handle(array $task = []): void` (TaskHandlerInterface signature).
 *
 * Задача «видимость угроз» (карточка inbox/2026-05-11-validation-card-death-notifications.md,
 * batch 3 — жалоба Arseny, регрессия F7.4): если на персонажа сейчас действует активное
 * `damage`-событие — называем его в предупреждении. + текст не врёт «смерть не угрожает»,
 * когда здоровье < 1.0.
 *
 * health-warning-backoff, story-02 (владелец: «если человек на одно, два, три сообщения
 * не реагирует — забить нужно, не напрягать спамом») — фиксированный кулдаун (33 / 5 мин)
 * заменён затуханием: решение «слать / не слать» и новое состояние считает чистый класс
 * {@see HealthWarningSchedule} (без БД и без Telegram), этот handler только собирает вход
 * (в т.ч. реакцию игрока из `player_action_log`, ОДНИМ запросом на весь пакет персонажей —
 * не по одному в цикле) и применяет результат к колонкам `characters.low_health_*`.
 *
 * story-03 — тумблер «⚙️ Настройки» → `characters.health_warnings_enabled` (opt-out, default 1)
 * отсекается прямо в SQL-выборке ниже: выключивший игрок не получает НИКАКИХ предупреждений,
 * включая критические.
 */
#[HandlerKey(
    key: 'low_health_warning',
    displayName: 'Предупреждение о низком HP',
    description: 'Recurring (Tasks.php every minute): шлёт пред-смертные уведомления при HP<threshold, называет активный damage-event; интервал затухает без реакции игрока (HealthWarningSchedule).',
)]
class LowHealthWarningHandler extends BaseTaskHandler
{
    /**
     * `player_action_log.source`, инициированные самим игроком (ADR-148) — только они
     * считаются «реакцией» для {@see HealthWarningSchedule}. `task` (фоновая задача
     * завершилась сама, Worker.php) и `other` реакцией НЕ являются. ENUM целиком —
     * `('callback','command','text','forcereply','other','task')`, миграция
     * 2026-09-28-130000_Adr148PlayerActionLogAddTaskSource.php.
     *
     * @var list<string>
     */
    private const PLAYER_INITIATED_SOURCES = ['callback', 'command', 'text', 'forcereply'];

    /**
     * Tasks.php scheduler callback.
     *
     * @param array<string,mixed> $task TaskHandlerInterface signature (recurring tasks
     *                                  не приймають task data).
     */
    public function handle(array $task = []): void
    {
        $characterModel    = new CharacterModel();
        $telegramUserModel = new TelegramUserModel();

        // Находим всех, у кого здоровье <= 5.00 и кто не выключил предупреждения (story-03,
        // тумблер в Настройках) — отсекаем в самой выборке, не циклом после неё: хендлер
        // крутится раз в минуту.
        $lowHealthCharacters = $characterModel
            ->where('health <=', 5.00)
            ->where('health_warnings_enabled', 1)
            ->findAll();

        if (empty($lowHealthCharacters)) {
            return;
        }

        $now         = time();  // Текущее время UNIX
        $eventLookup = new DeathMessageBuilder();
        $schedule    = new HealthWarningSchedule();

        // Id — из уже загруженных $lowHealthCharacters, без второго похода в БД за тем же
        // фильтром (хендлер крутится раз в минуту по всем игрокам с низким здоровьем).
        // rowToArr() (см. GenericBuildingInfoAction::rowToArr) настоящим образом сужает
        // тип строки модели (Entity ИЛИ array) до array<string,mixed> — тот же класс, на
        // котором в проекте уже ломались (Entity + строгий тайпхинт → TypeError в рантайме).
        $charIds = [];
        foreach ($lowHealthCharacters as $character) {
            $charArr = $this->rowToArr($character);
            $rawId   = $charArr['id'] ?? null;
            if (is_numeric($rawId)) {
                $charIds[] = (int) $rawId;
            }
        }

        // Реакция игрока = запись в firehose (ADR-148) позже last_notified_at — ОДНИМ
        // запросом на весь пакет, не по одному персонажу в цикле. КРИТИЧНО: `source`
        // фильтруется прямо здесь, в самом SQL — `player_action_log` пишет НЕ только
        // игрока, `PlayerActionLogger::recordTaskCompletion()` (Worker.php) кладёт туда
        // строку с source='task' на КАЖДОЕ завершение фоновой задачи (добыча/крафт/
        // стройка/поход). Если её не отфильтровать, «реакцией» окажется собственная
        // ранее начатая задача, завершившаяся во сне игрока, — затухание не включится
        // никогда. Значения enum — по миграции 2026-09-28-130000 (не по памяти).
        $lastActionByChar = [];
        if ($charIds !== []) {
            $actionRows = (new PlayerActionLogModel())
                ->select('character_id, MAX(created_at) AS last_action_at')
                ->whereIn('character_id', $charIds)
                ->whereIn('source', self::PLAYER_INITIATED_SOURCES)
                ->groupBy('character_id')
                ->findAll();
            foreach ($actionRows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rowCharIdRaw    = $row['character_id'] ?? null;
                $rowLastActionAt = $row['last_action_at'] ?? null;
                $rowCharId       = is_numeric($rowCharIdRaw) ? (int) $rowCharIdRaw : 0;
                if ($rowCharId <= 0 || ! is_string($rowLastActionAt)) {
                    continue;
                }
                $ts = strtotime($rowLastActionAt);
                if ($ts !== false) {
                    $lastActionByChar[$rowCharId] = $ts;
                }
            }
        }

        foreach ($lowHealthCharacters as $character) {
            $charArr = $this->rowToArr($character);
            if ($charArr === null) {
                continue;
            }

            $healthRaw = $charArr['health'] ?? null;
            $health    = is_numeric($healthRaw) ? (float) $healthRaw : 0.0;
            $charId    = is_numeric($charArr['id'] ?? null) ? (int) $charArr['id'] : 0;

            // Активное damage-событие, которое сейчас бьёт персонажа (для текста + интервала)
            $dmgEvent          = $charId > 0 ? $eventLookup->activeDamageEvent($charId) : null;
            $activeDamageEvent = $dmgEvent !== null && $dmgEvent['is_active'];

            $lastNotifiedRaw = $charArr['low_health_notified_at'] ?? null; // может быть NULL / Time
            $lastNotifiedAt  = $lastNotifiedRaw ? strtotime($lastNotifiedRaw) : false;

            $warnStreakRaw  = $charArr['low_health_warn_streak'] ?? null;
            $lastBandRaw    = $charArr['low_health_last_band'] ?? null;
            $warnsTodayRaw  = $charArr['low_health_warns_today'] ?? null;
            $warnsDayRaw    = $charArr['low_health_warns_day'] ?? null;

            $warnStreak  = is_numeric($warnStreakRaw) ? (int) $warnStreakRaw : 0;
            $lastBand    = is_numeric($lastBandRaw) ? (float) $lastBandRaw : null;
            $warnsToday  = is_numeric($warnsTodayRaw) ? (int) $warnsTodayRaw : 0;
            $warnsDay    = is_string($warnsDayRaw) ? $warnsDayRaw : null;

            $decision = $schedule->decide(
                health: $health,
                lastNotifiedAt: $lastNotifiedAt !== false ? $lastNotifiedAt : null,
                warnStreak: $warnStreak,
                lastBand: $lastBand,
                warnsToday: $warnsToday,
                warnsDay: $warnsDay,
                lastActionAt: $lastActionByChar[$charId] ?? null,
                activeDamageEvent: $activeDamageEvent,
                now: $now,
            );

            if (! $decision['should_send']) {
                // Реакция игрока или смена суток всё равно меняют состояние — сохраняем,
                // даже если предупреждение сейчас не уходит. Какие именно поля — решает
                // тот же чистый {@see HealthWarningSchedule::fieldsToPersist()}, что и
                // ниже (тестируется напрямую, без похода в БД).
                $fields = HealthWarningSchedule::fieldsToPersist($decision, $warnStreak, $lastBand, $warnsToday, $warnsDay);
                if ($fields !== []) {
                    $characterModel->update($charId, $fields);
                }

                continue;
            }

            // Находим телеграм-пользователя
            $telegramUserId = $charArr['telegram_user_id'] ?? null;
            $telegramUser   = is_numeric($telegramUserId)
                ? $this->rowToArr($telegramUserModel->find((int) $telegramUserId))
                : null;
            if ($telegramUser === null) {
                log_message('error', 'Не найден TelegramUser для character_id=' . $charId);
                continue;
            }

            // Формируем текст
            $playerNameRaw = $charArr['name'] ?? null;
            $playerName    = is_string($playerNameRaw) ? $playerNameRaw : '';
            $currentHealth = number_format($health, 2);

            $text = "Мой дорогой выживальщик *{$playerName}*, это я твой друг Роби🤖\n"
                . "Спешу сообщить тебе, что твой уровень здоровья на текущую минуту составляет ⚠️ *{$currentHealth}* ⚠️\n";

            if ($health < 1.0) {
                $text .= "_Здоровье критическое — на каждой минуте есть шанс погибнуть, и чем ниже цифра, тем он выше. Действуй прямо сейчас!_\n\n";
            } else {
                $text .= "_Я пишу тебе, пока показатель ещё не критический и смерть тебе не угрожает._\n\n"
                    . "НО! Контролируй показатели, так как если здоровье падёт ниже *1.00*, ты ступаешь на скользкий путь.\n"
                    . "Здоровье *0.99 - 0.01* подвержено вероятности смерти персонажа, и чем ниже цифра, тем выше шанс умереть уже на следующей минуте!\n\n";
            }

            if ($dmgEvent !== null && $dmgEvent['name'] !== '') {
                $protectionItem = $dmgEvent['protection_item'];
                if ($dmgEvent['is_active']) {
                    // Событие идёт прямо сейчас — есть что предпринять, чтобы перестало бить.
                    $text .= "❗️ Сейчас на тебя действует событие *{$dmgEvent['name']}* — оно и просаживает здоровье. "
                        . "Уйди на базу или ";
                    $text .= $protectionItem !== null && $protectionItem !== ''
                        ? "держи в инвентаре защитный предмет (`{$protectionItem}`).\n\n"
                        : "используй защитный предмет события.\n\n";
                } else {
                    // Событие уже закончилось, но здоровье просело из-за него и само быстро не вернётся.
                    $text .= "❗️ Недавно тебя задело событие *{$dmgEvent['name']}* — оно уже закончилось, "
                        . "но здоровье из-за него осталось низким и само так быстро не восстановится. "
                        . "Подлечись (аптечка/еда) и не уходи в минус.\n"
                        . "_Историю событий с началом и концом смотри в меню «🎉 События»._\n\n";
                }
            }

            $text .= "Предприми действия или воспользуйся аптечкой/провизией. Удачи в выживании!";

            // Inline-кнопки — обе полки лечения (аптечка + провизия) рядом с рецептом
            // лекарств; ButtonPacker гарантирует отсутствие строк-одиночек.
            $healButtons = [
                ['text' => '💊 Аптечка', 'callback_data' => 'pharmacy'],
                ['text' => '🍲 Провизия', 'callback_data' => 'provision'],
                ['text' => '💊 Лекарства', 'callback_data' => 'medicinesCraft1'],
            ];
            $keyboard = [
                'inline_keyboard' => ButtonPacker::pack($healButtons),
            ];

            $telegramChatId = $telegramUser['telegram_id'] ?? null;
            if (! is_int($telegramChatId) && ! is_string($telegramChatId)) {
                log_message('error', 'TelegramUser.telegram_id некорректен для character_id=' . $charId);
                continue;
            }

            // Отправляем фото + текст (lazy Telegram через BaseTaskHandler)
            $localFilePath = FCPATH . 'uploads/telegram/character/low_health_warning.png';

            $this->safeSendPhoto(
                $telegramChatId,
                $localFilePath,
                $text,
                [
                    'parse_mode'   => 'Markdown',
                    'reply_markup' => json_encode($keyboard),
                ]
            );

            // Обновляем время последнего уведомления + состояние затухания
            $characterModel->update(
                $charId,
                HealthWarningSchedule::fieldsToPersist($decision, $warnStreak, $lastBand, $warnsToday, $warnsDay),
            );
        }
    }

    /**
     * F1.4: `CharacterModel::findAll()` типизирован через `$returnType = CharacterEntity::class`,
     * но PHPStan видит generic `array<...>|object` union и не различает случаи — тот же класс
     * ошибки, на котором в проекте уже ломались (Entity + строгий тайпхинт → TypeError в
     * рантайме, тесты зелёные). Настоящее сужение вместо `??`-обхода: паттерн повторяет
     * {@see \App\Controllers\Telegram\Commands\Actions\Camp\GenericBuildingInfoAction::rowToArr()}.
     *
     * @return array<string, mixed>|null
     */
    private function rowToArr(mixed $row): ?array
    {
        $arr = null;
        if (is_array($row)) {
            $arr = $row;
        } elseif (is_object($row) && method_exists($row, 'toArray')) {
            $tmp = $row->toArray();
            if (is_array($tmp)) {
                $arr = $tmp;
            }
        }
        if ($arr === null) {
            return null;
        }
        $out = [];
        foreach ($arr as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }
}

<?php

namespace App\TaskHandlers;

use App\Attributes\HandlerKey;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\Player\Death\DeathMessageBuilder;

/**
 * v0.51.20 (F2.9 batch-2): extends BaseTaskHandler (per F2.9 contract).
 * Раніше extends Controller — handler НЕ контроллер.
 * Telegram lazy-init через BaseTaskHandler::telegram(), Request::sendPhoto → safeSendPhoto.
 * `process()` → `handle(array $task = []): void` (TaskHandlerInterface signature).
 *
 * Задача «видимость угроз» (карточка inbox/2026-05-11-validation-card-death-notifications.md,
 * batch 3 — жалоба Arseny, регрессия F7.4): если на персонажа сейчас действует активное
 * `damage`-событие — называем его в предупреждении и ужимаем кулдаун до 5 мин (вместо 33),
 * чтобы игрок видел угрозу, а не молчаливо помирал. + текст теперь не врёт «смерть не
 * угрожает», когда здоровье < 1.0.
 */
#[HandlerKey(
    key: 'low_health_warning',
    displayName: 'Предупреждение о низком HP',
    description: 'Recurring (Tasks.php every minute): шлёт пред-смертные уведомления при HP<threshold, называет активный damage-event, кулдаун 5мин.',
)]
class LowHealthWarningHandler extends BaseTaskHandler
{
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

        // Находим всех, у кого здоровье <= 5.00
        $lowHealthCharacters = $characterModel
            ->where('health <=', 5.00)
            ->findAll();

        if (empty($lowHealthCharacters)) {
            return;
        }

        $now         = time();  // Текущее время UNIX
        $eventLookup = new DeathMessageBuilder();

        foreach ($lowHealthCharacters as $character) {
            $health = (float) $character['health'];
            $charId = is_numeric($character['id']) ? (int) $character['id'] : 0;

            // Активное damage-событие, которое сейчас бьёт персонажа (для текста + кулдауна)
            $dmgEvent = $charId > 0 ? $eventLookup->activeDamageEvent($charId) : null;

            // Определяем нужный интервал между уведомлениями
            if ($health <= 0.10 && $health > 0.0) {
                // Если здоровье в диапазоне (0.01..0.10)
                $cooldownMinutes = 5;
            } else {
                // Для всего остального <= 5.00
                $cooldownMinutes = 33;
            }
            // Если на персонажа СЕЙЧАС действует damage-событие — не молчим по 33 мин:
            // предупреждаем чаще. Для уже завершившегося события (урон в прошлом, HP
            // ещё низкое) учащать не нужно — оно уже не бьёт.
            if ($dmgEvent !== null && $dmgEvent['is_active']) {
                $cooldownMinutes = min($cooldownMinutes, 5);
            }

            // Проверка, когда было последнее уведомление
            $lastNotifiedAt = $character['low_health_notified_at']; // может быть NULL
            if ($lastNotifiedAt) {
                // Преобразуем в таймстамп
                $lastNotifyTs = strtotime($lastNotifiedAt);

                // Сколько минут прошло с момента последнего уведомления
                $diffMinutes = floor(($now - $lastNotifyTs) / 60);

                // Если прошло МЕНЬШЕ, чем нужно, пропускаем
                if ($diffMinutes < $cooldownMinutes) {
                    // Пропускаем уведомление, т.к. кулдаун не истёк
                    continue;
                }
            }

            // Если дошли сюда, значит либо lastNotifiedAt = NULL,
            // либо кулдаун (5 / 33 мин) уже прошёл. Отправляем сообщение.

            // Находим телеграм-пользователя
            $telegramUser = $telegramUserModel->find($character['telegram_user_id']);
            if (!$telegramUser) {
                log_message('error', 'Не найден TelegramUser для character_id=' . $character['id']);
                continue;
            }

            // Формируем текст
            $playerName    = $character['name'];
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

            $text .= "Предприми действия или воспользуйся аптечкой. Удачи в выживании!";

            // Inline-кнопки
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '💊 Аптечка', 'callback_data' => 'pharmacy'],
                        ['text' => '💊 Лекарства', 'callback_data' => 'medicinesCraft1'],
                    ],
                ],
            ];

            // Отправляем фото + текст (lazy Telegram через BaseTaskHandler)
            $localFilePath = FCPATH . 'uploads/telegram/character/low_health_warning.png';

            $this->safeSendPhoto(
                $telegramUser['telegram_id'],
                $localFilePath,
                $text,
                [
                    'parse_mode'   => 'Markdown',
                    'reply_markup' => json_encode($keyboard),
                ]
            );

            // Обновляем время последнего уведомления
            $characterModel->update($character['id'], [
                'low_health_notified_at' => date('Y-m-d H:i:s', $now)
            ]);
        }
    }
}

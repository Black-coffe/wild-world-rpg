<?php

namespace App\TaskHandlers;

use App\Models\CharacterModel;
use App\Models\TelegramUserModel;

/**
 * v0.51.20 (F2.9 batch-2): extends BaseTaskHandler (per F2.9 contract).
 * Раніше extends Controller — handler НЕ контроллер.
 * Telegram lazy-init через BaseTaskHandler::telegram(), Request::sendPhoto → safeSendPhoto.
 * `process()` → `handle(array $task = []): void` (TaskHandlerInterface signature).
 */
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

        $now = time();  // Текущее время UNIX

        foreach ($lowHealthCharacters as $character) {
            $health = (float) $character['health'];

            // Определяем нужный интервал между уведомлениями
            if ($health <= 0.10 && $health > 0.0) {
                // Если здоровье в диапазоне (0.01..0.10)
                $cooldownMinutes = 5;
            } else {
                // Для всего остального <= 5.00
                $cooldownMinutes = 33;
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
                . "Спешу сообщить тебе, что твой уровень здоровья на текущую минуту составляет ⚠️ *{$currentHealth}* ⚠️\n"
                . "_Я пишу тебе, пока показатель ещё не критический и смерть тебе не угрожает._\n\n"
                . "НО! Контролируй показатели, так как если здоровье падёт ниже *1.00*, ты ступаешь на скользкий путь.\n"
                . "Здоровье *0.99 - 0.01* подвержено вероятности смерти персонажа, и чем ниже цифра, тем выше шанс умереть уже на следующей минуте!\n\n"
                . "Предприми действия или воспользуйся аптечкой. Удачи в выживании!";

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

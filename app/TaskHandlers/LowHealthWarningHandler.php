<?php

namespace App\TaskHandlers;

use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use CodeIgniter\Controller;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Exception\TelegramException;

class LowHealthWarningHandler extends Controller
{
    private $telegram;

    public function __construct()
    {
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');

        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            // Инициализируем класс Request, чтобы потом делать sendMessage / sendPhoto
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    /**
     * Метод вызывается из Worker->processUnlinkedActions() (или аналогичного).
     */
    public function process()
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

            // Отправляем фото + текст
            // Вариант 1: локальный файл (fopen), используем FCPATH.
            $localFilePath = FCPATH . 'uploads/telegram/character/low_health_warning.png';

            try {
                Request::sendPhoto([
                    'chat_id'    => $telegramUser['telegram_id'],
                    'photo'      => Request::encodeFile($localFilePath),
                    'caption'    => $text,
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode($keyboard),
                ]);
            } catch (TelegramException $e) {
                log_message('error', 'Ошибка при отправке фото (LowHealthWarningHandler): ' . $e->getMessage());
            }

            // Обновляем время последнего уведомления
            $characterModel->update($character['id'], [
                'low_health_notified_at' => date('Y-m-d H:i:s', $now)
            ]);
        }
    }
}

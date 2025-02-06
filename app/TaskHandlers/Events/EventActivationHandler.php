<?php

namespace App\TaskHandlers\Events;

use App\Models\ActiveEventModel;
use App\Models\BiomeModel;
use App\Models\EventModel;
use App\Models\TelegramUserModel;
use CodeIgniter\I18n\Time;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Class EventActivationHandler
 *
 * Отвечает за периодическую активацию новых событий в игре (например, раз в неделю).
 * 1) Проверяет, не закончились ли текущие активные события (и при необходимости завершает их).
 * 2) Определяет, можно ли запустить новое событие (если активных уже не выше лимита).
 * 3) Выбирает из списка событий (eventModel) те, что ещё не активировались на этой неделе
 *    и не превысили frequency_per_week.
 * 4) Случайным образом выбирает 1 событие и "активирует" его:
 *    - рассчитывает продолжительность с учётом ±35% вариации,
 *    - записывает в active_events со статусом 'active',
 *    - уведомляет всех пользователей в Telegram, прикрепляя картинку и описание.
 */
class EventActivationHandler
{
    /** @var ActiveEventModel */
    protected $activeEventModel;

    /** @var EventModel */
    protected $eventModel;

    /** @var BiomeModel */
    protected $biomeModel;

    /** @var TelegramUserModel */
    protected $telegramUserModel;

    /** @var Telegram */
    private $telegram;

    /** @var Time Начало недели (воскресенье 0:00) */
    protected $startOfWeek;

    /** @var Time Конец недели (суббота 23:59) */
    protected $endOfWeek;

    /** @var Time Текущее время */
    protected $now;

    public function __construct()
    {
        // Инициализация моделей
        $this->activeEventModel  = new ActiveEventModel();
        $this->eventModel        = new EventModel();
        $this->biomeModel        = new BiomeModel();
        $this->telegramUserModel = new TelegramUserModel();

        // Инициализация Telegram
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }

        // Текущее время (Time::now() — CodeIgniter\I18n)
        $this->now = Time::now('Europe/Kiev');

        // Инициализация границ недели (startOfWeek / endOfWeek)
        $this->initializeTimeBoundaries();
    }

    /**
     * Основной метод, вызываемый планировщиком (cron) или внутренним игровым циклом.
     * 1) Снимаем статус 'active' у событий, у которых end_time < $this->now => 'completed'.
     * 2) Проверяем, не превышено ли кол-во активных событий.
     * 3) Выбираем из таблицы events те, которые ещё не активировались на этой неделе и не превышают frequency_per_week.
     * 4) Проверяем интервал с момента последнего активированного события (540..1240 минут).
     * 5) При выполнении условий — случайно активируем одно событие, уведомляем всех игроков.
     */
    public function process()
    {
        // 1) Обновление статусов истекших событий
        $this->updateExpiredEventsStatus();

        // 2) Если уже есть >=1 активное событие на этой неделе — новое не запускаем
        if ($this->countActiveEvents() >= 1) {
            return;
        }

        // 3) Получаем события, которых ещё *не было* на этой неделе
        $availableEvents = $this->eventModel->whereNotIn('event_id', function($builder) {
            return $builder->select('event_id')
                ->from('active_events')
                ->where('start_time >=', $this->startOfWeek)
                ->where('start_time <=', $this->endOfWeek);
        })->findAll();

        // 4) Смотрим, не слишком ли мало времени прошло после последней активации
        //    (Берём случайный порог от 540 до 1240 мин; если не вышло — не активируем)
        if (!$this->isRecentActivationTooClose()) {
            return;
        }

        // 5) Фильтрация по frequency_per_week
        //    (например, если event указывает frequency_per_week=2, значит можно активировать
        //     не более 2 раз в рамках текущей недели)
        $filteredEvents = array_filter($availableEvents, function($event) {
            $activationCountThisWeek = $this->getActivationCountThisWeek(
                $event['event_id'],
                $this->startOfWeek,
                $this->endOfWeek
            );
            return $activationCountThisWeek < $event['frequency_per_week'];
        });

        // 6) Если список не пуст, берём случайное событие
        if (!empty($filteredEvents)) {
            $randomEvent = $filteredEvents[array_rand($filteredEvents)];

            // Активируем
            $this->activateEvent($randomEvent);

            // Уведомляем всех игроков (транслируем картинку + описание)
            $this->notifyPlayersAboutEvent($randomEvent);
        }
    }

    /**
     * Подсчитывает, сколько событий в статусе 'active'
     * было запущено в пределах этой недели.
     */
    protected function countActiveEvents(): int
    {
        return $this->activeEventModel
            ->where('status', 'active')
            ->where('start_time >=', $this->startOfWeek)
            ->where('start_time <=', $this->endOfWeek)
            ->countAllResults();
    }

    /**
     * Устанавливаем границы недели:
     * - startOfWeek (воскресенье 00:00)
     * - endOfWeek   (суббота 23:59)
     */
    protected function initializeTimeBoundaries()
    {
        // Пример определения дня недели (0 = воскресенье, 6 = суббота).
        // При желании можно адаптировать под старт с понедельника.
        $dayOfWeek = $this->now->getDayOfWeek();

        // Сколько дней назад было воскресенье?
        $daysUntilStartOfWeek = $dayOfWeek % 7;
        // Сколько осталось до субботы?
        $daysFromEndOfWeek = 6 - $dayOfWeek;

        $this->startOfWeek = $this->now
            ->subDays($daysUntilStartOfWeek)
            ->setTime(0, 0, 0);

        $this->endOfWeek = $this->now
            ->addDays($daysFromEndOfWeek)
            ->setTime(23, 59, 59);
    }

    /**
     * Сколько раз событие $eventId запускалось (start_time) в текущей неделе.
     */
    protected function getActivationCountThisWeek($eventId, $startOfWeek, $endOfWeek): int
    {
        return $this->activeEventModel
            ->where('event_id', $eventId)
            ->where('start_time >=', $startOfWeek)
            ->where('start_time <=', $endOfWeek)
            ->countAllResults();
    }

    /**
     * Переводит события из 'active' в 'completed', если end_time < $this->now.
     * Показывает, что событие завершилось.
     */
    protected function updateExpiredEventsStatus()
    {
        $expiredEvents = $this->activeEventModel
            ->where('status', 'active')
            ->where('end_time <', $this->now)
            ->findAll();

        foreach ($expiredEvents as $event) {
            // Ставим 'completed'
            $this->activeEventModel->update($event['id'], ['status' => 'completed']);
        }
    }

    /**
     * Активируем событие: рассчитываем случайную продолжительность (±35%),
     * записываем новую строку в active_events со статусом 'active'.
     *
     * @param array $event Информация о событии из таблицы events
     */
    protected function activateEvent(array $event)
    {
        // originalDuration — это minutes
        $originalDuration = (int) $event['duration'];

        // Считаем 35% от оригинальной длительности
        $variation = round($originalDuration * 0.35);

        // Случайный выбор между -variation и +variation
        $randomVariation = rand(-$variation, $variation);

        // Итоговая длительность (не меньше 1 минуты)
        $finalDuration = max(1, $originalDuration + $randomVariation);

        // Запись в active_events
        $data = [
            'event_id'    => $event['event_id'],
            'start_time'  => $this->now->toDateTimeString(),
            // end_time = now + finalDuration (минут)
            'end_time'    => $this->now->addMinutes($finalDuration)->toDateTimeString(),
            'status'      => 'active',
            'effect_applied' => false,
        ];

        $this->activeEventModel->insert($data);
    }

    /**
     * Проверка: не слишком ли мало времени прошло с момента последнего события.
     * Смотрим на *последнее активированное событие* (orderBy('start_time','DESC')).
     * Если с того момента прошло меньше рандомного кол-ва (540..1240) минут — возвращаем false,
     * иначе true.
     */
    protected function isRecentActivationTooClose(): bool
    {
        $lastActiveEvent = $this->activeEventModel
            ->select('start_time')
            ->orderBy('start_time', 'DESC')
            ->first();

        if ($lastActiveEvent) {
            $lastEventTimestamp = strtotime($lastActiveEvent['start_time']);
            $currentTimestamp   = time(); // Текущее (Unix-время)
            $minutesPassed      = ($currentTimestamp - $lastEventTimestamp) / 60;

            // Генерируем случайный интервал (в минутах) от 540 (9 часов) до 1240 (~20.6 часов)
            $randomMinutes = rand(540, 1240);

            // Если прошло *больше*, чем $randomMinutes, => можно новое событие
            // Если прошло *меньше*, => слишком рано (false)
            return ($minutesPassed > $randomMinutes);
        }

        // Если событий не было, значит можно активировать
        return true;
    }

    /**
     * Уведомляем всех пользователей, что стартовало новое событие,
     * прикрепляя картинку (img_path) и описание.
     *
     * @param array $event Запись из таблицы events
     */
    protected function notifyPlayersAboutEvent(array $event)
    {
        // Собираем текст сообщения
        $message = '';

        // $event['biome_ids'] — JSON-список ID биомов
        $allBiomes = json_decode($event['biome_ids'], true) ?: [];
        $biomsStr  = '';
        $imgPath   = $event['img_path'];

        foreach ($allBiomes as $biom) {
            $biomeName = $this->biomeModel->where('id', $biom)->first()['name'] ?? '???';
            $biomsStr .= "🔹 $biomeName\n";
        }

        // Форматируем длительность (в минутах)
        $durationFormatted = $this->formatDuration($event['duration']);

        // Перевод effect_type (damage/heal/buff/debuff/none)
        $effectTypeTranslated = $this->translateEffectType($event['effect_type']);

        // Перевод event_type (local/global)
        $territoryTranslated = $this->translateTerritory($event['event_type']);

        // Формируем само сообщение
        $message .= "⚠️ *Внимание, началось событие: ➡️ {$event['name']}.*\n\n";
        $message .= "ℹ️ *Описание:*\n";
        $message .= "_{$event['description']}_\n\n";
        $message .= "⌛️ Продлится: $durationFormatted\n\n";
        $message .= "🔔 Эффект влияния: $effectTypeTranslated\n\n";
        $message .= "🗾 Территория: $territoryTranslated\n\n";
        $message .= $biomsStr;
        $message .= "\nБудьте осторожны, или наоборот — воспользуйтесь преимуществами этого события!";

        // Отправляем всем пользователям
        $allTelegramUsers = $this->telegramUserModel->findAll();
        $counter = 0;

        foreach ($allTelegramUsers as $telegramUser) {
            // Каждые 50 сообщений делаем паузу 5 секунд,
            // чтобы не попасть под лимиты Telegram
            if ($counter > 0 && $counter % 50 == 0) {
                sleep(5);
            }
            $this->sendMessageToAllUsers($message, $imgPath, $telegramUser['telegram_id']);
            $counter++;
        }
    }

    /**
     * Форматирует minutes в строку "X дн. Y чс. Z мин."
     */
    protected function formatDuration(int $minutes): string
    {
        $days  = floor($minutes / 1440);
        $hours = floor(($minutes - $days * 1440) / 60);
        $mins  = $minutes % 60;
        return "{$days} дн. {$hours} чс. {$mins} мин.";
    }

    /**
     * Переводим effect_type (damage/heal/buff/debuff/none) на русский
     */
    protected function translateEffectType(string $type): string
    {
        $translations = [
            'damage' => 'Урон',
            'heal'   => 'Лечение',
            'buff'   => 'Усиление',
            'debuff' => 'Ослабление',
            'none'   => 'Без эффекта'
        ];
        return $translations[$type] ?? $type;
    }

    /**
     * Переводим event_type (local/global)
     */
    protected function translateTerritory(string $territory): string
    {
        $translations = [
            'local'  => 'Выборочно в указанных биомах',
            'global' => 'Повсеместно в указанных биомах'
        ];
        return $translations[$territory] ?? $territory;
    }

    /**
     * Отправляем сообщение + фото пользователю (chatId).
     * В caption передаём подробности.
     */
    protected function sendMessageToAllUsers(string $message, string $imgPath, int $chatId)
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',       'callback_data' => 'events']
                ]
            ]
        ];

        // Для фото:
        try {
            $imagePath = base_url($imgPath);
            // Предполагаем, что $imgPath содержит относительный путь к файлу
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            // Если не удалось отправить фото, можно продублировать как обычное текстовое
            log_message('error', "Ошибка при отправке фото: " . $e->getMessage());
        }
    }
}

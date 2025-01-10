<?php

namespace App\TaskHandlers;

use App\Models\ActiveEventModel;
use App\Models\EventModel;
use App\Models\BiomeModel;
use CodeIgniter\I18n\Time;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Exception\TelegramException;
use App\Models\TelegramUserModel;

class EventActivationHandler
{
    protected $activeEventModel;
    protected $eventModel;
    protected $biomeModel;
    protected $telegramUserModel;
    private $telegram;
    protected $startOfWeek;
    protected $endOfWeek;
    protected $now;

    public function __construct()
    {
        $this->activeEventModel = new ActiveEventModel();
        $this->eventModel = new EventModel();
        $this->biomeModel = new BiomeModel();
        $this->telegramUserModel = new TelegramUserModel();

        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }

        $this->now = Time::now('Europe/Kiev');
        // День недели: 0 (воскресенье) - 6 (суббота)
        $dayOfWeek = $this->now->getDayOfWeek();
        // CodeIgniter начинает неделю с воскресенья, нам нужно адаптировать логику, если ваша неделя начинается с понедельника
        $this->startOfWeek = $this->now->subDays($dayOfWeek)->setTime(0, 0, 0);
        $this->endOfWeek = $this->now->addDays(7 - $dayOfWeek - 1)->setTime(23, 59, 59);
    }

    public function process() {
        // Обновление статусов истекших событий
        $this->updateExpiredEventsStatus();

        // Получаем доступные для активации события, исключая уже активированные на этой неделе
        $availableEvents = $this->eventModel->whereNotIn('event_id', function($builder) {
            return $builder->select('event_id')
                ->from('active_events')
                ->where('start_time >=', $this->startOfWeek)
                ->where('start_time <=', $this->endOfWeek);
        })->findAll();

        // Проверяем время последней активации
        if (!$this->isRecentActivationTooClose()) {
            die();
        }

        // Фильтрация событий с учетом частоты активации
        $filteredEvents = array_filter($availableEvents, function($event) {
            $activationCountThisWeek = $this->getActivationCountThisWeek($event['event_id'], $this->startOfWeek, $this->endOfWeek);
            return $activationCountThisWeek < $event['frequency_per_week'];
        });

        // Выбор и активация случайного события
        if (!empty($filteredEvents)) {
            $randomEvent = $filteredEvents[array_rand($filteredEvents)];
            $this->activateEvent($randomEvent);
            $this->notifyPlayersAboutEvent($randomEvent);
        }
    }

    protected function getActivationCountThisWeek($eventId, $startOfWeek, $endOfWeek) {
        return $this->activeEventModel
            ->where('event_id', $eventId)
            ->where('start_time >=', $startOfWeek)
            ->where('start_time <=', $endOfWeek)
            ->countAllResults();
    }

    protected function updateExpiredEventsStatus() {
        // Получаем все активные события, у которых время окончания уже прошло
        $expiredEvents = $this->activeEventModel
            ->where('status', 'active')
            ->where('end_time <', $this->now)
            ->findAll();

        foreach ($expiredEvents as $event) {
            // Обновляем статус события на 'completed'
            $this->activeEventModel->update($event['id'], ['status' => 'completed']);
        }
    }

    protected function activateEvent($event) {
        // Исходная длительность события
        $originalDuration = $event['duration'];

        // Рассчитываем 35% от исходной длительности
        $variation = round($originalDuration * 0.35);

        // Генерируем случайное отклонение в пределах от -35% до +35%
        $randomVariation = rand(-$variation, $variation);

        // Рассчитываем окончательную длительность события с учетом рандомной вариации
        $finalDuration = max(1, $originalDuration + $randomVariation); // Используем max() для гарантии, что длительность не будет меньше 1 минуты

        // Формируем данные для записи
        $data = [
            'event_id' => $event['event_id'],
            'start_time' => $this->now->toDateTimeString(),
            'end_time' => $this->now->addMinutes($finalDuration)->toDateTimeString(),
            'status' => 'active',
            'effect_applied' => false,
        ];

        // Записываем данные о событии в базу данных
        $this->activeEventModel->insert($data);
    }

    protected function isRecentActivationTooClose() {
        // Получаем запись о последнем активированном событии
        $lastActiveEvent = $this->activeEventModel
            ->select('start_time')
            ->orderBy('start_time', 'DESC')
            ->first();

        if ($lastActiveEvent) {
            // Преобразование даты последнего события и текущего момента в Unix-временные метки
            $lastEventTimestamp = strtotime($lastActiveEvent['start_time']);
            $currentTimestamp = time(); // Текущее время в Unix-временной метке

            // Вычисление разницы в минутах
            $minutesPassed = ($currentTimestamp - $lastEventTimestamp) / 60;

            // Генерируем случайное число минут от 40 до 240
            $randomMinutes = rand(40, 240);

            // Возвращает true, если с момента последнего события прошло больше случайно выбранного количества минут
            return $minutesPassed > $randomMinutes;
        }

        // Если событий не было, считаем, что проверка на временной интервал не требуется
        return false;
    }

    protected function notifyPlayersAboutEvent($event) {
        $message = '';
        $allBiomes = json_decode($event['biome_ids'], true); // Декодирование строки JSON в массив
        $biomsStr = '';
        $imgPath = $event['img_path'];

        foreach ($allBiomes as $biom) {
            $biomsStr .= "🔹 {$this->biomeModel->where('id', $biom)->first()['name']}\n";
        }

        // Форматирование продолжительности
        $durationFormatted = $this->formatDuration($event['duration']);

        // Перевод эффекта влияния
        $effectTypeTranslated = $this->translateEffectType($event['effect_type']);

        // Перевод типа территории
        $territoryTranslated = $this->translateTerritory($event['event_type']);

        $message .= "⚠️ *Внимание, началось событие: ➡️ {$event['name']}.*\n\n";
        $message .= "ℹ️ *Описание:*\n";
        $message .= "_{$event['description']}_\n\n";
        $message .= "⌛️ Продлится : $durationFormatted\n\n";
        $message .= "🔔 Эффект влияния: $effectTypeTranslated\n\n";
        $message .= "🗾 Территория: $territoryTranslated\n\n";
        $message .= $biomsStr;
        $message .= "\nПринимайте меры, если вы не готовы к таковому!";

        $allTelegramUsers = $this->telegramUserModel->findAll();
        $counter = 0; // Счетчик отправленных сообщений

        foreach ($allTelegramUsers as $telegramUser) {
            if ($counter > 0 && $counter % 50 == 0) { // После каждых 50 сообщений
                sleep(5); // Пауза на 5 секунд
            }
            $this->sendMessageToAllUsers($message, $imgPath, $telegramUser['telegram_id']);
            $counter++;
        }

    }

    protected function formatDuration($minutes) {
        $days = floor($minutes / 1440);
        $hours = floor(($minutes - $days * 1440) / 60);
        $mins = $minutes % 60;
        return "{$days} дн. {$hours} чс. {$mins} мин.";
    }

    protected function translateEffectType($type) {
        $translations = [
            'damage' => 'Урон',
            'heal' => 'Лечение',
            'buff' => 'Усиление',
            'debuff' => 'Ослабление',
            'none' => 'Без эффекта'
        ];
        return $translations[$type] ?? $type;
    }

    protected function translateTerritory($territory) {
        $translations = [
            'local' => 'Выборочно в указанных биомах',
            'global' => 'Повсеместно в указанных биомах'
        ];
        return $translations[$territory] ?? $territory;
    }

    protected function sendMessageToAllUsers($message, $imgPath, $chatId) {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        try {
            $imagePath = base_url($imgPath); // Предполагается, что $imgPath - это локальный путь к файлу
            $result = Request::sendPhoto([
                'chat_id' => $chatId,
                'photo' => Request::encodeFile($imagePath),
                'caption' => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке фото: " . $e->getMessage());
            // Можете отправить сообщение без изображения или с другим изображением
        }
    }

}

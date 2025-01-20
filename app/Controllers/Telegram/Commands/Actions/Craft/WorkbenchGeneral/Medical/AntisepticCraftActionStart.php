<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class AntisepticCraftActionStart extends BaseAction
{
    protected $taskModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterResourceModel;

    /**
     * Количество предметов крафта, извлекаемое из callback_data.
     * По умолчанию 1 (если не смогли распарсить).
     */
    private int $quantity = 1;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->taskModel           = new TaskModel();
        $this->eventModel          = new EventModel();
        $this->activeEventModel    = new ActiveEventModel();
        $this->characterResourceModel = new CharacterResourceModel();

        // Пытаемся извлечь количество из callback_data вида "craftAntisepticCraft1_10"
        $data = $callbackQuery->getData(); // Например "craftAntisepticCraft1_10"
        $parts = explode('_', $data);
        // Если есть вторая часть и это число — запоминаем
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $this->quantity = (int) $parts[1];
        }
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError("Пользователь или персонаж не найден.");
        }

        // Повторная проверка и списание ресурсов — учитываем нужное количество!
        if (!$this->checkAndDeductResources($character['id'], $this->quantity)) {
            return $this->sendError("Недостаточно ресурсов для крафта {$this->quantity} шт.");
        }

        // Запуск процесса крафта
        return $this->startCraftingProcess($character, $user['id'], $this->quantity);
    }

    /**
     * Проверяем и списываем ресурсы с учётом выбранного количества.
     */
    private function checkAndDeductResources(int $characterId, int $qty): bool
    {
        // Базовые требования (на 1 шт.)
        $requiredResources = [
            'Кактус' => 3,
            'Грибы'  => 1,
            'Вода'   => 10,
        ];

        // Умножаем каждое требование на $qty
        foreach ($requiredResources as $resourceName => $requiredAmountPerOne) {
            $totalRequired = $requiredAmountPerOne * $qty;

            // Смотрим, есть ли у игрока достаточно ресурса
            $resource = $this->characterResourceModel->getResourceByNameAndCharacterId($resourceName, $characterId);
            if (!$resource || $resource['quantity'] < $totalRequired) {
                return false; // Не хватает ресурса
            } else {
                // Списываем
                $charRes = $this->characterResourceModel
                    ->where('id_characters', $characterId)
                    ->where('id_resources', $resource['id'])
                    ->first();

                // Обновляем количество
                $newQty = $charRes['quantity'] - $totalRequired;
                $this->characterResourceModel->update($charRes['id'], ['quantity' => $newQty]);
            }
        }

        return true;
    }

    /**
     * Запускает задачу крафта (запись в character_tasks) с учётом выбранного количества.
     */
    private function startCraftingProcess(array $character, int $userId, int $qty): ServerResponse
    {
        // Ищем задачу craftAntiseptic (из таблицы tasks)
        $craftTask = $this->taskModel->where('name', 'craftAntiseptic')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт антисептика" не найдена в базе.');
        }

        // Проверяем, нет ли уже активной задачи крафта
        $activeTask = $this->characterTaskModel->where([
            'character_id'      => $character['id'],
            'task_id'           => $craftTask['id'],
            'status'            => 'in_work',
        ])->first();

        if ($activeTask) {
            return $this->sendError(
                "У тебя уже идёт крафт этого предмета! Дождись окончания или прерви," .
                " но имей в виду, что при прерывании ресурсы не вернутся."
            );
        }

        // Считаем базовую длительность (через формулу атрибутов),
        // затем умножаем на $qty, чтобы суммарное время росло с количеством.
        $durationForOne = $this->calculateCraftingDuration($character, $craftTask);
        $totalDuration  = $durationForOne * $qty;

        // Формируем времена начала/окончания
        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $totalDuration . 'M'));

        // В поле "task_settings" запишем, сколько штук крафтим, чтобы потом в завершении выдать правильно
        $taskSettings = [
            'quantity' => $qty,
        ];

        // Создаём запись в character_tasks
        $this->characterTaskModel->save([
            'character_id'      => $character['id'],
            'telegram_user_id'  => $userId,
            'task_id'           => $craftTask['id'],
            'start_time'        => $startTime->format('Y-m-d H:i:s'),
            'end_time'          => $endTime->format('Y-m-d H:i:s'),
            'status'            => 'in_work',
            'task_settings'     => json_encode($taskSettings),
        ]);

        // Отправляем сообщение об успешном запуске
        return $this->notifyCraftStarted($character, $startTime, $endTime, $qty);
    }

    /**
     * Расчёт базовой длительности (на 1 шт.) через формулу атрибутов,
     * описанную в $craftTask (min_duration, max_duration).
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        // Атрибуты
        $experience = $character['experience'];
        $agility    = $character['agility'];
        $intellect  = $character['intellect'];

        // Весовые коэффициенты
        $expFactor  = 0.3;
        $agiFactor  = 0.3;
        $intFactor  = 0.4;

        // Подсчёт "рейтинга" по атрибутам
        $attributeScore     = ($experience * $expFactor) + ($agility * $agiFactor) + ($intellect * $intFactor);
        $maxAttributeScore  = 1000 * ($expFactor + $agiFactor + $intFactor);
        $normalizedScore    = $attributeScore / $maxAttributeScore; // 0..1

        // Из tasks
        $minDuration = $craftTask['min_duration'];
        $maxDuration = $craftTask['max_duration'];

        // Обратная зависимость (чем выше score, тем ближе к minDuration)
        $adjustedDuration = $minDuration + ($maxDuration - $minDuration) * (1 - $normalizedScore);

        // Округляем и возвращаем, в пределах [minDuration..maxDuration]
        $final = max($minDuration, min($maxDuration, round($adjustedDuration)));
        return $final;
    }

    /**
     * Уведомляем игрока о запуске крафта.
     * Дополнительно разбиваем общее время (минуты) на дни/часы/минуты.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime, int $qty): ServerResponse
    {
        // Считаем разницу в минутах
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        // Разбиваем на дни, часы, минуты
        $timeString = $this->formatMinutes($minutes);

        $text  = "*Процесс крафта запущен*\n\n"
            . "Ты создаёшь: *🧴 Антисептик x{$qty} шт.*\n\n"
            . "Время крафта: *{$timeString}* ⏱️\n"
            . "После завершения будет добавлено *{$qty}* шт. в твой инвентарь.\n\n"
            . "❗Если ты прервёшь задачу, ВСЕ затраченные ресурсы пропадут без возврата!\n\n"
            . "_О готовности узнаешь в личном сообщении._ 🎁\n";

        $imagePath = base_url('uploads/telegram/craft/antiseptic_craft.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Преобразует общее количество минут в строку вида:
     * - "1000 минут" => "16 часов 40 минут"
     * - "1440 минут" => "1 день"
     * - "2880 минут" => "2 дня" и т.д.
     *
     * Можно доработать для правильных падежей (день/дня/дней, час/часа/часов),
     * но пока используем упрощённую схему.
     */
    private function formatMinutes(int $totalMinutes): string
    {
        if ($totalMinutes <= 0) {
            return "0 минут";
        }

        // 1 день = 1440 минут
        $days = intdiv($totalMinutes, 1440);
        $remainAfterDays = $totalMinutes % 1440;

        $hours = intdiv($remainAfterDays, 60);
        $mins  = $remainAfterDays % 60;

        $parts = [];

        if ($days > 0) {
            // Можно указать "дн." для упрощения
            $parts[] = "{$days} " . $this->pluralForm($days, ['день','дня','дней']);
        }
        if ($hours > 0) {
            $parts[] = "{$hours} " . $this->pluralForm($hours, ['час','часа','часов']);
        }
        if ($mins > 0) {
            $parts[] = "{$mins} " . $this->pluralForm($mins, ['минута','минуты','минут']);
        }

        // Если всё ушло в дни/часы и минут не осталось, может быть пустая строка
        if (empty($parts)) {
            return "0 минут";
        }

        return implode(' ', $parts);
    }

    /**
     * Небольшая вспомогательная функция для выбора правильной формы (в русском языке).
     * Например: 1 час, 2 часа, 5 часов.
     */
    private function pluralForm(int $n, array $forms): string
    {
        // $forms — массив из трех вариантов: ['час', 'часа', 'часов'].
        // Например:
        //   1 -> forms[0]
        //   2 -> forms[1]
        //   5 -> forms[2]
        // используем стандартную русскую логику:
        $nMod10  = $n % 10;
        $nMod100 = $n % 100;

        if ($nMod100 >= 11 && $nMod100 <= 14) {
            return $forms[2];
        }
        switch ($nMod10) {
            case 1:
                return $forms[0];
            case 2:
            case 3:
            case 4:
                return $forms[1];
            default:
                return $forms[2];
        }
    }

    /**
     * Унифицированный метод для вывода ошибок в Telegram.
     */
    private function sendError(string $message): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $message,
        ]);
    }
}

<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class BandageCraftActionStart extends BaseAction
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

        // Пример callback_data: "craftBandage_10"
        // Разбиваем по "_"
        $data = $callbackQuery->getData();
        $parts = explode('_', $data);
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

        // Повторная проверка ресурсов: умножаем нормы на $this->quantity
        if (!$this->checkAndDeductResources($character['id'], $this->quantity)) {
            return $this->sendError("Недостаточно ресурсов для крафта {$this->quantity} шт.");
        }

        // Запуск процесса крафта
        return $this->startCraftingProcess($character, $user['id'], $this->quantity);
    }

    /**
     * Проверяем и списываем ресурсы для нужного количества (qty).
     */
    private function checkAndDeductResources(int $characterId, int $qty): bool
    {
        // Нормы на 1 шт.
        $requiredResources = [
            'Травы'         => 2,
            'Кора деревьев' => 2,
            'Водоросли'     => 3,
        ];

        foreach ($requiredResources as $resName => $baseAmount) {
            $totalNeeded = $baseAmount * $qty;

            // Проверяем наличие
            $resource = $this->characterResourceModel->getResourceByNameAndCharacterId($resName, $characterId);
            if (!$resource || $resource['quantity'] < $totalNeeded) {
                return false;
            }

            // Списываем
            $charRes = $this->characterResourceModel
                ->where('id_characters', $characterId)
                ->where('id_resources', $resource['id'])
                ->first();

            $newQty = $charRes['quantity'] - $totalNeeded;
            $this->characterResourceModel->update($charRes['id'], ['quantity' => $newQty]);
        }

        return true;
    }

    /**
     * Создаём запись задачи (character_tasks), учитывая $qty.
     */
    private function startCraftingProcess(array $character, int $userId, int $qty): ServerResponse
    {
        // Находим запись в tasks: "craftBandage"
        $craftTask = $this->taskModel->where('name', 'craftBandage')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт повязки" не найдена в базе данных.');
        }

        // Проверяем, нет ли активной задачи
        $activeTask = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id'      => $craftTask['id'],
            'status'       => 'in_work'
        ])->first();

        if ($activeTask) {
            return $this->sendError(
                "Извини, но у тебя уже идёт крафт повязки! Дождись окончания или прерви," .
                " но учти, что при прерывании ресурсы не возвращаются."
            );
        }

        // Считаем время на 1 шт., умножаем на qty
        $durationForOne = $this->calculateCraftingDuration($character, $craftTask);
        $totalDuration  = $durationForOne * $qty;

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $totalDuration . 'M'));

        // Сохраняем кол-во и recipe key в task_settings.
        // recipe ОБЯЗАТЕЛЕН для GenericCraftCompletionHandler (F2.2 cutover) —
        // без него handler логирует error и не завершает task → игрок теряет ресурсы.
        $taskSettings = json_encode([
            'quantity' => $qty,
            'recipe'   => 'Bandage',
        ]);

        // Создаём запись в character_tasks
        $this->characterTaskModel->save([
            'character_id'     => $character['id'],
            'telegram_user_id' => $userId,
            'task_id'          => $craftTask['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => $taskSettings,
        ]);

        // Уведомляем игрока
        return $this->notifyCraftStarted($character, $startTime, $endTime, $qty);
    }

    /**
     * Расчёт базовой длительности (на 1 шт.) через формулу атрибутов.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        // Атрибуты
        $experience = $character['experience'];
        $agility    = $character['agility'];
        $intellect  = $character['intellect'];

        // Весовые коэффициенты
        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $attributeScore    = ($experience * $expFactor) + ($agility * $agiFactor) + ($intellect * $intFactor);
        $maxAttributeScore = 1000 * ($expFactor + $agiFactor + $intFactor);
        $normalizedScore   = $attributeScore / $maxAttributeScore;

        $minDuration = $craftTask['min_duration']; // напр., 1
        $maxDuration = $craftTask['max_duration']; // напр., 3

        // Обратная зависимость: чем выше атрибуты, тем ближе к minDuration
        $adjustedDuration = $minDuration + ($maxDuration - $minDuration) * (1 - $normalizedScore);
        return max($minDuration, min($maxDuration, round($adjustedDuration)));
    }

    /**
     * Отправляем сообщение о запущенном процессе крафта,
     * форматируем общее время (минуты -> дни/часы/минуты),
     * предупреждаем о прерывании и потере ресурсов.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime, int $qty): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;
        $timeString = $this->formatMinutes($minutes);

        $text = "*Процесс крафта запущен*\n\n"
            . "Ты создаёшь: *🩹 Повязку* x{$qty} шт.\n\n"
            . "Время крафта: *{$timeString}* ⏱️\n\n"
            . "После завершения будет добавлено *{$qty}* шт. в инвентарь.\n\n"
            . "❗Прерывание задачи = потеря ресурсов!\n\n"
            . "_О готовности узнаешь в сообщении._ 🎁";

        $imagePath = base_url('uploads/telegram/craft/bandage_that_is_made_in_the_wild.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Преобразуем общее количество минут в строку вида "X дней Y часов Z минут".
     */
    private function formatMinutes(int $totalMinutes): string
    {
        if ($totalMinutes <= 0) {
            return "0 минут";
        }

        $days  = intdiv($totalMinutes, 1440);
        $rem   = $totalMinutes % 1440;
        $hours = intdiv($rem, 60);
        $mins  = $rem % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days} " . $this->pluralForm($days, ['день','дня','дней']);
        }
        if ($hours > 0) {
            $parts[] = "{$hours} " . $this->pluralForm($hours, ['час','часа','часов']);
        }
        if ($mins > 0) {
            $parts[] = "{$mins} " . $this->pluralForm($mins, ['минута','минуты','минут']);
        }
        if (empty($parts)) {
            return "0 минут";
        }

        return implode(' ', $parts);
    }

    /**
     * Подбираем правильную форму слова (день/дня/дней).
     */
    private function pluralForm(int $n, array $forms): string
    {
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
     * Универсальный метод вывода ошибок в Telegram.
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

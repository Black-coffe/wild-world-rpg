<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterBuildingModel;
use App\Models\ClaimedCellModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CharacterTaskModel;
use App\Models\TaskModel;

use App\Services\Tasks\ActiveTasksService;

use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use CodeIgniter\I18n\Time;

/**
 * Класс DeleteBaseAction:
 * теперь (примерно) содержит две кнопки (моментальный снос и планируемый переезд)
 * и логику для обоих вариантов.
 */
class DeleteBaseAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // Получаем пользователя и персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Ошибка: не удалось определить пользователя или персонажа.'
            ]);
        }

        $callbackData = $this->callbackQuery->getData();

        // 1) Главное меню удаления базы
        if ($callbackData === 'DeleteBase') {
            return $this->showBaseRemovalOptions();
        }

        // 2) Моментальный снос
        if ($callbackData === 'DeleteBase_InstantDemolition') {
            return $this->performInstantDemolition($character);
        }

        // 3) Планируемый переезд
        if ($callbackData === 'DeleteBase_PlannedRelocation') {
            // Теперь этот метод уже не «заглушка», а полноценная логика
            return $this->performPlannedRelocation($character);
        }

        // Неизвестный колбэк
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => 'Неизвестная команда удаления базы.'
        ]);
    }

    /**
     * Показываем меню с двумя вариантами (моментальный снос / планируемый переезд).
     */
    private function showBaseRemovalOptions(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $text = "Ты собираешься удалить (или перенести) свою базу. Есть два варианта:\n\n"
            . "1) *Моментальный снос*\n"
            . "   - База удаляется мгновенно.\n"
            . "   - Теряешь *70%* от всех ресурсов и *80%* от крафтов.\n"
            . "   - Все здания сгорают без возврата материалов.\n\n"
            . "2) *Планируемый переезд*\n"
            . "   - Займёт 24 часа.\n"
            . "   - Позволит сохранить 100% ресурсов и зданий.\n\n"
            . "Выбери нужный вариант:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '❌ Моментальный снос',  'callback_data' => 'DeleteBase_InstantDemolition'],
                ],
                [
                    ['text' => '🐢 Планируемый переезд','callback_data' => 'DeleteBase_PlannedRelocation'],
                ],
            ]
        ];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Моментальный снос: списываем 70% ресурсов, 80% крафта, удаляем базу.
     */
    private function performInstantDemolition(array $character): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // Пример уже реализованной логики 70%/80% (сокращённо)
        // --------------------------------------------------
        // 1) Списываем ресурсы
        $resModel = new CharacterResourceModel();
        $allResources = $resModel->where('id_characters', $character['id'])->findAll();
        foreach ($allResources as $res) {
            $oldQty = (int) $res['quantity'];
            if ($oldQty <= 0) continue;

            $newQty = (int) floor($oldQty * 0.30);
            if ($oldQty >= 1 && $newQty == 0) {
                $newQty = 1; // хотя бы 1 шт., если было >0
            }

            if ($newQty <= 0) {
                $resModel->delete($res['id']);
            } else {
                $resModel->update($res['id'], ['quantity' => $newQty]);
            }
        }

        // 2) Списываем крафтовые предметы (80%)
        $craftedItemsLogModel = new CraftedItemsLogModel();
        $allCraft = $craftedItemsLogModel->where('character_id', $character['id'])->findAll();
        foreach ($allCraft as $itemRow) {
            $oldQty = (int) $itemRow['quantity'];
            if ($oldQty <= 0) continue;

            $newQty = (int) floor($oldQty * 0.20);
            if ($oldQty >= 1 && $newQty == 0) {
                $newQty = 1;
            }

            if ($newQty <= 0) {
                $craftedItemsLogModel->delete($itemRow['id']);
            } else {
                $craftedItemsLogModel->update($itemRow['id'], ['quantity' => $newQty]);
            }
        }

        // 3) Удаляем здания
        $buildingModel = new CharacterBuildingModel();
        $buildingModel->where('character_id', $character['id'])->delete();

        // 4) Удаляем лагерь
        $claimedCellModel = new ClaimedCellModel();
        $claimedCellModel->where('character_id', $character['id'])->delete();

        // 5) Сообщение об успехе
        $text = "Ты произвёл *Моментальный снос* базы!\n\n"
            . "📉 Потеряно ~70% ресурсов и ~80% крафта.\n"
            . "Все строения уничтожены безвозвратно.\n\n"
            . "Если решишь снова основать базу – сможешь построить её в другой локации.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия', 'callback_data' => 'characterActions'],
                    ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
                ],
            ]
        ];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Планируемый переезд: проверяем задачи, создаём новую задачу на 24 часа,
     * сохраняем все постройки в task_settings (JSON).
     */
    private function performPlannedRelocation(array $character): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // 1) Проверка активных задач
        $activeTasksService = new ActiveTasksService();
        $activeTasks = $activeTasksService->getActiveTasksWithDetails($character['id']);

        if (!empty($activeTasks)) {
            // Есть активные задачи → нельзя запустить переезд
            $taskListText = "Активные задачи:\n\n";
            foreach ($activeTasks as $idx => $taskRow) {
                $taskListText .= ($idx + 1) . ") "
                    . $taskRow['name_rus'] . "\n   Осталось: "
                    . $taskRow['time_left_str'] . "\n\n";
            }

            $text = "Планируемый переезд недоступен, так как у тебя есть незавершённые задачи:\n\n"
                . $taskListText
                . "Дождись окончания или прерви задачи, затем повтори попытку!";

            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'Markdown'
            ]);
        }

        // 2) Получаем все постройки
        $buildingModel = new CharacterBuildingModel();
        $allBuildings  = $buildingModel->where('character_id', $character['id'])->findAll();

        // 3) Убираем поле 'id' и формируем массив
        $buildingsData = [];
        foreach ($allBuildings as $b) {
            unset($b['id']);
            $buildingsData[] = $b;
        }

        // Дополнительно, если нужно, можно сохранить состояние ресурсов,
        // координат и т.д. Но пока только здания:
        $taskSettings = [
            'character_buildings' => $buildingsData,
            'note' => 'Инфа о постройках на момент запуска переезда',
        ];
        $taskSettingsJson = json_encode($taskSettings, JSON_UNESCAPED_UNICODE);

        // 4) Ищем (или создаём) задачу в таблице tasks (BaseRelocation)
        $taskModel = new TaskModel();
        $relocationTask = $taskModel->where('name', 'BaseRelocation')->first();

        if (!$relocationTask) {
            // Создаём строку в tasks, если нужно
            $taskId = $taskModel->insert([
                'name'                 => 'BaseRelocation',
                'name_rus'            => 'Планируемый переезд',
                'description'         => 'Перенос базы за 24 часа',
                'min_duration'        => 1440, // 24 часа (минуты)
                'max_duration'        => 1440,
                'type'                => 'optionally',
                'difficulty_level'    => 7,
                'execution_limit'     => 0,
                'parallel_execution_allowed' => 0,
                'interruptible'       => 1,
            ]);
        } else {
            $taskId = $relocationTask['id'];
        }

        // 5) Записываем задачу в character_tasks
        $characterTaskModel = new CharacterTaskModel();
        $now = Time::now();
        $endTime = $now->addHours(24);

        $characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $character['telegram_user_id'] ?? 0,
            'task_id'          => $taskId,
            'start_time'       => $now->toDateTimeString(),
            'end_time'         => $endTime->toDateTimeString(),
            'status'           => 'in_work',
            'task_settings'    => $taskSettingsJson
        ]);

        // 6) Финальное сообщение
        $captionText = "Ты запустил *Планируемый переезд*! База пока на месте.\n\n"
            . "Процесс займёт ~24 часа (до {$endTime->toDateTimeString()}).\n"
            . "Все действия по строительству, крафту и т.д. будут заблокированы.\n"
            . "При отмене задачи — база не сносится, но переезд не состоится.\n\n"
            . "Удачи в переезде!";

        $imagePath = base_url('uploads/telegram/camp/relocation.png');

        return Request::sendPhoto([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $captionText,
            'parse_mode'   => 'Markdown',
        ]);
    }
}

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
 * Показывает меню с «Моментальным сносом» и «Планируемым переездом»,
 * реализует обе логики, и проверяет "если переезд уже идёт, то прерываем".
 */
class DeleteBaseAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        // Закрываем "часики" на инлайн-кнопке
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

        // Главное меню: выбор типа удаления базы
        if ($callbackData === 'DeleteBase') {
            return $this->showBaseRemovalOptions();
        }
        // Моментальный снос
        if ($callbackData === 'DeleteBase_InstantDemolition') {
            return $this->performInstantDemolition($character);
        }
        // Планируемый переезд
        if ($callbackData === 'DeleteBase_PlannedRelocation') {
            return $this->performPlannedRelocation($character);
        }

        // Если не совпало ни с одним
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => 'Неизвестная команда удаления базы.'
        ]);
    }

    /**
     * Показываем меню с двумя вариантами: моментальный снос / планируемый переезд.
     */
    private function showBaseRemovalOptions(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $text = "Ты собираешься удалить (или перенести) свою базу. Есть два варианта:\n\n"
            . "1) *Моментальный снос*\n"
            . "   - База удаляется мгновенно.\n"
            . "   - Теряешь *70%* от ресурсов и *80%* от крафта.\n"
            . "   - Все здания безвозвратно сгорают.\n\n"
            . "2) *Планируемый переезд*\n"
            . "   - Длится 24 часа.\n"
            . "   - Позволяет сохранить 100% ресурсов и построек.\n\n"
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
     * Логика моментального сноса:
     * 1) Прерываем, если есть "BaseRelocation" (переезд).
     * 2) Списываем 70% ресурсов, 80% крафта.
     * 3) Удаляем все здания и лагерь.
     */
    private function performInstantDemolition(array $character): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // --- A) Проверяем и прерываем, если уже идёт переезд ---
        $activeTasksService = new ActiveTasksService();
        $activeTasks        = $activeTasksService->getActiveTasksWithDetails($character['id']);
        $characterTaskModel = new CharacterTaskModel();

        foreach ($activeTasks as $taskRow) {
            if ($taskRow['name'] === 'BaseRelocation') {
                // Прерываем задачу переезда
                $characterTaskModel->update($taskRow['charTaskId'], [
                    'status' => 'interrupted'
                ]);
                // Можно дополнительно уведомить игрока,
                // но здесь просто прерываем "в фоне".
                break;
            }
        }

        // --- B) Списываем 70% ресурсов (оставляем 30%) ---
        $resModel = new CharacterResourceModel();
        $allResources = $resModel->where('id_characters', $character['id'])->findAll();

        foreach ($allResources as $res) {
            $oldQty = (int) $res['quantity'];
            if ($oldQty <= 0) continue;

            $newQty = (int) floor($oldQty * 0.30);
            if ($oldQty >= 1 && $newQty == 0) {
                $newQty = 1;
            }
            if ($newQty <= 0) {
                $resModel->delete($res['id']);
            } else {
                $resModel->update($res['id'], ['quantity' => $newQty]);
            }
        }

        // --- C) Списываем 80% крафта (оставляем 20%) ---
        $craftedItemsLogModel = new CraftedItemsLogModel();
        $allCraft = $craftedItemsLogModel->where('character_id', $character['id'])->findAll();

        foreach ($allCraft as $cItem) {
            $oldQty = (int) $cItem['quantity'];
            if ($oldQty <= 0) continue;

            $newQty = (int) floor($oldQty * 0.20);
            if ($oldQty >= 1 && $newQty == 0) {
                $newQty = 1;
            }
            if ($newQty <= 0) {
                $craftedItemsLogModel->delete($cItem['id']);
            } else {
                $craftedItemsLogModel->update($cItem['id'], ['quantity' => $newQty]);
            }
        }

        // --- D) Удаляем здания ---
        $charBuildingModel = new CharacterBuildingModel();
        $charBuildingModel->where('character_id', $character['id'])->delete();

        // --- E) Удаляем лагерь ---
        $claimedCellModel = new ClaimedCellModel();
        $claimedCellModel->where('character_id', $character['id'])->delete();

        // --- F) Сообщаем результат ---
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
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Логика планируемого переезда: сохраняем в task_settings здания,
     * создаём задачу на 24 часа, сообщаем игроку.
     */
    private function performPlannedRelocation(array $character): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // 1) Проверяем наличие других задач (если есть - недопустимо)
        $activeTasksService = new ActiveTasksService();
        $activeTasks        = $activeTasksService->getActiveTasksWithDetails($character['id']);
        if (!empty($activeTasks)) {
            $taskListText = "Активные задачи:\n\n";
            foreach ($activeTasks as $idx => $t) {
                $taskListText .= ($idx + 1). ") {$t['name_rus']} (осталось: {$t['time_left_str']})\n";
            }
            $text = "Нельзя начать *Планируемый переезд*, так как есть незавершённые задачи:\n\n"
                . $taskListText
                . "\nДождись окончания или прерви задачу, затем повтори попытку!";

            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'Markdown'
            ]);
        }

        // 2) Сохраняем здания в task_settings
        $charBuildingModel = new CharacterBuildingModel();
        $allBuildings = $charBuildingModel->where('character_id', $character['id'])->findAll();

        $bArr = [];
        foreach ($allBuildings as $b) {
            unset($b['id']); // Убираем первичный ключ
            $bArr[] = $b;
        }

        $taskSettings = [
            'character_buildings' => $bArr,
            'note' => 'Инфа о постройках на момент запуска переезда',
        ];
        $settingsJson = json_encode($taskSettings, JSON_UNESCAPED_UNICODE);

        // 3) Ищем/создаём задачу BaseRelocation в tasks
        $taskModel = new TaskModel();
        $relocation = $taskModel->where('name', 'BaseRelocation')->first();
        if (!$relocation) {
            $taskId = $taskModel->insert([
                'name'                 => 'BaseRelocation',
                'name_rus'            => 'Планируемый переезд',
                'description'         => 'Перенос базы за 24 часа',
                'min_duration'        => 1440,
                'max_duration'        => 1440,
                'type'                => 'optionally',
                'difficulty_level'    => 7,
                'execution_limit'     => 0,
                'parallel_execution_allowed' => 0,
                'interruptible'       => 1,
            ]);
        } else {
            $taskId = $relocation['id'];
        }

        // 4) Записываем новую задачу в character_tasks
        $charTaskModel = new CharacterTaskModel();
        $now = Time::now();
        $endTime = $now->addHours(24);

        $charTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $character['telegram_user_id'] ?? 0,
            'task_id'          => $taskId,
            'start_time'       => $now->toDateTimeString(),
            'end_time'         => $endTime->toDateTimeString(),
            'status'           => 'in_work',
            'task_settings'    => $settingsJson,
        ]);

        // 5) Отправляем сообщение (с картинкой)
        $text = "Ты запустил *Планируемый переезд*! База пока на месте.\n\n"
            . "Процесс займёт ~24 часа (до {$endTime->toDateTimeString()}).\n"
            . "Все действия по строительству, крафту и т.д. будут заблокированы.\n"
            . "Если отменишь задачу — база не сносится.\n\n"
            . "Удачи в переезде!";

        $imagePath = base_url('uploads/telegram/camp/relocation.png');

        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }
}

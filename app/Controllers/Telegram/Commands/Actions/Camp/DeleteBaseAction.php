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
 * Показывает меню с «Моментальным сносом», «Планируемым сносом» и
 * «Полноценным переездом», а также реализует логику моментального и планового сноса.
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

        // Меню с вариантами
        if ($callbackData === 'DeleteBase') {
            return $this->showBaseRemovalOptions();
        }
        // Моментальный снос
        if ($callbackData === 'DeleteBase_InstantDemolition') {
            return $this->performInstantDemolition($character);
        }
        // Планируемый снос
        if ($callbackData === 'DeleteBase_PlannedRelocation') {
            return $this->performPlannedRelocation($character);
        }
        // Полноценный переезд (24 ч)
        if ($callbackData === 'DeleteBase_FullRelocation') {
            return $this->showFullRelocationInstructions($character);
        }

        // Если не совпало ни с одним
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => 'Неизвестная команда удаления базы.'
        ]);
    }

    /**
     * Меню с тремя вариантами: Моментальный снос, Планируемый снос, Полноценный переезд.
     */
    private function showBaseRemovalOptions(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $text = "Ты собираешься удалить свою базу. Возможные варианты:\n\n"
            . "1) *Моментальный снос*\n"
            . "   - База удаляется мгновенно.\n"
            . "   - Теряешь *70%* от ресурсов и *80%* от крафта.\n"
            . "   - Все здания безвозвратно сгорают.\n\n"
            . "2) *Планируемый снос* (8 ч)\n"
            . "   - Сохранение 100% ресурсов и построек.\n"
            . "   - Но база исчезает окончательно (без переноса).\n"
            . "   - На время сноса (8 ч) блокируются другие действия.\n\n"
            . "3) *Полноценный переезд* (24 ч)\n"
            . "   - Сохранение 100% ресурсов и построек.\n"
            . "   - База будет перенесена в новую локацию (укажешь координаты).\n"
            . "   - Длится 24 ч, во время которых блокируются почти все действия.\n\n"
            . "Выбери нужный вариант:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '❌ Моментальный снос',   'callback_data' => 'DeleteBase_InstantDemolition'],
                ],
                [
                    ['text' => '🐢 Планируемый снос / 8 часов',    'callback_data' => 'DeleteBase_PlannedRelocation'],
                ],
                [
                    ['text' => '🚚 Полноценный переезд / 24 часа', 'callback_data' => 'DeleteBase_FullRelocation'],
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
     * Выводит подсказку игроку о том, как запустить «Полноценный переезд» через команду /base_shifting.
     */
    private function showFullRelocationInstructions(array $character): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $text = "Ты выбрал *Полноценный переезд*, который занимает 24 часа.\n\n"
            . "🚚 Чтобы перевезти базу в новую локацию, введи в чат команду:\n"
            . "`/base_shifting X=123Y=543`\n\n"
            . "Укажи **свои** координаты (X,Y) вместо 123,543.\n\n"
            . "📝 *Важно!*:\n"
            . "1) X и Y могут быть от 1 до 1000.\n"
            . "2) Ты должен знать (изучить) эту ячейку (или разведать роботом). Иначе переезд не сработает.\n"
            . "3) Если ячейка занята другим игроком или тобой не изучена, система предложит ближайшую свободную.\n"
            . "4) ⏳ *Переезд базы доступен только раз в 10 дней*, не чаще!\n\n"
            . "После ввода команды запустится 24-часовая задача, и все постройки/ресурсы сохранятся.\n"
            . "*По окончании переезда твой персонаж окажется в новой локации вместе с базой!*";

        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Логика моментального сноса (InstantDemolition).
     * Списываем часть ресурсов/крафта, удаляем все здания и лагерь.
     */
    private function performInstantDemolition(array $character): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // --- A) Прерываем, если уже идёт переезд ---
        $activeTasksService = new ActiveTasksService();
        $activeTasks        = $activeTasksService->getActiveTasksWithDetails($character['id']);
        $characterTaskModel = new CharacterTaskModel();

        foreach ($activeTasks as $taskRow) {
            if ($taskRow['name'] === 'BaseRelocation') {
                // Прерываем задачу переезда
                $characterTaskModel->update($taskRow['charTaskId'], [
                    'status' => 'interrupted'
                ]);
                break;
            }
        }

        // --- B) Списываем 70% ресурсов ---
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

        // --- C) Списываем 80% крафта ---
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
     * Логика "Планируемого сноса": 12 часов, сохраняет всё, но не переносит.
     */
    private function performPlannedRelocation(array $character): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // 1) Проверяем, нет ли других активных задач
        $activeTasksService = new ActiveTasksService();
        $activeTasks        = $activeTasksService->getActiveTasksWithDetails($character['id']);
        if (!empty($activeTasks)) {
            $taskListText = "Активные задачи:\n\n";
            foreach ($activeTasks as $idx => $t) {
                $taskListText .= ($idx + 1). ") {$t['name_rus']} (осталось: {$t['time_left_str']})\n";
            }
            $text = "Нельзя начать *Планируемый снос*, так как есть незавершённые задачи:\n\n"
                . $taskListText
                . "\nДождись окончания или прерви задачу, затем повтори попытку!";

            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'Markdown'
            ]);
        }

        // 2) Собираем данные о зданиях
        $charBuildingModel = new CharacterBuildingModel();
        $allBuildings = $charBuildingModel->where('character_id', $character['id'])->findAll();

        $bArr = [];
        foreach ($allBuildings as $b) {
            unset($b['id']);
            $bArr[] = $b;
        }

        $taskSettings = [
            'character_buildings' => $bArr,
            'note' => 'Инфа о постройках на момент запуска планового сноса',
        ];
        $settingsJson = json_encode($taskSettings, JSON_UNESCAPED_UNICODE);

        // 3) Создаём или ищем задачу BaseRelocation
        $taskModel = new TaskModel();
        $relocation = $taskModel->where('name', 'BaseRelocation')->first();
        if (!$relocation) {
            $taskId = $taskModel->insert([
                'name'                 => 'BaseRelocation',
                'name_rus'            => 'Планируемый снос',
                'description'         => 'Перенос базы за 8 часов',
                'min_duration'        => 480, // 8 * 60
                'max_duration'        => 480,
                'type'                => 'optionally',
                'difficulty_level'    => 7,
                'execution_limit'     => 0,
                'parallel_execution_allowed' => 0,
                'interruptible'       => 1,
            ]);
        } else {
            $taskId = $relocation['id'];
        }

        // 4) Записываем новую задачу в character_tasks (12 ч)
        $charTaskModel = new CharacterTaskModel();
        $now = Time::now();
        $endTime = $now->addHours(8);

        $charTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $character['telegram_user_id'] ?? 0,
            'task_id'          => $taskId,
            'start_time'       => $now->toDateTimeString(),
            'end_time'         => $endTime->toDateTimeString(),
            'status'           => 'in_work',
            'task_settings'    => $settingsJson,
        ]);

        // 5) Сообщаем игроку
        $text = "Ты запустил *Планируемый снос*! (8 ч)\n\n"
            . "База пока стоит на месте, но строительство/крафт и т.д. заблокированы.\n"
            . "По истечении 8 часов база удалится без потерь ресурсов.\n\n"
            . "Если отменишь задачу раньше — ничего не сносится.";

        $imagePath = base_url('uploads/telegram/camp/relocation.png');

        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }
}

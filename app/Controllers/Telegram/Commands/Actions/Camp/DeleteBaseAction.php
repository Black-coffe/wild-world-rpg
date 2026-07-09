<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterBuildingModel;
use App\Models\ClaimedCellModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CharacterTaskModel;
use App\Models\TaskModel;
use App\Models\TeleportBeaconModel;

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
    use \App\Services\GameSettings\GameSettingsReaderTrait;

    /**
     * ADR-122 — штраф моментального сноса берётся ЗА СВЁРНУТЫЙ ЛАГЕРЬ, а не за «один сарай».
     *
     * 🔴 Что чинит. Здания сносились per-base (ADR-102), а вот 70% ресурсов и 80% крафта
     * списывались ГЛОБАЛЬНО, по `character_id`, независимо от того, какую из баз сносят.
     * Игрок с тремя базами, снёсший второстепенную, лишался 70% ВСЕГО имущества.
     *
     * Почему именно «последняя база», а не доля 1/N: ресурсы физически не принадлежат базе —
     * ни `character_resources`, ни `base_storage` не имеют `map_cell_id` (склад тоже общий
     * на персонажа). Любая пропорция была бы выдуманной. Честная семантика одна: штраф — цена
     * «свернуть лагерь целиком и остаться бездомным». Здания сносимой базы сгорают всегда —
     * это и есть настоящая per-base цена.
     *
     * Killswitch `buildings.demolition.last_base_only` (default OFF → byte-identical).
     */
    protected function lastBaseOnlyEnabled(): bool
    {
        return $this->gsBool('buildings.demolition.last_base_only', false);
    }

    /** Доля ресурсов, теряемая при моментальном сносе (0.70 = теряется 70%). */
    protected function resourceLossPct(): float
    {
        return $this->gsFloat('buildings.demolition.resource_loss_pct', 0.70);
    }

    /** Доля крафта, теряемая при моментальном сносе (0.80 = теряется 80%). */
    protected function craftLossPct(): float
    {
        return $this->gsFloat('buildings.demolition.craft_loss_pct', 0.80);
    }

    /** Сколько баз у персонажа сейчас (для «последняя ли это база»). */
    protected function baseCount(int $charId): int
    {
        return (new ClaimedCellModel())->countActiveBases($charId);
    }

    /**
     * Штрафовать ли имущество при этом сносе. При включённом killswitch и наличии других баз —
     * нет: игрок не сворачивает лагерь, а лишь убирает один из нескольких.
     */
    protected function shouldChargePenalty(int $charId): bool
    {
        return ! $this->lastBaseOnlyEnabled() || $this->baseCount($charId) <= 1;
    }

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

        // Числа берём из GameSettings, а не из строки: иначе после ребаланса текст соврёт.
        [, $character] = $this->getUserAndCharacter();
        $charId  = ($character !== null && is_numeric($character['id'] ?? null)) ? (int) $character['id'] : 0;
        $resPct  = (int) round($this->resourceLossPct() * 100);
        $crfPct  = (int) round($this->craftLossPct() * 100);
        $charged = $charId > 0 ? $this->shouldChargePenalty($charId) : true;

        // Честно говорим ИМЕННО ЭТОМУ игроку, что он потеряет: у владельца нескольких баз
        // имущество не страдает — сгорают только здания сносимой базы.
        $instantLoss = $charged
            ? "   - Теряешь *{$resPct}%* от ресурсов и *{$crfPct}%* от крафта — это твоя последняя база, "
                . "ты сворачиваешь лагерь целиком.\n"
            : "   - Ресурсы и крафт *не пострадают*: у тебя есть другие базы, лагерь ты не сворачиваешь.\n";

        $text = "Ты собираешься удалить свою базу. Возможные варианты:\n\n"
            . "1) *Моментальный снос*\n"
            . "   - База удаляется мгновенно.\n"
            . $instantLoss
            . "   - Все здания этой базы безвозвратно сгорают.\n\n"
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
    private function showFullRelocationInstructions(array|\App\Entities\CharacterEntity $character): ServerResponse
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
    private function performInstantDemolition(array|\App\Entities\CharacterEntity $character): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // --- ADR-102: определяем КОНКРЕТНУЮ базу для сноса (мультибэйс) ---
        $targetCell = $this->resolveTargetBaseCell($character);
        if ($targetCell === null) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $this->noTargetBaseMessage($character),
                'parse_mode' => 'Markdown',
            ]);
        }

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

        // --- B/C) Штраф за имущество — ТОЛЬКО если игрок сворачивает лагерь целиком (ADR-122).
        // При нескольких базах снос одной из них имущество не трогает: ресурсы и крафт лежат
        // в общем пуле персонажа и базе не принадлежат (нет map_cell_id ни в character_resources,
        // ни в base_storage). Раньше снос второстепенной базы стирал 70% ВСЕГО.
        $charIdInt = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $charged   = $this->shouldChargePenalty($charIdInt);

        if ($charged) {
            // 🔴 Считаем ПОТЕРЮ, а не остаток. `floor($qty * (1.0 - 0.80))` даёт 19 из 100:
            // 1.0 − 0.8 = 0.19999999999999996 в double, и floor съедает единицу. Вычитание
            // округлённой вверх потери от целого количества от этой ошибки свободно.
            $resLoss   = min(1.0, max(0.0, $this->resourceLossPct()));
            $craftLoss = min(1.0, max(0.0, $this->craftLossPct()));

            $resModel     = new CharacterResourceModel();
            $allResources = $resModel->where('id_characters', $character['id'])->findAll();

            foreach ($allResources as $res) {
                $oldQty = (int) $res['quantity'];
                if ($oldQty <= 0) continue;

                $newQty = $oldQty - (int) ceil($oldQty * $resLoss);
                if ($oldQty >= 1 && $newQty == 0) {
                    $newQty = 1;
                }
                if ($newQty <= 0) {
                    $resModel->delete($res['id']);
                } else {
                    $resModel->update($res['id'], ['quantity' => $newQty]);
                }
            }

            $craftedItemsLogModel = new CraftedItemsLogModel();
            $allCraft = $craftedItemsLogModel->where('character_id', $character['id'])->findAll();

            foreach ($allCraft as $cItem) {
                $oldQty = (int) $cItem['quantity'];
                if ($oldQty <= 0) continue;

                $newQty = $oldQty - (int) ceil($oldQty * $craftLoss);
                if ($oldQty >= 1 && $newQty == 0) {
                    $newQty = 1;
                }
                if ($newQty <= 0) {
                    $craftedItemsLogModel->delete($cItem['id']);
                } else {
                    $craftedItemsLogModel->update($cItem['id'], ['quantity' => $newQty]);
                }
            }
        }

        // --- D) Удаляем здания ТОЛЬКО этой базы (ADR-102: не трогаем другие базы) ---
        $charBuildingModel = new CharacterBuildingModel();
        $charBuildingModel
            ->where('character_id', $character['id'])
            ->where('map_cell_id', $targetCell)
            ->delete();

        // --- E) Удаляем ТОЛЬКО этот лагерь ---
        $claimedCellModel = new ClaimedCellModel();
        $claimedCellModel
            ->where('character_id', $character['id'])
            ->where('map_cell_id', $targetCell)
            ->delete();

        // --- E2) Чистим сирот этой базы (маяки телепорта на снесённой клетке) ---
        $this->cleanupBaseOrphans((int) $character['id'], $targetCell);

        // --- F) Сообщаем результат ---
        $lossLine = $charged
            ? '📉 Потеряно ~' . (int) round($this->resourceLossPct() * 100) . '% ресурсов и ~'
                . (int) round($this->craftLossPct() * 100) . "% крафта.\n"
            : "📦 Ресурсы и крафт сохранены — у тебя остались другие базы.\n";

        $text = "Ты произвёл *Моментальный снос* базы!\n\n"
            . $lossLine
            . "Все строения этой базы уничтожены безвозвратно.\n\n"
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
    private function performPlannedRelocation(array|\App\Entities\CharacterEntity $character): ServerResponse
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

        // ADR-102: какую базу сносим (мультибэйс)
        $targetCell = $this->resolveTargetBaseCell($character);
        if ($targetCell === null) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $this->noTargetBaseMessage($character),
                'parse_mode' => 'Markdown',
            ]);
        }

        // 2) Собираем данные о зданиях ТОЛЬКО этой базы
        $charBuildingModel = new CharacterBuildingModel();
        $allBuildings = $charBuildingModel
            ->where('character_id', $character['id'])
            ->where('map_cell_id', $targetCell)
            ->findAll();

        $bArr = [];
        foreach ($allBuildings as $b) {
            unset($b['id']);
            $bArr[] = $b;
        }

        $taskSettings = [
            'character_buildings' => $bArr,
            'base_cell' => $targetCell, // ADR-102: completion-handler снесёт ТОЛЬКО эту базу
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

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * ADR-102: map_cell_id базы, которую снесём (активная база, где стоит игрок;
     * fallback — единственная база). null — игрок не на базе и баз ≠ 1 (неоднозначно).
     *
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     */
    private function resolveTargetBaseCell(array|\App\Entities\CharacterEntity $character): ?int
    {
        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $cell   = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : 0;
        return (new ClaimedCellModel())->resolveTargetBaseCell($charId, $cell);
    }

    /**
     * ADR-102: сообщение, когда базу-цель не удалось определить однозначно.
     *
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     */
    private function noTargetBaseMessage(array|\App\Entities\CharacterEntity $character): string
    {
        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $bases  = (new ClaimedCellModel())->countActiveBases($charId);
        if ($bases === 0) {
            return "У тебя нет базы для сноса.";
        }
        return "У тебя несколько баз. Чтобы снести нужную, *встань на неё* (телепортируйся или дойди), затем повтори снос.";
    }

    /**
     * ADR-102: чистим сирот, оставшихся от снесённой базы (маяки телепорта на её клетке).
     * Иначе остаются записи с невалидным map_cell_id.
     */
    private function cleanupBaseOrphans(int $charId, int $cell): void
    {
        if ($charId <= 0 || $cell <= 0) {
            return;
        }
        (new TeleportBeaconModel())
            ->where('character_id', $charId)
            ->where('map_cell_id', $cell)
            ->delete();
    }
}

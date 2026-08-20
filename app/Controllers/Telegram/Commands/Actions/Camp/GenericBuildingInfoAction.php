<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterResourceModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use App\Services\Notifications\MediaSender;
use App\Services\Tasks\ActionScopeService;
use App\Services\Tasks\ActiveTasksService;
use Config\Buildings;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * S1 (v0.51.182+) — generic preview-handler для всех зданий.
 *
 * Заменяет 12 копипастных `Build*Construction.php` (~4187 LOC дубля), которые
 * раньше отдельно показывали preview каждого здания. Поведение определяется
 * рецептом из `app/Config/Buildings.php` (новые поля `emoji` + `info_text`),
 * ключ передаётся через callback_data: `genericBuildInfo_HandPump`.
 *
 * Контракт preview (1:1 с legacy):
 *   - Нет user/character → ошибка.
 *   - Активный переезд → блок сервисом `ActiveTasksService`.
 *   - Нет лагеря → buttons «Разбить лагерь / Действия».
 *   - Не на базе → buttons «Телепорт / Двигаться».
 *   - Низкий уровень → buttons «Телепорт / Двигаться» + сообщение про уровень.
 *   - Список ресурсов + крафтовых компонентов (с иконками + в наличии).
 *   - Список зависимостей (зданий, которые должны быть построены).
 *   - Если всего хватает → кнопка «🛠️ Строить» → `genericStartBuild_<Key>` → {@see GenericBuildingAction}.
 *   - Если не хватает → buttons «Добыть / Купить / Действия / Инвентарь».
 *   - Edit-in-place через `MediaSender::editOrSend()` (ADR-018).
 */
class GenericBuildingInfoAction extends BaseAction
{
    private ClaimedCellModel       $claimedCellModel;
    private BuildingModel          $buildingModel;
    private CharacterBuildingModel $characterBuildingModel;
    private CharacterResourceModel $characterResourceModel;
    private CraftedItemsModel      $craftedItemsModel;
    private CraftedItemsLogModel   $craftedItemsLogModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
    }

    public function handle(): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // 1. Parse building key from callback_data: genericBuildInfo_HandPump
        $callbackData = $this->callbackQuery->getData();
        $parts        = explode('_', $callbackData, 2);
        $buildingKey  = $parts[1] ?? '';
        if ($buildingKey === '') {
            return $this->sendError('Не указан тип здания.');
        }

        /** @var Buildings $cfg */
        $cfg    = config('Buildings');
        $recipe = $cfg->get($buildingKey);
        if ($recipe === null) {
            return $this->sendError("Неизвестное здание: {$buildingKey}");
        }

        // 2. user/character (F1.4: getUserAndCharacter() может вернуть CharacterEntity →
        // narrow to array через toArray() для strict-array signatures внизу).
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь не найден в базе данных или персонаж не определён.');
        }
        if (is_object($character) && method_exists($character, 'toArray')) {
            $character = $character->toArray();
        }

        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // 3. Active relocation block
        if ((new ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        $emoji      = $recipe['emoji'];
        $nameRus    = $recipe['name_rus'];
        $infoText   = $recipe['info_text'];
        $minLevel   = $recipe['level_required'];
        $imagePath  = base_url($recipe['image_in_progress']);

        // 4. No camp
        $claimedCells = $this->claimedCellModel->where('character_id', $character['id'])->findAll();
        if (empty($claimedCells)) {
            return MediaSender::editOrSend($this->navTarget() + [
                'photo'        => Request::encodeFile($imagePath),
                'caption'      => "У вас нет лагеря. Разбейте лагерь, чтобы продолжить.",
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[
                        ['text' => '🏕 Разбить лагерь', 'callback_data' => 'Camp'],
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ]],
                ]),
            ]);
        }

        // 5. Not on base
        $charRefreshed = $this->characterModel->find($character['id']);
        $charArr       = is_array($charRefreshed)
            ? $charRefreshed
            : (is_object($charRefreshed) && method_exists($charRefreshed, 'toArray')
                ? $charRefreshed->toArray()
                : []);
        // ADR-095 Фаза 1b: «активная база» — строить можно на ЛЮБОЙ своей базе, на
        // которой стоишь (раньше сравнивалось только с ПЕРВОЙ базой → на 2-й базе
        // игрок ошибочно получал «не в лагере»).
        $currentCellRaw = $charArr['cell_number'] ?? null;
        $currentCell    = is_numeric($currentCellRaw) ? (int) $currentCellRaw : 0;
        $activeBase     = $this->claimedCellModel->findActiveCell((int) $character['id'], $currentCell);
        if ($activeBase === null) {
            return MediaSender::editOrSend($this->navTarget() + [
                'photo'        => Request::encodeFile($imagePath),
                'caption'      => "Вы находитесь не в лагере. Переместитесь в лагерь, чтобы продолжить строительство.",
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[
                        ['text' => '📡 Телепорт', 'callback_data' => 'TeleportToCamp'],
                        ['text' => '🧭 Двигаться', 'callback_data' => 'move'],
                    ]],
                ]),
            ]);
        }

        // 6. Low character level
        if ((int) $character['level'] < $minLevel) {
            return MediaSender::editOrSend($this->navTarget() + [
                'photo'        => Request::encodeFile($imagePath),
                'caption'      => "Ваш уровень слишком низкий для строительства *{$emoji} {$nameRus}*. Нужно хотя бы уровень: *{$minLevel}*.",
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[
                        ['text' => '📡 Телепорт', 'callback_data' => 'TeleportToCamp'],
                        ['text' => '🧭 Двигаться', 'callback_data' => 'move'],
                    ]],
                ]),
            ]);
        }

        // 7. Dependencies, resources, crafted-items checks
        $missingDeps    = $this->checkDependencies((int) $character['id'], $recipe['dependencies']);
        $requiredRes    = $recipe['resources'];
        $requiredItems  = $recipe['crafted_items'];
        $missingRes     = $this->checkResources((int) $character['id'], $requiredRes);
        $missingItems   = $this->checkCraftedItems((int) $character['id'], $requiredItems);

        $hasShortage = !empty($missingDeps) || !empty($missingRes) || !empty($missingItems);

        // 8. Compute duration estimate (approx — using task min/max with character stats)
        $duration = $this->estimateDuration($character, $recipe['task_name']);

        // 9. Build caption text
        // ADR-143: легенда области действия — стройка «🏠 Только на базе» + занятость
        // (фоновая) из флага задачи, чтобы preview не врал, если админ поменяет флаг.
        $scope        = new ActionScopeService();
        $buildTaskRow = $this->rowToArr($this->taskModel->where('name', $recipe['task_name'])->first());
        $bgBuild      = $scope->isBackground($buildTaskRow['parallel_execution_allowed'] ?? 1);
        $caption = "*{$emoji} {$nameRus}!*\n"
            . $scope->scopeLine(ActionScopeService::KIND_BUILD, $bgBuild) . "\n\n"
            . "Для строительства тебе нужны:\n\n"
            . $this->formatResourcesText($character['id'], $requiredRes)
            . $this->formatCraftedItemsText($character['id'], $requiredItems)
            . ($duration !== null
                ? "\n*Строительство займёт:* ~{$duration} минут"
                    . $this->durationBonusNote($character, $recipe['task_name'], $duration) . "\n"
                : '')
            . ($infoText !== '' ? "\n*Описание:* {$infoText}\n\n" : "\n");

        if (!empty($missingDeps)) {
            $caption .= "\n*Сначала постройте:* " . implode(', ', $missingDeps) . "\n";
        }
        if (!empty($missingRes)) {
            $caption .= "\nНедостающие ресурсы:\n" . $this->formatMissing($missingRes);
        }
        if (!empty($missingItems)) {
            $caption .= "\nНедостающие предметы:\n" . $this->formatMissing($missingItems);
        }

        // ADR-156: честная оговорка про материалы выше собственного уровня. Аудит 2026-07-26:
        // 13 построек из 16 требуют то, что игрок их «гейта» ещё не добывает и не крафтит
        // (Спортзал объявлен с L5, а нужны Стекло пакеты — крафт с L25). Без этой строки
        // новичок упирается в стену молча: список «недостающего» есть, а почему добыть не
        // выходит — не сказано. Показываем ТОЛЬКО когда чего-то не хватает и только то,
        // что вне досягаемости; материалы можно купить или выменять — так и пишем.
        if ($hasShortage) {
            $charLevel  = isset($character['level']) && is_numeric($character['level']) ? (int) $character['level'] : 1;
            $outOfReach = (new \App\Services\Buildings\BuildingGateService())
                ->outOfReachMaterials($buildingKey, $charLevel);
            if ($outOfReach !== []) {
                $parts    = [];
                $hasRes   = false;
                $hasCraft = false;
                foreach (array_slice($outOfReach, 0, 3) as $row) {
                    $parts[] = "{$row['name']} ({$row['kind']} с {$row['level']} ур.)";
                    $row['kind'] === 'крафт' ? $hasCraft = true : $hasRes = true;
                }
                // Пути обхода РАЗНЫЕ, и обобщать их — снова полуправда: ресурсы свободно
                // продаются в магазине, а крафтовые предметы идут только через закрытый
                // рынок, который открывается Складом (BuyCraftAction проверяет постройку).
                $ways = [];
                if ($hasRes) {
                    $ways[] = 'ресурсы продаются в магазине';
                }
                if ($hasCraft) {
                    $ways[] = 'предметы делают на верстаке или берут на закрытом рынке (нужен Склад)';
                }
                $caption .= "\n⚠️ *На своём уровне добудешь не всё:* " . implode(', ', $parts)
                    . '. ' . ucfirst(implode('; ', $ways)) . ".\n";
            }
        }

        // 10. Buttons: «Строить» if sufficient, else gather/buy/actions/inventory
        // Дефицит-ссылки: общая кнопка «Купить» вела на выбор редкости, и игрок сам гадал,
        // какая редкость у глины. Теперь каждый недостающий ресурс — своя кнопка сразу на
        // нужное количество. Первые четыре, по две в ряд; остальное добирается общей кнопкой.
        $needRows = [];
        $needBtns = [];
        foreach (array_slice($missingRes, 0, 4) as $info) {
            $resId  = (int) $info['id'];
            $lack   = max(0, (int) $info['need'] - (int) $info['have']);
            if ($resId <= 0 || $lack <= 0) {
                continue;
            }
            $needBtns[] = [
                'text'          => "🛒 {$info['name']} ×{$lack}",
                'callback_data' => "buy_need_{$resId}_{$lack}",
            ];
        }
        foreach (array_chunk($needBtns, 2) as $chunk) {
            $needRows[] = $chunk;
        }

        $keyboard = [
            'inline_keyboard' => [[
                // ADR-168 — метка источника «экран постройки».
                ['text' => '⛏️ Добыть ресурсы', 'callback_data' => \App\Services\Logging\ActionOrigin::tag('gather', \App\Services\Logging\ActionOrigin::FROM_BUILDING)],
                ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
            ], [
                ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ]],
        ];
        if ($needRows !== []) {
            array_splice($keyboard['inline_keyboard'], 0, 0, $needRows);
        }
        if (!$hasShortage) {
            $keyboard['inline_keyboard'][] = [[
                'text'          => "🛠️ Строить {$emoji} {$nameRus}",
                'callback_data' => "genericStartBuild_{$buildingKey}",
            ]];
        }

        return MediaSender::editOrSend($this->navTarget() + [
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $caption,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * F1.4: модели возвращают Entity или array — narrow to array<string,mixed>.
     *
     * @return array<string, mixed>|null
     */
    private function rowToArr(mixed $row): ?array
    {
        $arr = null;
        if (is_array($row)) {
            $arr = $row;
        } elseif (is_object($row) && method_exists($row, 'toArray')) {
            $tmp = $row->toArray();
            if (is_array($tmp)) {
                $arr = $tmp;
            }
        }
        if ($arr === null) {
            return null;
        }
        $out = [];
        foreach ($arr as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }

    /**
     * @param list<string> $deps name_en зданий
     * @return list<string> name_rus отсутствующих зданий
     */
    private function checkDependencies(int $charId, array $deps): array
    {
        if (empty($deps)) {
            return [];
        }
        $missing = [];
        foreach ($deps as $depEn) {
            $bld = $this->rowToArr($this->buildingModel->where('name_en', $depEn)->first());
            if ($bld === null) {
                $missing[] = $depEn;
                continue;
            }
            $bldId = isset($bld['id']) && is_numeric($bld['id']) ? (int) $bld['id'] : 0;
            if ($bldId === 0) {
                $missing[] = $depEn;
                continue;
            }
            $owns = $this->characterBuildingModel
                ->where('character_id', $charId)
                ->where('building_id', $bldId)
                ->countAllResults();
            if ($owns === 0) {
                $missing[] = BuildingModel::rusName($bld, (string) $depEn);
            }
        }
        return $missing;
    }

    /**
     * @param array<string,int> $reqs name_en → нужное количество
     * @return array<string,array{need:int,have:int,name:string,id:int}>
     */
    private function checkResources(int $charId, array $reqs): array
    {
        $missing = [];
        foreach ($reqs as $resEn => $need) {
            $row = $this->rowToArr($this->resourceModel->getResourceByNameEn($resEn));
            if ($row === null || !isset($row['id']) || !is_numeric($row['id'])) {
                $missing[$resEn] = ['need' => (int) $need, 'have' => 0, 'name' => $resEn . ' (нет в DB)', 'id' => 0];
                continue;
            }
            $charRes = $this->rowToArr($this->characterResourceModel
                ->where('id_characters', $charId)
                ->where('id_resources', (int) $row['id'])
                ->first());
            $have = 0;
            if ($charRes !== null && isset($charRes['quantity']) && is_numeric($charRes['quantity'])) {
                $have = (int) $charRes['quantity'];
            }
            if ($have < $need) {
                $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : $resEn;
                $missing[$resEn] = ['need' => (int) $need, 'have' => $have, 'name' => $name, 'id' => (int) $row['id']];
            }
        }
        return $missing;
    }

    /**
     * @param array<string,int> $reqs name_eng крафта → нужное количество
     * @return array<string,array{need:int,have:int,name:string}>
     */
    private function checkCraftedItems(int $charId, array $reqs): array
    {
        $missing = [];
        foreach ($reqs as $itemEn => $need) {
            $item = $this->rowToArr($this->craftedItemsModel->getRowByName($itemEn));
            if ($item === null || !isset($item['id']) || !is_numeric($item['id'])) {
                $missing[$itemEn] = ['need' => (int) $need, 'have' => 0, 'name' => $itemEn . ' (нет в DB)'];
                continue;
            }
            $log = $this->rowToArr($this->craftedItemsLogModel
                ->where('character_id', $charId)
                ->where('crafted_item_id', (int) $item['id'])
                ->first());
            $have = 0;
            if ($log !== null && isset($log['quantity']) && is_numeric($log['quantity'])) {
                $have = (int) $log['quantity'];
            }
            if ($have < $need) {
                $nameRus = isset($item['name_rus']) && is_string($item['name_rus']) ? $item['name_rus'] : $itemEn;
                $missing[$itemEn] = ['need' => (int) $need, 'have' => $have, 'name' => $nameRus];
            }
        }
        return $missing;
    }

    /**
     * @param array<string,int> $reqs name_en → нужное количество
     */
    private function formatResourcesText(int $charId, array $reqs): string
    {
        $out = '';
        foreach ($reqs as $resEn => $need) {
            $row = $this->rowToArr($this->resourceModel->getResourceByNameEn($resEn));
            if ($row === null || !isset($row['id']) || !is_numeric($row['id'])) {
                $out .= "- {$resEn} (нет в DB) - {$need}\n";
                continue;
            }
            $charRes = $this->rowToArr($this->characterResourceModel
                ->where('id_characters', $charId)
                ->where('id_resources', (int) $row['id'])
                ->first());
            $have = 0;
            if ($charRes !== null && isset($charRes['quantity']) && is_numeric($charRes['quantity'])) {
                $have = (int) $charRes['quantity'];
            }
            $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : $resEn;
            $icon = ResourceIconHelper::for($name);
            $out .= "{$icon} {$name} - {$need} ед. (в наличии {$have} ед.)\n";
        }
        return $out;
    }

    /**
     * @param array<string,int> $reqs name_eng → нужное количество
     */
    private function formatCraftedItemsText(int $charId, array $reqs): string
    {
        $out = '';
        foreach ($reqs as $itemEn => $need) {
            $item = $this->rowToArr($this->craftedItemsModel->getRowByName($itemEn));
            if ($item === null || !isset($item['id']) || !is_numeric($item['id'])) {
                $out .= "- {$itemEn} (нет в DB) - {$need}\n";
                continue;
            }
            $log = $this->rowToArr($this->craftedItemsLogModel
                ->where('character_id', $charId)
                ->where('crafted_item_id', (int) $item['id'])
                ->first());
            $have = 0;
            if ($log !== null && isset($log['quantity']) && is_numeric($log['quantity'])) {
                $have = (int) $log['quantity'];
            }
            $nameRus = isset($item['name_rus']) && is_string($item['name_rus']) ? $item['name_rus'] : $itemEn;
            $icon    = ResourceIconHelper::for($nameRus);
            $out .= "{$icon} {$nameRus} - {$need} ед. (в наличии {$have} ед.)\n";
        }
        return $out;
    }

    /**
     * @param array<string,array{need:int,have:int,name:string}> $missing
     */
    private function formatMissing(array $missing): string
    {
        $lines = [];
        foreach ($missing as $info) {
            $lines[] = "- {$info['name']}: требуется {$info['need']}, в наличии {$info['have']}";
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $character
     */
    /**
     * ADR-160: расчёт вынесен в {@see \App\Services\Buildings\BuildDurationService} —
     * ту же точку, которой считается сама стройка (`GenericBuildingAction`). Раньше это
     * была вторая копия формулы, и она уже разъехалась с первой: статы урезались до
     * целых (`(int) 0.16 → 0`), тогда как реальная стройка считает по дробным.
     * Расхождение было в доли минуты (граница округления), так что игроку это почти
     * никогда не врало — но теперь обещание «Строительство займёт ~N минут» равно факту
     * по построению, а не по совпадению.
     *
     * @param array<string, mixed> $character
     */
    private function estimateDuration(array $character, string $taskName): ?int
    {
        $taskRow = $this->rowToArr($this->taskModel->where('name', $taskName)->first());

        return (new \App\Services\Buildings\BuildDurationService())->minutes($character, $taskRow);
    }

    /**
     * ADR-162: строка правды о выигрыше от статов — « (новичку — 74 мин)».
     *
     * До калибровки потолка её не было смысла писать: при потолке 2000 выигрыш был нулевым
     * у всех. Теперь статы реально сокращают стройку, а невидимый бонус читается как
     * поломка (memory `feedback_invisible_multiplier_bonus_reads_as_broken`) — игрок должен
     * видеть, что время личное, а не общее.
     *
     * Пустая строка, когда сравнивать не с чем: выигрыша нет (новичок), задачи нет, или у
     * задачи нет верхней границы. «−0%» не пишем — тот же принцип, что в крафте (ADR-158).
     *
     * @param array<string, mixed> $character
     */
    private function durationBonusNote(array $character, string $taskName, ?int $minutes): string
    {
        if ($minutes === null) {
            return '';
        }

        $taskRow = $this->rowToArr($this->taskModel->where('name', $taskName)->first());
        $maxRaw  = $taskRow['max_duration'] ?? null;
        $max     = is_numeric($maxRaw) ? (int) $maxRaw : 0;

        if ($max <= 0 || $minutes >= $max) {
            return '';
        }

        return " _(новичку — {$max} мин; ускоряют опыт, ловкость и интеллект)_";
    }

    private function sendError(string $text): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Workbench;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterResourceModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\GameSettings\GameSettingsService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * S16 (v0.51.198) — entrance screen для Tier 3 Professional Workbench (ADR-026).
 *
 * Аналог `WorkbenchOneAction` (T1), но с расширенными gate'ами:
 *   - Уровень персонажа: GameSettings `tier3.workbench.character_level_required` (default=20).
 *   - Здания: BlastFurnace L3 + Laboratory L3.
 *   - База (claimed_cells).
 *   - Ресурсы: Редкие металлы ×30, Доколлапсная электроника ×3, Промышленный пластик ×5.
 *   - Крафтовые компоненты: wiring ×20.
 *   - Золото: 50000.
 *
 * При выполнении всех условий — кнопка «🛠️ Крафтить» → `genericCraft_ProfessionalWorkbench_1`
 * → `GenericCraftActionStart` обрабатывает (включая повторную валидацию).
 *
 * Длительность крафта — 8h (live-tunable через `tier3.workbench.craft_duration_hours`).
 */
class WorkbenchProfessionalAction extends BaseAction
{
    protected CharacterResourceModel $characterResourceModel;
    protected BuildingModel $buildingModel;
    protected CharacterBuildingModel $characterBuildingModel;
    protected ClaimedCellModel $claimedCellModel;
    protected CraftedItemsLogModel $craftedItemsLogModel;
    protected CraftedItemsModel $craftedItemsModel;
    protected GameSettingsService $gameSettings;

    /**
     * @param \Longman\TelegramBot\Entities\CallbackQuery $callbackQuery
     */
    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->gameSettings           = new GameSettingsService();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId,
        )) {
            return Request::emptyResponse();
        }

        $characterId = (int) $character['id'];

        // S17 (v0.51.199): если у чара уже есть ProfessionalWorkbench в инвентаре —
        // показываем craft-menu T3 (Оружие/Броня/Медицина/Утилиты), а не gate screen
        // для крафта самого верстака. Это превращает workbenchProfessional callback
        // в two-mode dispatcher: pre-build → requirements / post-build → craft menu.
        $existingWorkbench = $this->craftedItemsModel->where('name_eng', 'ProfessionalWorkbench')->first();
        if (is_array($existingWorkbench) && isset($existingWorkbench['id'])) {
            $log = $this->craftedItemsLogModel
                ->where('character_id', $characterId)
                ->where('crafted_item_id', $existingWorkbench['id'])
                ->first();
            $haveQtyRaw = is_array($log) && isset($log['quantity']) ? $log['quantity'] : 0;
            $haveQty    = is_numeric($haveQtyRaw) ? (int) $haveQtyRaw : 0;
            if ($haveQty > 0) {
                return $this->renderCraftMenu($chatId);
            }
        }

        // Live-tunable gates.
        $levelRequired = (int) $this->gameSettings->get('tier3.workbench.character_level_required', 20);
        $durationHours = (int) $this->gameSettings->get('tier3.workbench.craft_duration_hours', 8);

        $requiredResources = [
            'Редкие металлы'           => 30,
            'Доколлапсная электроника' => 3,
            'Промышленный пластик'     => 5,
        ];
        $requiredComponents = [
            'wiring' => 20,
        ];
        $requiredGold = 50000;
        $requiredBuildingLevels = [
            'BlastFurnace' => 3,
            'Laboratory'   => 3,
        ];

        $hasBase = (bool) $this->claimedCellModel->where('character_id', $characterId)->first();

        $text = "*🛠️ Профессиональный верстак (Tier 3)*\n\n"
            . "_Тяжёлый ручной верстак с парными тисками, маленьким горном и стеной для инструментов. "
            . "Открывает крафт Tier 3 — продвинутое оружие, броню, медицину и утилиты._\n\n"
            . "*Требования:*\n";

        $hasAll = true;

        // Уровень.
        $charLevel = (int) ($character['level'] ?? 0);
        $marker = $charLevel >= $levelRequired ? '✅' : '❌';
        if ($charLevel < $levelRequired) {
            $hasAll = false;
        }
        $text .= "{$marker} 👤 Уровень — {$charLevel} / {$levelRequired}\n";

        // База.
        $marker = $hasBase ? '✅' : '❌';
        if (!$hasBase) {
            $hasAll = false;
        }
        $text .= "{$marker} 🏚️ База (лагерь)\n";

        // Здания.
        foreach ($requiredBuildingLevels as $buildingNameEn => $needLevel) {
            $building = $this->buildingModel->where('name_en', $buildingNameEn)->first();
            $rusRaw = is_array($building) && isset($building['name_rus']) ? $building['name_rus'] : null;
            $rusName = is_string($rusRaw) && $rusRaw !== '' ? $rusRaw : $buildingNameEn;
            $haveBuilding = null;
            if (is_array($building) && isset($building['id'])) {
                $haveBuilding = $this->characterBuildingModel
                    ->where('character_id', $characterId)
                    ->where('building_id', $building['id'])
                    ->first();
            }
            $haveLevelRaw = is_array($haveBuilding) && isset($haveBuilding['level']) ? $haveBuilding['level'] : 0;
            $haveLevel    = is_numeric($haveLevelRaw) ? (int) $haveLevelRaw : 0;
            $marker = $haveLevel >= $needLevel ? '✅' : '❌';
            if ($haveLevel < $needLevel) {
                $hasAll = false;
            }
            $text .= "{$marker} 🏗 {$rusName} — L{$haveLevel} / L{$needLevel}\n";
        }

        $text .= "\n*Ресурсы:*\n";
        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $charResource = $this->characterResourceModel
                ->getResourceByNameAndCharacterId($resourceName, $characterId);
            $availableAmount = $charResource ? (int) $charResource['quantity'] : 0;
            $marker = $availableAmount >= $requiredAmount ? '✅' : '❌';
            if ($availableAmount < $requiredAmount) {
                $hasAll = false;
            }
            $icon = \App\Helpers\ResourceIconHelper::for($resourceName);
            $text .= "{$marker} {$icon} {$resourceName} — {$availableAmount} / {$requiredAmount}\n";
        }

        $text .= "\n*Компоненты:*\n";
        foreach ($requiredComponents as $componentNameEng => $requiredAmount) {
            $craftedItem = $this->craftedItemsModel->where('name_eng', $componentNameEng)->first();
            $availableAmount = 0;
            if (is_array($craftedItem) && isset($craftedItem['id'])) {
                $log = $this->craftedItemsLogModel
                    ->where('crafted_item_id', $craftedItem['id'])
                    ->where('character_id', $characterId)
                    ->first();
                $qtyRaw = is_array($log) && isset($log['quantity']) ? $log['quantity'] : 0;
                $availableAmount = is_numeric($qtyRaw) ? (int) $qtyRaw : 0;
            }
            $compRusRaw = is_array($craftedItem) && isset($craftedItem['name_rus']) ? $craftedItem['name_rus'] : null;
            $rusName = is_string($compRusRaw) && $compRusRaw !== '' ? $compRusRaw : $componentNameEng;
            $marker = $availableAmount >= $requiredAmount ? '✅' : '❌';
            if ($availableAmount < $requiredAmount) {
                $hasAll = false;
            }
            $text .= "{$marker} 🔩 {$rusName} — {$availableAmount} / {$requiredAmount}\n";
        }

        // Золото.
        $charGold = (int) ($character['gold'] ?? 0);
        $marker = $charGold >= $requiredGold ? '✅' : '❌';
        if ($charGold < $requiredGold) {
            $hasAll = false;
        }
        $text .= "{$marker} 💰 Золото — {$charGold} / {$requiredGold}\n";

        if ($hasAll) {
            $text .= "\n⏱ Крафт займёт *{$durationHours} часов*.\n";
        } else {
            $text .= "\n__Подкопи недостающее и возвращайся.__\n";
        }

        $inlineKeyboard = $hasAll
            ? [
                [['text' => '🛠️ Крафтить', 'callback_data' => 'genericCraft_ProfessionalWorkbench_1']],
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь',   'callback_data' => 'inventory'],
                ],
            ]
            : [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь',   'callback_data' => 'inventory'],
                ],
                [
                    ['text' => '💰 Продать', 'callback_data' => 'sell'],
                    ['text' => '🛍️ Купить',  'callback_data' => 'buy'],
                ],
            ];

        $imagePath = base_url('uploads/telegram/craft/professional_workbench.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ]);
    }

    /**
     * S17 (v0.51.199) — meню T3 крафта после того как чар построил верстак.
     * Оружие T3 живое (S17), остальные категории (Armor/Medical/Utility) ждут S18-S20.
     */
    private function renderCraftMenu(int $chatId): ServerResponse
    {
        $text = "*🛠️ Профессиональный верстак (T3) — Готов*\n\n"
            . "Тяжёлый верстак собран, тиски разведены, паяльник греется. "
            . "Выбирай раздел для крафта.\n\n"
            . "⚔️ *Оружие* — 5 рецептов T3 (S17)\n"
            . "🛡 *Броня* — 4 рецепта T3 (S18)\n"
            . "💊 *Медицина* — 3 рецепта T3 (S19)\n"
            . "🔧 *Утилиты* — 3 рецепта T3 (S20)\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⚔️ Оружие T3', 'callback_data' => 'craftWeaponsT3Select'],
                    ['text' => '🛡 Броня T3',   'callback_data' => 'craftArmorT3Select'],
                ],
                [
                    ['text' => '💊 Медицина T3', 'callback_data' => 'craftMedicalT3Select'],
                    ['text' => '🔧 Утилиты T3',  'callback_data' => 'craftUtilityT3Select'],
                ],
                [
                    ['text' => '🎖 Фракционное оружие', 'callback_data' => 'craftFactionWeaponsSelect'],
                ],
                [
                    ['text' => '📋 Очередь крафта', 'callback_data' => 'craftQueue'],
                ],
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
                ],
            ],
        ];

        $imagePath = base_url('uploads/telegram/craft/professional_workbench.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}

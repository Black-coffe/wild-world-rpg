<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Craft\CraftCardHelper;
use App\Services\GameSettings\GameSettingsService;
use Config\CraftRecipes;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * S20 (v0.51.202) — generic preview screen для T3 utility (инструментов).
 *
 * Callback prefix: `craftPreviewT3Utility_<RecipeKey>` (CallbackRoutes.prefixRoutes).
 * Зеркало Medical/Armor preview, но показывает прочность инструмента (кол-во
 * применений) + gather-бонус (из GameSettings tools.<snake>.gather_bonus для
 * Sapper/Golden; статичный для DiamondPickaxe — он в legacy ToolManager map).
 */
class UtilityRecipePreviewT3Action extends BaseAction
{
    private CharacterResourceModel $characterResourceModel;
    private CraftedItemsModel      $craftedItemsModel;
    private CraftedItemsLogModel   $craftedItemsLogModel;
    private GameSettingsService    $gameSettings;

    /** Статичные описания добычи (resource-set из ToolManager). */
    private const BOOST_DESC = [
        'DiamondPickaxe' => 'премиум добыча: камень/руда/глина/лёд (до +220%)',
        'Sapper Shovel'  => 'копаемые: глина/песок/ил/травы/мхи/снег',
        'Golden Hoe'     => 'фермерские: овощи/травы/мхи/фрукты',
    ];

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->gameSettings           = new GameSettingsService();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден или у него нет персонажа.',
            ]);
        }

        $recipeKey = $this->extractRecipeKey();
        if ($recipeKey === '') {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Не удалось определить рецепт.']);
        }

        /** @var CraftRecipes $cfg */
        $cfg    = config('CraftRecipes');
        $recipe = $cfg->get($recipeKey);
        if ($recipe === null) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "Рецепт *{$recipeKey}* не найден в конфигурации.",
                'parse_mode' => 'Markdown',
            ]);
        }

        $characterId = (int) $character['id'];

        $itemNameEngRaw = $recipe['item_name_eng'] ?? '';
        $itemNameEng    = is_string($itemNameEngRaw) ? $itemNameEngRaw : '';
        $craftedItem = $itemNameEng !== ''
            ? $this->craftedItemsModel->where('name_eng', $itemNameEng)->first()
            : null;

        $insufficient = [];

        $needLevel  = $this->intFromMixed($recipe['required_level'] ?? 0);
        $charLevel  = $this->intFromMixed($character['level'] ?? 0);
        if ($needLevel > 0 && $charLevel < $needLevel) {
            $insufficient[] = "уровень: нужно {$needLevel}, у вас {$charLevel}";
        }

        $goldNeed = $this->intFromMixed($recipe['gold_required'] ?? 0);
        $goldHave = $this->intFromMixed($character['gold'] ?? 0);
        if ($goldHave < $goldNeed) {
            $insufficient[] = "золото: нужно {$goldNeed}, у вас {$goldHave}";
        }

        // Gate via required_crafted_items.
        $gateRaw = $recipe['required_crafted_items'] ?? [];
        $gate = is_array($gateRaw) ? $gateRaw : [];
        foreach ($gate as $itemEn => $qtyNeedRaw) {
            if (!is_string($itemEn)) {
                continue;
            }
            $qtyNeed = $this->intFromMixed($qtyNeedRaw);
            $item = $this->craftedItemsModel->where('name_eng', $itemEn)->first();
            if (!is_array($item) || !isset($item['id'])) {
                $insufficient[] = "{$itemEn} (не найден в БД)";
                continue;
            }
            $log = $this->craftedItemsLogModel
                ->where('character_id', $characterId)
                ->where('crafted_item_id', $item['id'])
                ->first();
            $have = is_array($log) ? $this->intFromMixed($log['quantity'] ?? 0) : 0;
            if ($have < $qtyNeed) {
                $nameRusRaw = $item['name_rus'] ?? null;
                $nameRus = is_string($nameRusRaw) && $nameRusRaw !== '' ? $nameRusRaw : $itemEn;
                $insufficient[] = "{$nameRus}: нужно {$qtyNeed}, есть {$have}";
            }
        }

        // Gold (F3.B8 в GenericCraftActionStart умножает gold_required на quantity — у T3
        // ценник 5000-6000/шт, в отличие от обычных карточек без золота вовсе, поэтому
        // ступень, недоступную по золоту, показывать нельзя).
        $maxAffordable = PHP_INT_MAX;
        if ($goldNeed > 0) {
            $maxAffordable = (int) floor($goldHave / $goldNeed);
        }

        // Resources.

        $resourcesRaw = $recipe['resources'] ?? [];
        $resources = is_array($resourcesRaw) ? $resourcesRaw : [];
        $resourceLines = [];
        foreach ($resources as $resName => $qtyNeedRaw) {
            $resNameStr = is_string($resName) ? $resName : (string) $resName;
            $qtyNeed = $this->intFromMixed($qtyNeedRaw);
            $charRes = $this->characterResourceModel->getResourceByNameAndCharacterId($resNameStr, $characterId);
            $have    = is_array($charRes) ? $this->intFromMixed($charRes['quantity'] ?? 0) : 0;
            $marker  = $have >= $qtyNeed ? '✅' : '❌';
            if ($have < $qtyNeed) {
                $insufficient[] = "{$resNameStr}: нужно {$qtyNeed}, есть {$have}";
            }
            if ($qtyNeed > 0) {
                $maxAffordable = min($maxAffordable, (int) floor($have / $qtyNeed));
            }
            $resourceLines[] = "{$marker} {$resNameStr} — {$have} / {$qtyNeed}";
        }

        // Components.
        $componentsRaw = $recipe['crafted_items'] ?? [];
        $components = is_array($componentsRaw) ? $componentsRaw : [];
        $componentLines = [];
        foreach ($components as $itemEn => $qtyNeedRaw) {
            $itemEnStr = is_string($itemEn) ? $itemEn : (string) $itemEn;
            $qtyNeed = $this->intFromMixed($qtyNeedRaw);
            $item = $this->craftedItemsModel->where('name_eng', $itemEnStr)->first();
            $have = 0;
            $rusName = $itemEnStr;
            if (is_array($item) && isset($item['id'])) {
                $log = $this->craftedItemsLogModel
                    ->where('character_id', $characterId)
                    ->where('crafted_item_id', $item['id'])
                    ->first();
                $have = is_array($log) ? $this->intFromMixed($log['quantity'] ?? 0) : 0;
                $rusRaw = $item['name_rus'] ?? null;
                $rusName = is_string($rusRaw) && $rusRaw !== '' ? $rusRaw : $itemEnStr;
            }
            $marker  = $have >= $qtyNeed ? '✅' : '❌';
            if ($have < $qtyNeed) {
                $insufficient[] = "{$rusName}: нужно {$qtyNeed}, есть {$have}";
            }
            if ($qtyNeed > 0) {
                $maxAffordable = min($maxAffordable, (int) floor($have / $qtyNeed));
            }
            $componentLines[] = "{$marker} {$rusName} — {$have} / {$qtyNeed}";
        }
        if ($maxAffordable === PHP_INT_MAX) {
            $maxAffordable = 0;
        }

        // Tool stats: durability (uses) + gather boost.
        $usesRaw = is_array($craftedItem) ? ($craftedItem['durability_count'] ?? 0) : 0;
        $uses    = $this->intFromMixed($usesRaw);
        $boostLine = $this->buildBoostLine($itemNameEng);

        // ADR-143: реальная занятость задачи крафта (pre-commit предупреждение).
        $scope       = new \App\Services\Tasks\ActionScopeService();
        $taskModel   = new \App\Models\TaskModel();
        $taskNameRaw = $recipe['task_name'] ?? '';
        $taskNameStr = is_string($taskNameRaw) ? $taskNameRaw : '';
        $taskRow     = $taskNameStr !== '' ? $taskModel->where('name', $taskNameStr)->first() : null;
        $bg          = $scope->isBackground(is_array($taskRow) ? ($taskRow['parallel_execution_allowed'] ?? 0) : 0);

        // Caption.
        $itemNameRusRaw = $recipe['item_name_rus'] ?? $recipeKey;
        $itemNameRus    = is_string($itemNameRusRaw) ? $itemNameRusRaw : $recipeKey;
        $iconRaw        = $recipe['icon_emoji'] ?? '🔧';
        $icon           = is_string($iconRaw) ? $iconRaw : '🔧';

        $caption = "{$icon} *{$itemNameRus}* (T3)\n";
        if ($boostLine !== '') {
            $caption .= "_⛏ Добыча: {$boostLine}_\n";
        }
        if ($uses > 0) {
            $caption .= "_Прочность: {$uses} применений_\n";
        }
        $caption .= "\n" . $scope->scopeLine(\App\Services\Tasks\ActionScopeService::KIND_CRAFT, $bg) . "\n";
        $caption .= "\n*Уровень:* L{$needLevel} (у вас L{$charLevel})\n";
        $caption .= "*Золото:* {$goldNeed} / {$goldHave}\n";

        if (!empty($gate)) {
            $caption .= "\n*Требуется в инвентаре:*\n";
            foreach ($gate as $itemEn => $qtyNeed) {
                if (!is_string($itemEn)) {
                    continue;
                }
                $qtyNeedInt = $this->intFromMixed($qtyNeed);
                $item = $this->craftedItemsModel->where('name_eng', $itemEn)->first();
                $nameRusRaw = is_array($item) ? ($item['name_rus'] ?? null) : null;
                $rusName = is_string($nameRusRaw) && $nameRusRaw !== '' ? $nameRusRaw : $itemEn;
                $caption .= "🛠 {$rusName} × {$qtyNeedInt}\n";
            }
        }

        if (!empty($resourceLines)) {
            $caption .= "\n*Ресурсы (за 1 шт.):*\n" . implode("\n", $resourceLines) . "\n";
        }
        if (!empty($componentLines)) {
            $caption .= "\n*Компоненты (за 1 шт.):*\n" . implode("\n", $componentLines) . "\n";
        }

        $canCraft = empty($insufficient);
        if (!$canCraft) {
            $caption .= "\n_Не хватает: " . implode('; ', array_slice($insufficient, 0, 6)) . "._\n";
        } else {
            $caption .= "\n✅ Можно крафтить.\n";
        }

        $rows = [];
        if ($canCraft) {
            $rows = (new CraftCardHelper())->quantityRows($recipeKey, $maxAffordable);
        }
        $rows[] = [
            ['text' => '⬅️ Назад к утилитам', 'callback_data' => 'craftUtilityT3Select'],
            ['text' => '🎒 Инвентарь',         'callback_data' => 'inventory'],
        ];

        $imageRel = $recipe['image_in_progress'] ?? 'uploads/telegram/craft/professional_workbench.jpg';
        $imageRel = is_string($imageRel) ? $imageRel : 'uploads/telegram/craft/professional_workbench.jpg';
        $absolutePath = FCPATH . $imageRel;
        if (!is_file($absolutePath)) {
            $imageRel = 'uploads/telegram/craft/professional_workbench.jpg';
        }

        $imagePath = base_url($imageRel);
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $caption,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }

    /**
     * Описание добычи: статичное (resource-set) + admin-tunable бонус из
     * GameSettings (tools.<snake>.gather_bonus) для Sapper/Golden.
     */
    private function buildBoostLine(string $itemNameEng): string
    {
        $desc = self::BOOST_DESC[$itemNameEng] ?? '';
        $snake = $this->toSnakeCase($itemNameEng);
        $bonus = $this->gameSettings->get("tools.{$snake}.gather_bonus", null);
        if (is_numeric($bonus)) {
            $pct = (int) round(((float) $bonus) * 100);
            return $desc !== '' ? "+{$pct}% — {$desc}" : "+{$pct}%";
        }
        return $desc;
    }

    private function extractRecipeKey(): string
    {
        $data   = (string) $this->callbackQuery->getData();
        $prefix = 'craftPreviewT3Utility_';
        if (!str_starts_with($data, $prefix)) {
            return '';
        }
        return substr($data, strlen($prefix));
    }

    private function toSnakeCase(string $name): string
    {
        $s = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name) ?? $name;
        $s = str_replace([' ', '-'], '_', $s);
        $s = preg_replace('/_+/', '_', $s) ?? $s;
        return strtolower(trim($s, '_'));
    }

    private function intFromMixed(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}

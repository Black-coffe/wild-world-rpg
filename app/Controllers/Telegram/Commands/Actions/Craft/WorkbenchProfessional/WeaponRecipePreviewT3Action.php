<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\WeaponModel;
use Config\CraftRecipes;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * S17 (v0.51.199) — generic preview screen для T3 weapon крафта.
 *
 * Callback prefix: `craftPreviewT3_<RecipeKey>` (см. CallbackRoutes.prefixRoutes).
 * Recipe key извлекается из callback suffix → lookup в Config\CraftRecipes →
 * рендер preview: weapon stats + recipe requirements + кнопка «🛠 Скрафтить».
 *
 * **Один класс на 5 weapons (S17) — DRY-pattern**, без 5 boilerplate Action'ов
 * (legacy WorkbenchStandard pattern с дублированием ресурсов в action layer).
 * CraftRecipes — single source of truth для всех 5 recipes.
 *
 * Reuse: pattern переиспользуется для S18 (T3 armor — другой prefix),
 * S19 (medical), S20 (utility) — каждый со своим callback prefix.
 */
class WeaponRecipePreviewT3Action extends BaseAction
{
    private CharacterResourceModel $characterResourceModel;
    private CraftedItemsModel      $craftedItemsModel;
    private CraftedItemsLogModel   $craftedItemsLogModel;
    private WeaponModel            $weaponModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->weaponModel            = new WeaponModel();
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
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Не удалось определить рецепт.',
            ]);
        }

        /** @var CraftRecipes $cfg */
        $cfg    = config('CraftRecipes');
        $recipe = $cfg->get($recipeKey);
        if ($recipe === null) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Рецепт *{$recipeKey}* не найден в конфигурации.",
                'parse_mode' => 'Markdown',
            ]);
        }

        $characterId = (int) $character['id'];

        // Weapon data — для damage/range preview.
        $weaponNameEnRaw = $recipe['weapon_name_en'] ?? '';
        $weaponNameEn    = is_string($weaponNameEnRaw) ? $weaponNameEnRaw : '';
        $weapon = $weaponNameEn !== ''
            ? $this->weaponModel->where('name_en', $weaponNameEn)->first()
            : null;

        // Build availability summary.
        $insufficient = [];

        $needLevel  = $this->intFromMixed($recipe['required_level'] ?? 0);
        $charLevel  = $this->intFromMixed($character['level'] ?? 0);
        if ($needLevel > 0 && $charLevel < $needLevel) {
            $insufficient[] = "уровень: нужно {$needLevel}, у вас {$charLevel}";
        }
        $needStr   = $this->intFromMixed($recipe['required_strength'] ?? 0);
        $charStr   = $this->intFromMixed($character['strength'] ?? 0);
        if ($needStr > 0 && $charStr < $needStr) {
            $insufficient[] = "сила: нужно {$needStr}, у вас {$charStr}";
        }

        $goldNeed   = $this->intFromMixed($recipe['gold_required'] ?? 0);
        $goldHave   = $this->intFromMixed($character['gold'] ?? 0);
        if ($goldHave < $goldNeed) {
            $insufficient[] = "золото: нужно {$goldNeed}, у вас {$goldHave}";
        }

        // Required workbench (gate via required_crafted_items).
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
            $resourceLines[] = "{$marker} {$resNameStr} — {$have} / {$qtyNeed}";
        }

        // Crafted items (consumable).
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
            $componentLines[] = "{$marker} {$rusName} — {$have} / {$qtyNeed}";
        }

        // Build caption.
        $itemNameRusRaw = $recipe['item_name_rus'] ?? $recipeKey;
        $itemNameRus    = is_string($itemNameRusRaw) ? $itemNameRusRaw : $recipeKey;
        $iconRaw        = $recipe['icon_emoji'] ?? '⚔️';
        $icon           = is_string($iconRaw) ? $iconRaw : '⚔️';

        $caption = "{$icon} *{$itemNameRus}* (T3)\n";
        if (is_array($weapon)) {
            $rarityRaw = $weapon['rarity'] ?? 'Common';
            $rarity    = is_string($rarityRaw) ? $rarityRaw : 'Common';
            $dmgRaw    = $weapon['damage_value'] ?? 0;
            $damageNum = is_numeric($dmgRaw) ? (float) $dmgRaw : 0.0;
            $damage    = number_format($damageNum, 1, '.', ' ');
            $dtypeRaw  = $weapon['damage_type'] ?? 'Physical';
            $dtype     = is_string($dtypeRaw) ? $dtypeRaw : 'Physical';
            $caption .= "_Редкость: {$rarity} | Урон: {$damage} ({$dtype})_\n";
        }
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
            $rows[] = [['text' => '🛠 Скрафтить 1 шт', 'callback_data' => 'genericCraft_' . $recipeKey . '_1']];
        }
        $rows[] = [
            ['text' => '⬅️ Назад к оружию', 'callback_data' => 'craftWeaponsT3Select'],
            ['text' => '🎒 Инвентарь',       'callback_data' => 'inventory'],
        ];

        // Image: recipe-specific, fallback на verstack.
        $imageRel = $recipe['image_in_progress'] ?? 'uploads/telegram/craft/professional_workbench.jpg';
        $imageRel = is_string($imageRel) ? $imageRel : 'uploads/telegram/craft/professional_workbench.jpg';

        // Если файл не существует — fallback на default.
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
     * Callback formatting: `craftPreviewT3_<RecipeKey>` → '<RecipeKey>'.
     */
    private function extractRecipeKey(): string
    {
        $data  = (string) $this->callbackQuery->getData();
        $prefix = 'craftPreviewT3_';
        if (!str_starts_with($data, $prefix)) {
            return '';
        }
        return substr($data, strlen($prefix));
    }

    /**
     * Safe int cast for recipe array values (mixed) — guard via is_numeric().
     */
    private function intFromMixed(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}

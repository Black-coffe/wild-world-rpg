<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\OutfitModel;
use Config\CraftRecipes;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * S18 (v0.51.200) — generic preview screen для T3 armor крафта.
 *
 * Callback prefix: `craftPreviewT3Armor_<RecipeKey>` (см. CallbackRoutes.prefixRoutes).
 * Recipe key извлекается из callback suffix → lookup в Config\CraftRecipes →
 * рендер preview: outfit stats + recipe requirements + кнопка «🛠 Скрафтить».
 *
 * Зеркало `WeaponRecipePreviewT3Action` (S17) — но lookup в OutfitModel
 * вместо WeaponModel. CraftRecipes — single source of truth для всех 4 recipes.
 */
class ArmorRecipePreviewT3Action extends BaseAction
{
    private CharacterResourceModel $characterResourceModel;
    private CraftedItemsModel      $craftedItemsModel;
    private CraftedItemsLogModel   $craftedItemsLogModel;
    private OutfitModel            $outfitModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->outfitModel            = new OutfitModel();
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

        // Outfit data — для armor/weight preview.
        $outfitNameEnRaw = $recipe['outfit_name_en'] ?? '';
        $outfitNameEn    = is_string($outfitNameEnRaw) ? $outfitNameEnRaw : '';
        $outfit = $outfitNameEn !== ''
            ? $this->outfitModel->where('name_en', $outfitNameEn)->first()
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

        // V14 (ADR-046): faction/quest gate для faction-unique armor (зеркало weapon-preview).
        $gateNotes = [];
        $reqQuest  = isset($recipe['required_quest']) && is_string($recipe['required_quest']) ? $recipe['required_quest'] : '';
        if ($reqQuest !== '') {
            $db    = \Config\Database::connect();
            $query = $db->table('quest_steps qs')
                ->join('quests q', 'q.id = qs.quest_id')
                ->where('q.title_en', $reqQuest)
                ->where('qs.character_id', $characterId)
                ->where('qs.is_completed', 1)
                ->get();
            $done = $query !== false && $query->getFirstRow('array') !== null;
            $gateNotes[] = ($done ? '✅' : '🔒') . ' захват стратегического объекта';
            if (!$done) {
                $insufficient[] = 'не захвачен стратегический объект';
            }
        }
        $reqFaction = isset($recipe['required_faction']) && is_numeric($recipe['required_faction']) ? (int) $recipe['required_faction'] : 0;
        if ($reqFaction > 0) {
            $db    = \Config\Database::connect();
            $fq    = $db->table('character_factions')->where('character_id', $characterId)->get();
            $frow  = $fq !== false ? $fq->getFirstRow('array') : null;
            $charFaction  = is_array($frow) && isset($frow['faction_id']) && is_numeric($frow['faction_id']) ? (int) $frow['faction_id'] : 0;
            $factionNames = [1 => 'Военные', 2 => 'Партизаны', 3 => 'Инженеры', 4 => 'Фермеры'];
            $need = $factionNames[$reqFaction] ?? ('#' . $reqFaction);
            $ok   = $charFaction === $reqFaction;
            $gateNotes[] = ($ok ? '✅' : '🔒') . " фракция: {$need}";
            if (!$ok) {
                $insufficient[] = "только фракция {$need}";
            }
        }

        // Build caption.
        $itemNameRusRaw = $recipe['item_name_rus'] ?? $recipeKey;
        $itemNameRus    = is_string($itemNameRusRaw) ? $itemNameRusRaw : $recipeKey;
        $iconRaw        = $recipe['icon_emoji'] ?? '🛡';
        $icon           = is_string($iconRaw) ? $iconRaw : '🛡';

        $caption = "{$icon} *{$itemNameRus}* (T3)\n";
        if (is_array($outfit)) {
            $rarityRaw = $outfit['rarity'] ?? 'Common';
            $rarity    = is_string($rarityRaw) ? $rarityRaw : 'Common';
            $armorRaw  = $outfit['armor_value'] ?? 0;
            $armorNum  = is_numeric($armorRaw) ? (float) $armorRaw : 0.0;
            $armor     = number_format($armorNum, 0, '.', ' ');
            $weightRaw = $outfit['weight'] ?? 0;
            $weightNum = is_numeric($weightRaw) ? (float) $weightRaw : 0.0;
            $weight    = number_format($weightNum, 1, '.', ' ');
            $caption .= "_Редкость: {$rarity} | Броня: {$armor} | Вес: {$weight}_\n";

            // V15 (честный closer): реальные ненулевые сопротивления/модификаторы
            // + честная сноска про PvP — чтобы решение крафтить было информированным.
            $resLines = \App\Services\Display\OutfitDisplayHelper::resistanceLines($outfit);
            if (! empty($resLines)) {
                $caption .= "\n*Спец-свойства:*\n" . implode("\n", $resLines) . "\n";
                $caption .= \App\Services\Display\OutfitDisplayHelper::PVP_NOTE . "\n";
            }
        }
        $caption .= "\n*Уровень:* L{$needLevel} (у вас L{$charLevel})\n";
        $caption .= "*Золото:* {$goldNeed} / {$goldHave}\n";

        if (!empty($gateNotes)) {
            $caption .= "\n*Фракционный доступ:*\n" . implode("\n", $gateNotes) . "\n";
        }

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
        // V14: back-кнопка зависит от рецепта (faction armor → faction-меню).
        $backCbRaw = $recipe['back_callback'] ?? 'craftArmorT3Select';
        $backCb    = is_string($backCbRaw) && $backCbRaw !== '' ? $backCbRaw : 'craftArmorT3Select';
        $rows[] = [
            ['text' => '⬅️ Назад', 'callback_data' => $backCb],
            ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
        ];

        // Image: recipe-specific, fallback на verstack.
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
     * Callback formatting: `craftPreviewT3Armor_<RecipeKey>` → '<RecipeKey>'.
     */
    private function extractRecipeKey(): string
    {
        $data  = (string) $this->callbackQuery->getData();
        $prefix = 'craftPreviewT3Armor_';
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

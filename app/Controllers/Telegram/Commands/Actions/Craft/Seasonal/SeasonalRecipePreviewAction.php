<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\Seasonal;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Services\GameSettings\GameSettingsService;
use Config\CraftRecipes;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * S28 (ADR-032) — generic preview сезонного рецепта.
 *
 * Callback prefix: `craftPreviewSeasonal_<RecipeKey>`. Зеркало
 * MedicalRecipePreviewT3Action (heal-эффект из GameSettings medical.<snake>.heal_*),
 * но «Назад» → seasonalCraft. CraftRecipes — single source of truth.
 */
class SeasonalRecipePreviewAction extends BaseAction
{
    private CharacterResourceModel $characterResourceModel;
    private GameSettingsService    $gameSettings;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->gameSettings           = new GameSettingsService();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден или у него нет персонажа.']);
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

        $insufficient = [];

        $needLevel = $this->intFromMixed($recipe['required_level'] ?? 0);
        $charLevel = $this->intFromMixed($character['level'] ?? 0);
        if ($needLevel > 0 && $charLevel < $needLevel) {
            $insufficient[] = "уровень: нужно {$needLevel}, у вас {$charLevel}";
        }

        $goldNeed = $this->intFromMixed($recipe['gold_required'] ?? 0);
        $goldHave = $this->intFromMixed($character['gold'] ?? 0);
        if ($goldHave < $goldNeed) {
            $insufficient[] = "золото: нужно {$goldNeed}, у вас {$goldHave}";
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

        // Heal-эффект из GameSettings (admin-tunable, S19-path).
        $snake      = $this->toSnakeCase($itemNameEng);
        $healHealth = $this->intFromMixed($this->gameSettings->get("medical.{$snake}.heal_health", 0));
        $healTired  = $this->intFromMixed($this->gameSettings->get("medical.{$snake}.heal_tired", 0));

        // ADR-143: реальная занятость задачи крафта (pre-commit предупреждение).
        $scope       = new \App\Services\Tasks\ActionScopeService();
        $taskModel   = new \App\Models\TaskModel();
        $taskNameRaw = $recipe['task_name'] ?? '';
        $taskNameStr = is_string($taskNameRaw) ? $taskNameRaw : '';
        $taskRow     = $taskNameStr !== '' ? $taskModel->where('name', $taskNameStr)->first() : null;
        $bg          = $scope->isBackground(is_array($taskRow) ? ($taskRow['parallel_execution_allowed'] ?? 0) : 0);

        $itemNameRusRaw = $recipe['item_name_rus'] ?? $recipeKey;
        $itemNameRus    = is_string($itemNameRusRaw) ? $itemNameRusRaw : $recipeKey;
        $iconRaw        = $recipe['icon_emoji'] ?? '🗓';
        $icon           = is_string($iconRaw) ? $iconRaw : '🗓';

        $caption = "{$icon} *{$itemNameRus}* (сезонное)\n";
        $effectParts = [];
        if ($healHealth !== 0) {
            $effectParts[] = "❤️ +{$healHealth} HP";
        }
        if ($healTired !== 0) {
            $effectParts[] = "⚡ +{$healTired} выносл.";
        }
        if (!empty($effectParts)) {
            $caption .= "_Эффект: " . implode(', ', $effectParts) . "_\n";
        }
        $caption .= "\n" . $scope->scopeLine(\App\Services\Tasks\ActionScopeService::KIND_CRAFT, $bg) . "\n";
        $caption .= "\n*Уровень:* L{$needLevel} (у вас L{$charLevel})\n";
        if ($goldNeed > 0) {
            $caption .= "*Золото:* {$goldNeed} / {$goldHave}\n";
        }
        if (!empty($resourceLines)) {
            $caption .= "\n*Ресурсы (за 1 шт.):*\n" . implode("\n", $resourceLines) . "\n";
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
            ['text' => '⬅️ К сезонным', 'callback_data' => 'seasonalCraft'],
            ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
        ];

        $imageRelRaw = $recipe['image_in_progress'] ?? 'uploads/telegram/craft/general_crafting_img.png';
        $imageRel    = is_string($imageRelRaw) ? $imageRelRaw : 'uploads/telegram/craft/general_crafting_img.png';
        $absolutePath = FCPATH . $imageRel;
        if (!is_file($absolutePath)) {
            $imageRel = 'uploads/telegram/craft/general_crafting_img.png';
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

    private function extractRecipeKey(): string
    {
        $data   = (string) $this->callbackQuery->getData();
        $prefix = 'craftPreviewSeasonal_';
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

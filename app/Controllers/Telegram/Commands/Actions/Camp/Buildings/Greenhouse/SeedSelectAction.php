<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Greenhouse;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Farming\FarmingService;
use Config\CraftRecipes;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * V6 (ADR-033) — хаб активного земледелия (callback `plantSeedMenu`).
 *
 * Показывает: что растёт сейчас (ETA, занятые слоты), какие семена в запасе,
 * время роста и ожидаемый урожай каждой культуры (+флаг севооборота). Для каждой
 * культуры: посадить (если есть семя) и/или заготовить семена (крафт).
 *
 * Caption самодостаточен (media-off): вся механика читается текстом.
 */
class SeedSelectAction extends BaseAction
{
    use FarmingActionTrait;

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->errorCard($chatId, 'Персонаж не найден. Попробуй /start.');
        }
        $charId  = (int) $character['id'];
        $farming = new FarmingService();

        if (!$farming->isEnabled()) {
            return $this->errorCard($chatId, '🌱 Земледелие сейчас недоступно. Загляни позже.');
        }
        if (!$this->hasGreenhouse($charId)) {
            return $this->errorCard($chatId, 'Для посадки нужна *Теплица* на базе. Построй её и возвращайся.');
        }

        $maxSlots  = $farming->maxConcurrentPlantings();
        $plantTask = $this->plantTaskRow();
        $plantings = $plantTask !== null && isset($plantTask['id']) && is_numeric($plantTask['id'])
            ? $this->activePlantings($charId, (int) $plantTask['id'])
            : [];

        $lastCropRaw = $character['last_planted_crop'] ?? null;
        $lastCrop    = is_string($lastCropRaw) && $lastCropRaw !== '' ? $lastCropRaw : null;

        $seedNames = [];
        foreach ($farming->allCrops() as $crop) {
            $meta = $farming->cropMeta($crop);
            if ($meta !== null) {
                $seedNames[] = $meta['seed_en'];
            }
        }
        $ownedMap = $this->ownedSeedMap($charId, $seedNames);

        // V7: greenhouse-level множители (урожай/время роста) для точного показа.
        $effects   = $this->buildingEffects();
        $ghGrowMult  = $effects->getGreenhouseGrowTimeMultiplier($charId);
        $ghYieldMult = $effects->getGreenhouseYieldMultiplier($charId);
        $ghActive    = ($ghYieldMult > 1.0 || $ghGrowMult < 1.0);

        /** @var CraftRecipes $cfg */
        $cfg = config('CraftRecipes');

        $ghNote = $ghActive
            ? "🏗 _Бонус теплицы: урожай ↑, рост быстрее (по уровню)._\n"
            : '';
        $text = "🌱 *Грядки теплицы*\n"
            . "_Активная посадка — слой поверх пассивной теплицы. Семена → рост по таймеру → урожай (+севооборот за смену культуры)._\n"
            . $ghNote . "\n"
            . $this->activePlantingsText($plantings, $maxSlots, $farming) . "\n";

        $rows = [];
        foreach ($farming->allCrops() as $crop) {
            $meta = $farming->cropMeta($crop);
            if ($meta === null) {
                continue;
            }
            $owned    = $ownedMap[$meta['seed_en']] ?? 0;
            $grow     = $farming->growMinutes($crop, $ghGrowMult);
            $willRot  = $farming->shouldRotate($lastCrop, $crop);
            $yield    = $farming->harvestYield($crop, $willRot, $ghYieldMult);
            $rotTag   = $willRot ? " _(+{$farming->rotationBonusPercent()}% севооборот)_" : '';

            $text .= "{$meta['icon']} *{$meta['crop_ru']}* — семян: *{$owned}*\n"
                . "   ⏱ рост {$grow} мин → урожай ×{$yield}{$rotTag}\n";

            // Состав крафта семени (из рецепта) — для информированности.
            $recipe = $cfg->get($meta['recipe']);
            $costTxt = '';
            if (is_array($recipe) && isset($recipe['resources']) && is_array($recipe['resources'])) {
                $parts = [];
                foreach ($recipe['resources'] as $resName => $qty) {
                    $parts[] = (is_string($resName) ? $resName : '') . '×' . (is_numeric($qty) ? (int) $qty : 0);
                }
                $costTxt = ' (' . implode(', ', $parts) . ')';
            }

            $row = [];
            if ($owned > 0) {
                $row[] = ['text' => "🌱 Посадить {$meta['icon']}", 'callback_data' => "plantSeedPreview_{$crop}"];
            }
            $row[] = ['text' => "🛠 Семена {$meta['icon']}", 'callback_data' => "genericCraft_{$meta['recipe']}_1"];
            $rows[] = $row;

            $text .= "   🛠 заготовить семена{$costTxt}\n\n";
        }

        $rows[] = [
            ['text' => '🏗️ К теплице', 'callback_data' => 'building_5_Greenhouse'],
            ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
        ];

        $imagePath = base_url('uploads/telegram/camp/Greenhouse_craft.png');

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }

    private function errorCard(int $chatId, string $message): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $message,
            'parse_mode' => 'Markdown',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * S20 (v0.51.202) — меню T3 утилит (инструментов) в Professional Workbench.
 * Закрывает ROADMAP-CRAFT Фазу 4 (5/5).
 *
 * Callback: `craftUtilityT3Select` (открывается из WorkbenchProfessionalAction
 * только если у чара уже есть ProfessionalWorkbench в инвентаре).
 *
 * Список 3 existing tool-предметов (рецепты в CraftRecipes; gather-бонус через
 * ToolManager: DiamondPickaxe в legacy map, Sapper/Golden — GameSettings overlay):
 *   - ⛏️ DiamondPickaxe L20 — премиум добыча (камень/руда/глина/лёд, до +220%)
 *   - 🪏 Sapper Shovel   L16 — копаемые (глина/песок/ил/травы), admin-tunable бонус
 *   - 🌾 Golden Hoe      L16 — фермерские (овощи/травы/мхи), admin-tunable бонус
 *
 * Каждая кнопка → `craftPreviewT3Utility_<RecipeKey>` → UtilityRecipePreviewT3Action.
 */
class UtilityCraftT3Select extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $text = "*🔧 Утилиты T3 — Профессиональный верстак*\n\n"
            . "_Три премиальных инструмента добычи. Алмазная кирка для шахтёров, "
            . "сапёрная лопата для копателей, золотая мотыга для фермеров. "
            . "Каждый требует ProfessionalWorkbench, редкие материалы и время._\n\n"
            . "Выбирай рецепт для деталей и крафта:\n\n"
            . "1) ⛏️ *Алмазная кирка* — L20, премиум добыча камня/руды/глины (до +220%)\n"
            . "2) 🪏 *Сапёрная лопата* — L16, копаемые ресурсы (глина/песок/ил/травы)\n"
            . "3) 🌾 *Золотая мотыга* — L16, фермерская добыча (овощи/травы/мхи)\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⛏️ Алмазная кирка', 'callback_data' => 'craftPreviewT3Utility_DiamondPickaxe'],
                ],
                [
                    ['text' => '🪏 Сапёрная лопата', 'callback_data' => 'craftPreviewT3Utility_SapperShovel'],
                    ['text' => '🌾 Золотая мотыга',  'callback_data' => 'craftPreviewT3Utility_GoldenHoe'],
                ],
                [
                    ['text' => '🛠️ Назад к верстаку', 'callback_data' => 'workbenchProfessional'],
                    ['text' => '🎒 Инвентарь',         'callback_data' => 'inventory'],
                ],
            ],
        ];

        $imagePath = base_url('uploads/telegram/craft/tools_standing_by_an_old_fence_in_the_wild.jpg');
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

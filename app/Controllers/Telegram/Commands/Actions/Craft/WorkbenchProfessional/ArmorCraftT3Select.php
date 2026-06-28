<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * S18 (v0.51.200) — меню T3 брони в Professional Workbench (ADR-026 reusable, Фаза 4 3/5).
 *
 * Callback: `craftArmorT3Select` (открывается из WorkbenchProfessionalAction
 * только если у чара уже есть ProfessionalWorkbench в инвентаре).
 *
 * Список 4 outfits (existing outfits table rows; рецепты в CraftRecipes):
 *   - 🛡 TacticalArmorSuit       L16 Epic       (тактический бронекостюм)
 *   - 🦋 ExoskeletonStrekoza     L16 Epic       (лёгкий экзоскелет)
 *   - ⚙️ TitanPowerArmor         L20 Legendary  (тяжёлая силовая броня)
 *   - ⚡ TeslaShardArmor          L25 Legendary  (электрический осколочный)
 *
 * Каждая кнопка → callback `craftPreviewT3Armor_<RecipeKey>` →
 * ArmorRecipePreviewT3Action (generic preview через CraftRecipes lookup).
 */
class ArmorCraftT3Select extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $scope = new \App\Services\Tasks\ActionScopeService();

        $text = "*🛡 Броня T3 — Профессиональный верстак*\n\n"
            . "_Четыре рецепта брони высокого тира. От тактического жилета до силовой брони и тесла-доспеха. "
            . "Каждое требует ProfessionalWorkbench, редкие материалы и время._\n\n"
            . $scope->legend(\App\Services\Tasks\ActionScopeService::KIND_CRAFT) . "\n\n"
            . "Выбирай рецепт для деталей и крафта:\n\n"
            . "1) 🛡 *Тактический бронекостюм* — L16 Epic, 22 armor\n"
            . "2) 🦋 *Экзоскелет «Стрекоза»* — L16 Epic, 10 armor (лёгкий)\n"
            . "3) ⚙️ *Силовая броня «Титан»* — L20 Legendary, 30 armor (тяжёлый)\n"
            . "4) ⚡ *Осколочный доспех «Тесла»* — L25 Legendary, 27 armor\n\n"
            . "🛡 *Грандмастер (L40):*\n"
            . "5) 🛡 *Боевая броня «Джаггернаут»* — Legendary; нужны северные трофеи "
            . "(Пепел Предтеч, Кристалл Разлома, Древние реликвии)\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🛡 Тактический',  'callback_data' => 'craftPreviewT3Armor_TacticalArmorSuit'],
                    ['text' => '🦋 «Стрекоза»',   'callback_data' => 'craftPreviewT3Armor_ExoskeletonStrekoza'],
                ],
                [
                    ['text' => '⚙️ «Титан»',      'callback_data' => 'craftPreviewT3Armor_TitanPowerArmor'],
                    ['text' => '⚡ «Тесла»',       'callback_data' => 'craftPreviewT3Armor_TeslaShardArmor'],
                ],
                [
                    ['text' => '🛡 «Джаггернаут» (Грандмастер)', 'callback_data' => 'craftPreviewT3Armor_JuggernautBattleArmor'],
                ],
                [
                    ['text' => '🛠️ Назад к верстаку', 'callback_data' => 'workbenchProfessional'],
                    ['text' => '🎒 Инвентарь',         'callback_data' => 'inventory'],
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

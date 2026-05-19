<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * S17 (v0.51.199) — меню T3 оружия в Professional Workbench (ADR-026, Фаза 4 2/5).
 *
 * Callback: `craftWeaponsT3Select` (открывается из WorkbenchProfessionalAction
 * только если у чара уже есть ProfessionalWorkbench в инвентаре).
 *
 * Список 5 weapons (existing weapons table rows; рецепты в CraftRecipes):
 *   - 🔫 GaussPistol           L16 Epic     (gauss-импульсный пистолет)
 *   - 🎯 RailCarbineVikhr      L18 Epic     (компактный рельсотрон)
 *   - ⚡ IonDestabilizer        L20 Legendary (ионная пушка)
 *   - 🔥 FlamethrowerAid       L20 Legendary (ранцевый огнемёт)
 *   - 🚀 ExoRailgunBehemoth    L24 Legendary (тяжёлый рельсотрон)
 *
 * Каждая кнопка → callback `craftPreviewT3_<RecipeKey>` →
 * WeaponRecipePreviewT3Action (generic preview через CraftRecipes lookup).
 */
class WeaponsCraftT3Select extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $text = "*⚔️ Оружие T3 — Профессиональный верстак*\n\n"
            . "_Пять рецептов высокого тира. Энергетика, рельсотроны, огнемёт. "
            . "Каждое требует ProfessionalWorkbench, ресурсы из доколлапсной эры и время._\n\n"
            . "Выбирай рецепт для деталей и крафта:\n\n"
            . "1) 🔫 *Гаусс-пистолет* — L16 Epic, 25 dmg\n"
            . "2) 🎯 *Рельсотрон «Вихрь»* — L18 Epic, 30 dmg\n"
            . "3) ⚡ *Ион-дестабилизатор* — L20 Legendary, 35 dmg\n"
            . "4) 🔥 *Огнемёт «Помощь»* — L20 Legendary, 28 dmg\n"
            . "5) 🚀 *Экзо-рельсотрон «Бегемот»* — L24 Legendary, 40 dmg\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔫 Гаусс-пистолет',   'callback_data' => 'craftPreviewT3_GaussPistol'],
                    ['text' => '🎯 «Вихрь»',          'callback_data' => 'craftPreviewT3_RailCarbineVikhr'],
                ],
                [
                    ['text' => '⚡ Ион-дестабилизатор', 'callback_data' => 'craftPreviewT3_IonDestabilizer'],
                    ['text' => '🔥 Огнемёт',           'callback_data' => 'craftPreviewT3_FlamethrowerAid'],
                ],
                [
                    ['text' => '🚀 «Бегемот»', 'callback_data' => 'craftPreviewT3_ExoRailgunBehemoth'],
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

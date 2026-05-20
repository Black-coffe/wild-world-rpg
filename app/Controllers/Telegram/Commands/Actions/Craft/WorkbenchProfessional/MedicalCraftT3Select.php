<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * S19 (v0.51.201) — меню T3 медицины в Professional Workbench (ADR-026 reusable, Фаза 4 4/5).
 *
 * Callback: `craftMedicalT3Select` (открывается из WorkbenchProfessionalAction
 * только если у чара уже есть ProfessionalWorkbench в инвентаре).
 *
 * Список 3 НОВЫХ drug-предметов (рецепты в CraftRecipes; heal-эффект через
 * GameSettings medical.<key>.heal_health/.heal_tired, admin-tunable):
 *   - 💉 SyntheticMedicine    L20 — full-restore (100 HP / 60 вынос.), single-use
 *   - 🩸 EmergencyTransfusion L18 — экстренный +80 HP, single-use
 *   - 🧰 SurgicalKit          L22 — multi-use ×3 (50 HP / 30 вынос. за применение)
 *
 * Каждая кнопка → callback `craftPreviewT3Medical_<RecipeKey>` →
 * MedicalRecipePreviewT3Action (generic preview через CraftRecipes lookup).
 */
class MedicalCraftT3Select extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $text = "*💊 Медицина T3 — Профессиональный верстак*\n\n"
            . "_Три высокотехнологичных медикамента из доколлапсного сырья. "
            . "От экстренного переливания до полного синтетического восстановления и многоразового "
            . "хирургического набора. Каждый требует ProfessionalWorkbench, редкие материалы и время._\n\n"
            . "Выбирай рецепт для деталей и крафта:\n\n"
            . "1) 💉 *Синтетическое лекарство* — L20, полное восстановление (100 HP / 60 вынос.)\n"
            . "2) 🩸 *Экстренное переливание* — L18, +80 HP (одноразовое)\n"
            . "3) 🧰 *Хирургический набор* — L22, многоразовый ×3 (50 HP / 30 вынос. за применение)\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💉 Синтетическое',  'callback_data' => 'craftPreviewT3Medical_SyntheticMedicine'],
                    ['text' => '🩸 Переливание',    'callback_data' => 'craftPreviewT3Medical_EmergencyTransfusion'],
                ],
                [
                    ['text' => '🧰 Хирург. набор',  'callback_data' => 'craftPreviewT3Medical_SurgicalKit'],
                ],
                [
                    ['text' => '🛠️ Назад к верстаку', 'callback_data' => 'workbenchProfessional'],
                    ['text' => '🎒 Инвентарь',         'callback_data' => 'inventory'],
                ],
            ],
        ];

        $imagePath = base_url('uploads/telegram/craft/many_medicinal_things.jpg');
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

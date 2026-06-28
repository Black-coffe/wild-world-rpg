<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * S25 (v0.51.205) — меню фракционного оружия в Professional Workbench
 * (ADR-029, ROADMAP-CRAFT Фаза 5 — ЗАКРЫВАЕТ ФАЗУ).
 *
 * Callback: `craftFactionWeaponsSelect`. 4 net-new Legendary weapons, по одному
 * на фракцию, true faction-exclusive (gate: фракция + StrategicCapture<X> квест,
 * enforced в GenericCraftActionStart). Завершает 1:1 цепочку
 * фракция → strategic-объект → signature weapon.
 *
 * Кнопка → `craftPreviewT3_<RecipeKey>` (reuse generic WeaponRecipePreviewT3Action,
 * который показывает quest/faction-gate и блокирует кнопку крафта если не выполнено).
 */
class FactionWeaponsCraftSelect extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $scope = new \App\Services\Tasks\ActionScopeService();

        $text = "*🎖 Фракционное оружие — Профессиональный верстак*\n\n"
            . "_Сигнатурное Legendary-оружие. По одному на фракцию. Доступно "
            . "только члену фракции, захватившему её стратегический объект._\n\n"
            . $scope->legend(\App\Services\Tasks\ActionScopeService::KIND_CRAFT) . "\n\n"
            . "🪖 *Бункерная винтовка* — Военные (захват Бункера), L20, 38 dmg\n"
            . "🔱 *Технолучевой дробовик* — Инженеры (захват Технопарка), L22, 40 dmg\n"
            . "🗡 *Нож Города-призрака* — Партизаны (захват Города-призрака), L14, 32 dmg\n"
            . "🌾 *Коса Жатвы* — Фермеры (захват Старой фермы), L16, 36 dmg\n\n"
            . "Выбери своё фракционное оружие:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🪖 Бункерная винтовка', 'callback_data' => 'craftPreviewT3_BunkerRifle'],
                    ['text' => '🔱 Технодробовик',       'callback_data' => 'craftPreviewT3_TechnoBeamShotgun'],
                ],
                [
                    ['text' => '🗡 Нож-призрак',  'callback_data' => 'craftPreviewT3_GhostCityKnife'],
                    ['text' => '🌾 Коса Жатвы',   'callback_data' => 'craftPreviewT3_FarmersHarvestScythe'],
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

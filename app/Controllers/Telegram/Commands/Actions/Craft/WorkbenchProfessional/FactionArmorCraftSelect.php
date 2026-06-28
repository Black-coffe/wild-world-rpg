<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * V14 (ADR-046) — меню фракционной брони в Professional Workbench.
 * Сиблинг S25 FactionWeaponsCraftSelect (ADR-029).
 *
 * Callback: `craftFactionArmorSelect`. 4 net-new Legendary outfit'а, по одному
 * на фракцию, true faction-exclusive (gate: фракция + StrategicCapture<X> квест,
 * enforced в GenericCraftActionStart). Дополняет signature-оружие до полного
 * faction-лута (оружие + броня).
 *
 * Кнопка → `craftPreviewT3Armor_<RecipeKey>` (reuse ArmorRecipePreviewT3Action,
 * расширен показом quest/faction-gate; блокирует крафт если gate не пройден).
 */
class FactionArmorCraftSelect extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $scope = new \App\Services\Tasks\ActionScopeService();

        $text = "*🛡 Фракционная броня — Профессиональный верстак*\n\n"
            . "_Сигнатурная Legendary-броня. По одной на фракцию. Доступна "
            . "только члену фракции, захватившему её стратегический объект._\n\n"
            . $scope->legend(\App\Services\Tasks\ActionScopeService::KIND_CRAFT) . "\n\n"
            . "🪖 *Бункерная бронеплита* — Военные (захват Бункера), L20, броня 32 (тяж.)\n"
            . "🔱 *Техно-щитовой костюм* — Инженеры (захват Технопарка), L22, броня 28 (+огнестойк.)\n"
            . "🥷 *Плащ Города-призрака* — Партизаны (захват Города-призрака), L14, броня 22 (+скрытность)\n"
            . "🌾 *Броня хуторской стражи* — Фермеры (захват Старой фермы), L16, броня 26 (+яды)\n\n"
            . "Выбери свою фракционную броню:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🪖 Бронеплита',   'callback_data' => 'craftPreviewT3Armor_BunkerPlateArmor'],
                    ['text' => '🔱 Техно-щит',    'callback_data' => 'craftPreviewT3Armor_TechnoShieldSuit'],
                ],
                [
                    ['text' => '🥷 Плащ-призрак', 'callback_data' => 'craftPreviewT3Armor_GhostCityCloak'],
                    ['text' => '🌾 Хуторская',    'callback_data' => 'craftPreviewT3Armor_FarmsteadGuardArmor'],
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

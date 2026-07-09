<?php

namespace App\Services\Player;

use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class CraftService
{
    /**
     * Показ «меню крафта» (аналогично CraftingAction::handle()).
     *
     * @param int $chatId Кому отправляем это сообщение
     */
    public function showCraftMenu(int $chatId): ServerResponse
    {
        // Текст точно такой же, как в CraftingAction
        $text = "*🛠️ Ты в разделе крафта!* 🏭\n\n"
            . "Здесь куётся твоё могущество. Чем серьёзнее вещь — тем серьёзнее нужен верстак.\n\n"
            . "*№1 Общий крафт*\n"
            . "_Где угодно и когда угодно, без верстака: лекарства, инструменты, компоненты, костёр._\n\n"
            . "*№2 Стандартный крафт*\n"
            . "_Продвинутые вещи: роботы, телепорты, броня, оружие, дроны. Нужны 🔬 Верстак 1 и своя база._\n\n"
            . "*№3 Профессиональный крафт (Tier 3)*\n"
            . "_Топовое оружие, броня, медицина, утилиты и фракционные вещи. Нужен 🛠️ Профессиональный верстак (цех): Доменная печь и Лаборатория 3-го уровня плюс 20-й уровень персонажа._\n\n"
            . "🔬 _Оба верстака собираются в разделе «🔬 Верстаки» (кнопка ниже). Отдельного «второго» верстака нет — сразу после Верстака 1 идёт Профессиональный._";

        // Кнопки = три уровня крафта (Общий / Стандартный / Профессиональный)
        // + раздел сборки самих верстаков. Раньше «Профессиональный крафт»
        // не имел кнопки (вход только через Верстаки) — это и была причина
        // путаницы игроков. Теперь все три уровня видны напрямую.
        $rows = [
            [
                ['text' => '🔨 Общий крафт',          'callback_data' => 'generalCraft'],
                ['text' => '🔧 Стандартный крафт',    'callback_data' => 'standardCraft'],
            ],
            [
                ['text' => '🛠️ Профессиональный крафт', 'callback_data' => 'workbenchProfessional'],
                ['text' => '🔬 Верстаки',               'callback_data' => 'WorkbenchChoice'],
            ],
        ];

        // W19 (ADR-074): «🔧 Модернизация» — gated killswitch'ом (dormant → скрыта, как W9-W18).
        $modernization = (new \App\Services\Craft\ItemModifierService())->enabled()
            ? ['text' => '🔧 Модернизация', 'callback_data' => 'enchant']
            : null;

        // ADR-150 Слайс 5: ремонт ИНСТРУМЕНТОВ живёт в неймспейсе `Craft\Repair`, но кнопки на
        // экране Крафта не было ВООБЩЕ — попасть можно было лишь из Инвентаря (Крафтовые ресурсы)
        // или из хаба поселения. Возвращаем его в свою группу. Ремонт ЗДАНИЙ остаётся на Базе.
        if (\App\Services\Telegram\BotMenuService::craftBaseHubEnabled()) {
            $repairRow = [['text' => '🪛 Ремонт инструментов', 'callback_data' => 'repairToolsList']];
            if ($modernization !== null) {
                $repairRow[] = $modernization;
                $modernization = null;
            }
            $rows[] = $repairRow;

            $text .= "\n\n🪛 _Изношенный инструмент чинится в «Ремонте инструментов» — ресурсами "
                . "или сразу за золото у мастера._";
        }

        if ($modernization !== null) {
            $rows[] = [$modernization];
        }

        $keyboard = ['inline_keyboard' => $rows];

        // Картинка та же, что и в CraftingAction
        $imagePath = base_url('uploads/telegram/craft/crafting_area.png');

        // Возвращаем результат (answerCallbackQuery обычно делают в CallbackqueryCommand)
        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}

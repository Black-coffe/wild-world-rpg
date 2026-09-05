<?php

namespace App\Services\Player;

use App\Services\Telegram\ButtonPacker;

use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class CraftService
{
    /**
     * Показ «меню крафта» (аналогично CraftingAction::handle()).
     *
     * @param int   $chatId    Кому отправляем это сообщение
     * @param mixed $character Строка/сущность персонажа — нужна, чтобы подписать кнопку
     *                         раздела T3 замком, пока цех не собран. Без неё подпись
     *                         остаётся замковой (безопасный дефолт: заперто).
     */
    public function showCraftMenu(int $chatId, mixed $character = null): ServerResponse
    {
        // Есть ли у игрока Профессиональный верстак — тем же вопросом, что задаёт
        // сам экран T3 (`WorkbenchProfessionalAction`). Один гейт на кнопку и на
        // экран за ней: иначе подпись обещает одно, а открывается другое.
        $characterId = 0;
        if (is_array($character) || $character instanceof \ArrayAccess) {
            $rawId = $character['id'] ?? null;
            $characterId = is_numeric($rawId) ? (int) $rawId : 0;
        }
        $hasProfessionalWorkbench = $characterId > 0
            && (new \App\Models\CraftedItemsLogModel())
                ->ownedQuantityByNameEng('ProfessionalWorkbench', $characterId) > 0;

        $repairHubEnabled = \App\Services\Telegram\BotMenuService::craftBaseHubEnabled();

        $text = self::hubCaption($hasProfessionalWorkbench, $repairHubEnabled);

        $modernizationEnabled = (new \App\Services\Craft\ItemModifierService())->enabled();

        $keyboard = [
            'inline_keyboard' => self::hubRows(
                $hasProfessionalWorkbench,
                $repairHubEnabled,
                $modernizationEnabled,
            ),
        ];

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

    /**
     * Чистая сборка подписи хаба крафта — без БД и без Telegram.
     *
     * Вынесена отдельно, чтобы длину подписи мерил тест: фото с подписью длиннее
     * 1024 символов Telegram отклоняет молча (ok=false), и экран просто не
     * приходит игроку.
     */
    public static function hubCaption(bool $hasProfessionalWorkbench, bool $repairHubEnabled): string
    {
        $text = "*🛠️ Ты в разделе крафта!* 🏭\n\n"
            . "Здесь куётся твоё могущество. Чем серьёзнее вещь — тем серьёзнее нужен верстак.\n\n"
            . "*№1 Общий крафт*\n"
            . "_Где угодно и когда угодно, без верстака: лекарства, инструменты, компоненты, костёр._\n\n"
            . "*№2 Стандартный крафт*\n"
            . "_Продвинутые вещи: роботы, телепорты, броня, оружие, дроны. Нужны 🔬 Верстак 1 и своя база._\n\n"
            . "*№3 Профессиональный крафт (Tier 3)*\n"
            . "_Топовое оружие, броня, медицина, утилиты и фракционные вещи. Нужен 🛠️ Профессиональный верстак (цех): Доменная печь и Лаборатория 3-го уровня плюс 20-й уровень персонажа._\n\n"
            . "🔬 _Оба верстака собираются в разделе «🔬 Верстаки» (кнопка ниже). Отдельного «второго» верстака нет — сразу после Верстака 1 идёт Профессиональный._" . "

🔨 _Уже собранное лежит на полке «🔨 Крафтовые ресурсы» — там же раздел «🚚 Транспорт» и починка инструментов._";

        // Пока цеха нет, кнопка раздела T3 подписана замком и ведёт на экран
        // «чего не хватает для сборки» — раньше она молча открывала стройку
        // верстака под подписью «крафт» и читалась как дубль «Верстаков».
        if (!$hasProfessionalWorkbench) {
            $text .= "\n\n🔒 _Профессиональный крафт пока заперт: кнопка «🔒 Проф. крафт» покажет, чего не хватает для сборки цеха._";
        }

        if ($repairHubEnabled) {
            $text .= "\n\n🪛 _Изношенный инструмент чинится в «Ремонте инструментов» — ресурсами "
                . "или сразу за золото у мастера._";
        }

        return $text;
    }

    /**
     * Чистая сборка рядов клавиатуры хаба — без БД и без Telegram.
     *
     * Ряды здесь оставлены «как задумано по смыслу»: одиночный хвост (ремонт без
     * модернизации или наоборот) правит централизованный
     * {@see \App\Services\Telegram\KeyboardNormalizer} в точке отправки. Правило
     * «ноль одиночек в ряду» проверяется тестом на ВЫХОДЕ нормализатора, а не на
     * этом массиве: мерить исходный массив — значит мерить не то, что видит игрок.
     *
     * @return list<list<array<string,string>>>
     */
    public static function hubRows(
        bool $hasProfessionalWorkbench,
        bool $repairHubEnabled,
        bool $modernizationEnabled,
    ): array {
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
                $hasProfessionalWorkbench
                    ? ['text' => '🛠️ Профессиональный крафт', 'callback_data' => 'workbenchProfessional']
                    : ['text' => '🔒 Проф. крафт', 'callback_data' => 'workbenchProfessional'],
                ['text' => '🔬 Верстаки',               'callback_data' => 'WorkbenchChoice'],
            ],
        ];

        // W19 (ADR-074): «🔧 Модернизация» — gated killswitch'ом (dormant → скрыта, как W9-W18).
        $modernization = $modernizationEnabled
            ? ['text' => '🔧 Модернизация', 'callback_data' => 'enchant']
            : null;

        // ADR-150 Слайс 5: ремонт ИНСТРУМЕНТОВ живёт в неймспейсе `Craft\Repair`, но кнопки на
        // экране Крафта не было ВООБЩЕ — попасть можно было лишь из Инвентаря (Крафтовые ресурсы)
        // или из хаба поселения. Возвращаем его в свою группу. Ремонт ЗДАНИЙ остаётся на Базе.
        // Полка уже скрафченного («🔨 Крафтовые ресурсы», `resourcesCrafting`) до 2026-09-05
        // жила ТОЛЬКО в Инвентаре и в хабе поселения — на экране Крафта входа не было вовсе.
        // Из-за этого хинт про транспорт звал «🔨 Крафт» → «🚚 Транспорт»: раздел с машинами
        // лежит именно на этой полке, а дороги к ней из крафта не существовало. Дверь названа
        // так же, как в Инвентаре — одно имя у одной двери.
        $tail = [];
        if ($repairHubEnabled) {
            $tail[] = ['text' => '🪛 Ремонт инструментов', 'callback_data' => 'repairToolsList'];
        }
        $tail[] = ['text' => '🔨 Крафтовые ресурсы', 'callback_data' => 'resourcesCrafting'];
        if ($modernization !== null) {
            $tail[] = $modernization;
        }

        // Пакуем централизованно: одиночка в ряду запрещена (memory
        // feedback_never_one_button_per_row), а хвост меняет длину от килсвитчей.
        foreach (ButtonPacker::pack($tail) as $packedRow) {
            $rows[] = $packedRow;
        }

        return $rows;
    }
}

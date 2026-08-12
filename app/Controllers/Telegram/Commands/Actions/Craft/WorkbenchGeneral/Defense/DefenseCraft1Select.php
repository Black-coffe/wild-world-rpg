<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Defense;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Экран «🛡 Защита» — категория Общего крафта для защитных предметов
 * (`crafted_items.direction_craft = 'protection'`).
 *
 * Повод: багрепорт в чате сообщества (12.08.2026). Игрок увидел в тексте
 * «Метеоритного удара» обещание «Метеоритное укрытие снижает потери вдвое»,
 * обошёл все меню и подменю крафта и не нашёл ни одной двери к предмету:
 * «я везде пересмотрел в меню/подменю крафта, нигде нет».
 *
 * Он был прав. Рецепт `MeteorShelter` жил в `Config\CraftRecipes`, задача
 * `craftMeteorShelter` и строка `crafted_items` — в БД, а кнопки не было
 * нигде: все меню крафта — захардкоженные списки, и ни одно не покрывало
 * `direction_craft='protection'`. За 3 месяца — 0 крафтов при 7 срабатываниях
 * события и 652 задетых персонажах. Классический BUILT-BUT-INVISIBLE
 * (тот же класс бага, что и `droneScout`, см. памятку в `Config\CallbackRoutes`).
 *
 * Раздел заведён отдельным, а не подшит к «Компонентам»/«Инструментам»,
 * потому что защитный предмет — не сырьё и не инструмент: игрок, ищущий
 * «чем прикрыться от события», идёт искать защиту. Здесь же будут жить
 * будущие защитные предметы, которые тексты событий обещают.
 */
class DefenseCraft1Select extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $scope = new \App\Services\Tasks\ActionScopeService();

        $text = "*Ты в разделе 🛡 Защита!* 🏭\n\n"
            . "Здесь крафтятся вещи, которые смягчают удар мировых событий. "
            . "Такую вещь не надо надевать или включать — достаточно держать её "
            . "в инвентаре, и она сработает сама, когда событие грянет.\n\n"
            . "Своя база укрывает сильнее любого предмета, но событие часто застаёт "
            . "в поле — вот на этот случай раздел и нужен.\n\n"
            . $scope->legend(\App\Services\Tasks\ActionScopeService::KIND_CRAFT) . "\n\n"
            . "_Выбирай защиту и приступай к крафту_ 👇\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🛡 Метеоритное укрытие', 'callback_data' => 'meteorShelter'],
                ],
                [
                    ['text' => '🌟 Мировые события', 'callback_data' => 'events'],
                    ['text' => '📋 Очередь крафта', 'callback_data' => 'craftQueue'],
                ],
                [
                    ['text' => '⬅️ Назад', 'callback_data' => 'generalCraft'],
                ],
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $base = $this->navTarget() + [
            'chat_id'      => $chatId,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ];

        // Картинку подставляем только если файл реально на диске: `encodeFile`
        // падает на fopen отсутствующего пути и убивает весь экран целиком
        // (урок `feedback_gear_image_must_resolve_via_is_file`).
        $imageRel  = 'uploads/telegram/camp/Construction-by-improvised.jpg';
        $imageFile = FCPATH . $imageRel;
        if (is_file($imageFile)) {
            return \App\Services\Notifications\MediaSender::editOrSend($base + [
                'photo'   => Request::encodeFile(base_url($imageRel)),
                'caption' => $text,
            ]);
        }

        // Нет файла → экран остаётся полноценным текстом (MEDIA-OFF канон, ADR-020).
        // `editTextOrSend` читает именно `text`, caption он не мапит.
        return \App\Services\Notifications\MediaSender::editTextOrSend($base + ['text' => $text]);
    }
}

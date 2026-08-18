<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;

class InventoryAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $text = "🎒 *Инвентарь*\n\n"
            . "Тут всё, что ты несёшь с собой. Вся добыча с вылазок копится в разделе "
            . "*«Добытые ресурсы»* — это твой основной запас, он может быть огромным.\n\n"
            . "📦 *«Склад базы»* — отдельное хранилище: добыча сама туда не попадает. Сложить "
            . "её туда можно руками, стоя на базе, или карго-дроном — прямо из поля.\n\n"
            // Вопрос игрока 18.08.2026: «какая вообще вместимость инвентаря?» Ответ —
            // предела нет вовсе, и об этом не было сказано нигде. Заодно снимаем
            // ложное впечатление, будто дрон нужен ради места в рюкзаке.
            . "⚖️ *Вес рюкзака ничем не ограничен* — унести можно сколько угодно. Склад "
            . "нужен не ради места, а чтобы держать запас дома: карго-дрон возит добычу "
            . "с любой клетки, пока ты сам далеко.\n\n"
            . "Выбери раздел 👇\n";

        $rows = [
            [
                ['text' => '🔄 Добытые ресурсы', 'callback_data' => 'resourcesGathered'],
                ['text' => '🔨 Крафтовые ресурсы', 'callback_data' => 'resourcesCrafting'],
            ],
            // W8: вход на склад базы (base_storage) прямо из хаба инвентаря —
            // раньше достижим только из move-keyboard / после cargo-доставки
            // (UX-discoverability, CLAUDE.md §🎮 правило #4). Пустой склад — graceful.
            [
                ['text' => '📦 Склад базы', 'callback_data' => 'baseStorageList'],
                ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
            ],
        ];

        // Slice 1 (ресурс-грамотность) — единый экран «📊 Все мои ресурсы»: кнопка
        // только при killswitch ON. OFF → ряд не добавляется → хаб byte-identical (dormant).
        if ((new \App\Services\Player\ResourceOverviewService())->enabled()) {
            array_unshift($rows, [
                ['text' => '📊 Все мои ресурсы', 'callback_data' => 'resourceOverview'],
            ]);
        }

        $keyboard = ['inline_keyboard' => $rows];

        $imagePath = base_url('uploads/telegram/warehouse_in_the_forest.png'); // Укажите актуальный путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // #12 edit-in-place (ADR-018): инвентарь — навигационное меню → редактируем
        // текущее сообщение. editOrSend при любой ошибке edit упадёт обратно на новое.
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}

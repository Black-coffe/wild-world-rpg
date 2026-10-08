<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Player\Trade\ResourceShopScreenService;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;

class ShopAction extends BaseAction
{
    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $text = "🏪 *Добро пожаловать в лавку странствующих торговцев!* 🏪\n\n"
            . "Здесь звучит монетный звон, и каждый предмет исписан историями далёких странствий. 🌍✨\n\n"
            . "Если ты не боишься рыночной суеты и готов выторговать себе достойную цену, тогда твои способности будут здесь оценены по достоинству. 🤝💰\n\n"
            . "Выбери, что ты хочешь сделать: приступить к торгам за редкие артефакты или продать найденные сокровища, чтобы наполнить свой кошель звонкой монетой? Время показать, на что ты способен! 🎩🔔\n\n";

        // W2.N6: входы хаба — из нейтральной модели (те же четыре, что видит веб `/play`).
        $keyboard = ['inline_keyboard' => self::hubRows((new ResourceShopScreenService())->hubEntries())];
        $imagePath = base_url('uploads/telegram/vendor_kiosk_in_the_game_world.png'); // Укажите актуальный путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);

    }

    /**
     * Ряды хаба: сырьё, крафт, затем «Действия» и «Инвентарь». Чистая функция.
     *
     * @param list<array{key:string, label:string, native:bool}> $entries
     * @return list<list<array{text:string, callback_data:string}>>
     */
    public static function hubRows(array $entries): array
    {
        $buttons = array_map(
            static fn (array $e): array => ['text' => $e['label'], 'callback_data' => $e['key']],
            $entries
        );
        $rows   = array_chunk($buttons, 2);
        $rows[] = [
            ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
            ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
        ];

        return $rows;
    }
}

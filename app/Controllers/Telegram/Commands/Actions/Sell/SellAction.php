<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Player\Trade\ResourceShopScreenService;

class SellAction extends BaseAction
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

        // W2.N6: данные экрана — из нейтральной модели, общей с вебом `/play`.
        $model = (new ResourceShopScreenService())->sellHubModel((int) $character['id']);

        $text = "📦 *У тебя есть разных: {$model['types']} вид(а) всех ресурсов*\n"
            . "👉Их общая стоимость = *" . number_format($model['total_value']) . "💰*\n\n"
            . "_📌ВАЖНО📌 Чтобы и тебе, и мне, как торговцу, было проще, пересмотри ресурсы и обрати внимание на их редкость. Ниже отметь цифрой, какой редкости ресурсы ты готов продать. Если их там будет несколько, на следующем шаге ты выберешь нужный ресурс._";

        $rows = self::rarityRows('sellResource_rarity_');
        $rows[3][] = ['text' => '◀️ Я', 'callback_data' => 'character'];
        $rows[3][] = ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'];

        // ADR-096 — оптовая продажа: ряд «💰 N%» под выбором редкости (продать долю ВСЕХ
        // ресурсов сразу). Модель отдаёт доли, только если есть что продавать и фича включена.
        if ($model['bulk'] !== []) {
            $text .= "\n\n🧺 *Оптом* — продать сразу долю *всех* ресурсов:";
            $rows[] = BulkSellAction::buttonsRow('all', $model['bulk']);
        }

        // Arseny report 2026-05-26: «Нужна кнопка назад» — шаг назад на главный экран магазина.
        $rows[] = [
            ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
        ];

        $keyboard = ['inline_keyboard' => $rows];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // #12 edit-in-place (ADR-018): экран выбора редкости для продажи — навигация →
        // редактируем сообщение, на котором нажата кнопка (fallback на новое при ошибке).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Кнопки редкостей 1…10 по три в ряд (последний ряд — одна «🔟», его дополняет вызывающий).
     * Общая для продажи и покупки. Чистая функция.
     *
     * @return list<list<array{text:string, callback_data:string}>>
     */
    public static function rarityRows(string $callbackPrefix): array
    {
        $digits = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟'];
        $btns   = [];
        foreach (ResourceShopScreenService::RARITIES as $i => $r) {
            $btns[] = ['text' => $digits[$i] . ' редкость', 'callback_data' => $callbackPrefix . $r];
        }

        return array_chunk($btns, 3);
    }
}

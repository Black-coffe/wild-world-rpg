<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ResourceModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterModel;
use App\Models\ResourcesBankModel;
use App\Services\Notifications\MediaSender;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\TaskHandlers\ResourceBankUpdateHandler;

class BuyResourceAction extends BaseAction
{
    use GameSettingsReaderTrait;

    protected $resourceModel;
    protected $characterResourceModel;
    protected $characterModel;
    protected $resourcesBankModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->resourceModel          = new ResourceModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->characterModel         = new CharacterModel();
        $this->resourcesBankModel     = new ResourcesBankModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return $this->respondWithMessage('Пользователь не найден в базе данных или персонаж не определён.');
        }

        // Минимальная проверка золота (порог live-tunable через GameSettings, ADR-040)
        $minGold = $this->gsInt('economy.shop.buy_resource_min_gold', 10);
        if ((int) ($character['gold'] ?? 0) < $minGold) {
            return $this->respondWithMessage("К сожалению, у вас недостаточно золотых монет для торговли! Необходимо минимум {$minGold}.");
        }

        $callbackData = $this->callbackQuery->getData();
        $params       = explode('_', $callbackData);

        // buyResource
        //  └── rarity_{число}
        //  └── select_{resourceId}
        //  └── quantity_{resourceId}_{количество}

        if (!isset($params[1])) {
            // Показываем стартовое окно выбора редкости
            return $this->showStartScreen($character);
        }

        switch ($params[1]) {
            case 'rarity':
                $rarity = $params[2] ?? null;
                if ($rarity) {
                    return $this->showResourcesOfRarity($rarity);
                }
                break;

            case 'select':
                $resourceId = $params[2] ?? null;
                if ($resourceId) {
                    return $this->askForQuantity($resourceId);
                }
                break;

            case 'quantity':
                $resourceId = $params[2] ?? null;
                $quantity   = $params[3] ?? null;
                if ($resourceId && $quantity) {
                    return $this->finalizePurchase($character, $resourceId, $quantity);
                }
                break;

            // Идея #6 (Arseny, 21.01.2025): свободный ввод qty через ForceReply.
            case 'custom':
                $resourceId = $params[2] ?? null;
                if ($resourceId) {
                    return $this->promptCustomQuantity((int) $resourceId);
                }
                break;
        }

        return Request::emptyResponse();
    }

    /**
     * Простой метод для отправки текстового сообщения с кнопками «Персонаж, Инвентарь, Магазин».
     * #12 edit-in-place (ADR-018): покупка ресурса — пошаговый флоу (редкость → ресурс → кол-во →
     * результат), каждый шаг редактирует предыдущее сообщение (fallback на новое при ошибке /
     * клике с photo-экрана — так стартовые «нет золота» / «не найден» естественно приходят новым).
     */
    protected function respondWithMessage(string $text): ServerResponse
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин',    'callback_data' => 'shop'],
                ],
            ]
        ];
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Стартовый экран, где игроку предлагают выбрать редкость для покупки
     */
    protected function showStartScreen(array|\App\Entities\CharacterEntity $character): ServerResponse
    {
        $goldAmount = number_format($character['gold']);
        $text = "👉*У тебя есть* _{$goldAmount}_ *золотых монет*💰\n\n"
            . "📌Выбери редкость ресурсов, которые хочешь купить:";

        // Кнопки по редкостям
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '1️⃣ редкость', 'callback_data' => 'buy_rarity_1'],
                    ['text' => '2️⃣ редкость', 'callback_data' => 'buy_rarity_2'],
                    ['text' => '3️⃣ редкость', 'callback_data' => 'buy_rarity_3'],
                ],
                [
                    ['text' => '4️⃣ редкость', 'callback_data' => 'buy_rarity_4'],
                    ['text' => '5️⃣ редкость', 'callback_data' => 'buy_rarity_5'],
                    ['text' => '6️⃣ редкость', 'callback_data' => 'buy_rarity_6'],
                ],
                [
                    ['text' => '7️⃣ редкость', 'callback_data' => 'buy_rarity_7'],
                    ['text' => '8️⃣ редкость', 'callback_data' => 'buy_rarity_8'],
                    ['text' => '9️⃣ редкость', 'callback_data' => 'buy_rarity_9'],
                ],
                [
                    ['text' => '🔟 редкость', 'callback_data' => 'buy_rarity_10'],
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ]
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        // #12 edit-in-place (ADR-018): стартовый экран выбора редкости для покупки — навигация.
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Показать список ресурсов указанной редкости. Показываем buy_price.
     * Добавляем «~» перед buy_price и фразу о том, что реальная цена может отличаться.
     */
    protected function showResourcesOfRarity(int $rarity): ServerResponse
    {
        $resources = $this->resourceModel->where('rarity', $rarity)->findAll();
        if (empty($resources)) {
            return $this->respondWithMessage("*Ресурсы редкости {$rarity} не найдены.*");
        }

        $text            = "📦 *Ресурсы редкости {$rarity}:*\n\n";
        $keyboardButtons = [];
        $row             = [];

        foreach ($resources as $index => $resource) {
            // Показываем текущую (примерную) цену:
            $text .= "🧺 *{$resource['name']}* | _Цена покупки_: ~*{$resource['buy_price']}*💰\n";

            // Добавляем кнопку для выбора конкретного ресурса
            $row[] = [
                'text'          => $resource['name'],
                'callback_data' => "buy_select_{$resource['id']}"
            ];

            // Каждые 2 кнопки в строке
            if (count($row) == 2 || $index == count($resources) - 1) {
                $keyboardButtons[] = $row;
                $row = [];
            }
        }

        // Добавляем фразу о том, что цена может отличаться
        $text .= "*\n❗️Реальная цена может быть другой исходя из спроса ресурса❗️*";

        $keyboard = ['inline_keyboard' => $keyboardButtons];
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        // #12 edit-in-place (ADR-018): список ресурсов редкости для покупки — навигация.
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Спросить, сколько единиц купить
     * Аналогично добавляем «~» и фразу о возможном отличии цены.
     */
    protected function askForQuantity(int $resourceId): ServerResponse
    {
        $resource = $this->resourceModel->find($resourceId);
        if (!$resource) {
            return $this->respondWithMessage("Ресурс не найден.");
        }

        // Идея #15 (Arseny, 16.04.2025): прозрачная торговля — итог в кнопках,
        // чтобы игрок видел сколько потратит ДО клика, а не после.
        $unitPrice = (int) $resource['buy_price'];

        $text = "🧺 *Выберите желаемое количество*\n"
            . "📦 _{$resource['name']}_ *для покупки.*\n"
            . "Текущая цена за 1 ед: ~*{$unitPrice}* 💰\n\n"
            . "Реальная цена может быть другой исходя из спроса ресурса.";

        $btn = static function (int $qty) use ($resourceId, $unitPrice): array {
            $total = $qty * $unitPrice;
            return [
                'text'          => "{$qty} → " . number_format($total) . "💰",
                'callback_data' => "buy_quantity_{$resourceId}_{$qty}",
            ];
        };

        $keyboardButtons = [
            'inline_keyboard' => [
                [$btn(1),   $btn(5),    $btn(10),   $btn(15)],
                [$btn(25),  $btn(50),   $btn(100),  $btn(150)],
                [$btn(250), $btn(500),  $btn(1000), $btn(5000)],
                [['text' => '📝 Своё число', 'callback_data' => "buy_custom_{$resourceId}"]],
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        // #12 edit-in-place (ADR-018): экран выбора количества для покупки — навигация.
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboardButtons),
        ]);
    }

    /**
     * Идея #6: ForceReply prompt для произвольного qty.
     * Маркер `BUY:{id}` БЕЗ квадратных скобок — Telegram (parse_mode=Markdown) их «съедал»,
     * ответ не находил маркер (баг 2026-05-11). Парсит ответ {@see GenericmessageCommand}.
     */
    protected function promptCustomQuantity(int $resourceId): ServerResponse
    {
        $resource = $this->resourceModel->find($resourceId);
        if (!$resource) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Ресурс не найден.',
            ]);
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => "📝 Введите число для покупки *{$resource['name']}* (1 ед. = ~{$resource['buy_price']}💰).\n\nОтветьте на это сообщение числом.\n_(код заявки: BUY:{$resourceId})_",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['force_reply' => true, 'selective' => true]),
        ]);
    }

    /**
     * Финальный этап — купить указанный ресурс. Делегирует в ResourceTradeService.
     */
    protected function finalizePurchase(array|\App\Entities\CharacterEntity $character, int $resourceId, int $quantity): ServerResponse
    {
        // ⚠️ `(array) $entity` по CI4-Entity даёт mangled-ключи — нужен `->toArray()`,
        // иначе `$character['id']`/`$character['gold']` === null в ResourceTradeService
        // (prod-баг 2026-05-11, тот же класс, что у продажи кнопкой).
        $charArr = $character instanceof \App\Entities\CharacterEntity ? $character->toArray() : $character;
        $svc     = new \App\Services\Player\Trade\ResourceTradeService();
        $result  = $svc->buyResource($charArr, $resourceId, $quantity);
        return $this->respondWithMessage($result['message']);
    }
}

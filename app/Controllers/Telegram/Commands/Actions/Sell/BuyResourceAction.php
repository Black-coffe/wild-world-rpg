<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ResourceModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterModel;
use App\Models\ResourcesBankModel;
use App\Services\Notifications\MediaSender;
use App\Services\Player\Trade\ResourceShopScreenService;
use App\TaskHandlers\ResourceBankUpdateHandler;

class BuyResourceAction extends BaseAction
{
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

        // Минимальная проверка золота (порог live-tunable через GameSettings, ADR-040) —
        // W2.N6: решает нейтральная модель, общая с вебом `/play`.
        $hub = (new ResourceShopScreenService())->buyHubModel((int) $character['id']);
        if (! $hub['allowed']) {
            return $this->respondWithMessage("К сожалению, у вас недостаточно золотых монет для торговли! Необходимо минимум {$hub['min_gold']}.");
        }

        $callbackData = $this->callbackQuery->getData();
        $params       = explode('_', $callbackData);

        // buyResource
        //  └── rarity_{число}
        //  └── select_{resourceId}
        //  └── quantity_{resourceId}_{количество}

        if (!isset($params[1])) {
            // Показываем стартовое окно выбора редкости
            return $this->showStartScreen($hub['gold']);
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
                    return $this->askForQuantity((int) $character['id'], (int) $resourceId);
                }
                break;

            case 'quantity':
                $resourceId = $params[2] ?? null;
                $quantity   = $params[3] ?? null;
                if ($resourceId && $quantity) {
                    return $this->finalizePurchase($character, $resourceId, $quantity);
                }
                break;

            // Дефицит-ссылка: экран нехватки (крафт / стройка) шлёт `buy_need_{id}_{qty}`
            // и открывает количество СРАЗУ для нужного ресурса. Раньше обе кнопки «Купить»
            // вели на общий выбор редкости, и игрок должен был сам вспомнить, какая
            // редкость у глины — за 30 дней покупались только ресурсы id 1–10.
            case 'need':
                $resourceId = $params[2] ?? null;
                $needQty    = $params[3] ?? null;
                if ($resourceId) {
                    return $this->askForQuantity((int) $character['id'], (int) $resourceId, is_numeric($needQty) ? (int) $needQty : 0);
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

        // Нераспознанный формат callback (устаревшая кнопка) — гасим «часики»
        // и объясняем причину (no-silent-failures), а не молчим.
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => '⚠️ Кнопка устарела. Открой магазин заново.',
            'show_alert'        => true,
        ]);
        return Request::emptyResponse();
    }

    /**
     * Простой метод для отправки текстового сообщения с кнопками «Персонаж, Инвентарь, Магазин».
     * #12 edit-in-place (ADR-018): покупка ресурса — пошаговый флоу (редкость → ресурс → кол-во →
     * результат), каждый шаг редактирует предыдущее сообщение (fallback на новое при ошибке /
     * клике с photo-экрана — так стартовые «нет золота» / «не найден» естественно приходят новым).
     */
    /**
     * @param list<array{text: string, callback_data: string}> $topRow
     */
    protected function respondWithMessage(string $text, array $topRow = []): ServerResponse
    {
        $rows = [];
        // Arseny report 2026-05-26 (хвост): экран результата сделки — ряд возврата
        // в тот же список редкости, чтобы «купить ещё» не требовало заново идти
        // Магазин → Купить ресы → редкость. Для ошибок ряд пустой.
        if ($topRow !== []) {
            $rows[] = $topRow;
        }
        $rows[] = [
            ['text' => '◀️ Я', 'callback_data' => 'character'],
            ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ['text' => '🛒 Магазин',    'callback_data' => 'shop'],
        ];
        $keyboard = ['inline_keyboard' => $rows];
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
    protected function showStartScreen(float $gold): ServerResponse
    {
        $goldAmount = number_format($gold);
        $text = "👉*У тебя есть* _{$goldAmount}_ *золотых монет*💰\n\n"
            . "📌Выбери редкость ресурсов, которые хочешь купить:";

        // Кнопки по редкостям
        $rows = SellAction::rarityRows('buy_rarity_');
        $rows[3][] = ['text' => '◀️ Я', 'callback_data' => 'character'];
        $rows[3][] = ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'];
        // Arseny report 2026-05-26: «Нужна кнопка назад» — шаг назад на главный экран магазина.
        $rows[] = [
            ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
        ];
        $keyboard = ['inline_keyboard' => $rows];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        // #12 edit-in-place (ADR-018): стартовый экран выбора редкости для покупки — навигация.
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Показать список ресурсов указанной редкости с buy_price — витрина из
     * {@see ResourceShopScreenService::buyRarityModel()} (только торгуемое: семена с
     * `is_tradeable=0` раньше продавались даром).
     */
    protected function showResourcesOfRarity(int $rarity): ServerResponse
    {
        $model = (new ResourceShopScreenService())->buyRarityModel($rarity);
        if ($model['rows'] === []) {
            return $this->respondWithMessage("*Ресурсы редкости {$rarity} не найдены.*");
        }

        $text = "📦 *Ресурсы редкости {$rarity}:*\n\n";
        $btns = [];
        foreach ($model['rows'] as $row) {
            // Показываем текущую (примерную) цену:
            $text  .= "🧺 *{$row['name']}* | _Цена покупки_: ~*{$row['price_text']}*💰\n";
            $btns[] = ['text' => $row['name'], 'callback_data' => "buy_select_{$row['resource_id']}"];
        }

        // Добавляем фразу о том, что цена может отличаться
        $text .= "*\n❗️Реальная цена может быть другой исходя из спроса ресурса❗️*";

        // По две кнопки в строке; Arseny report 2026-05-26: «Нужна кнопка назад» — шаг назад на выбор редкости.
        $keyboardButtons   = array_chunk($btns, 2);
        $keyboardButtons[] = [
            ['text' => '⬅️ Назад', 'callback_data' => 'buy'],
            ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
        ];

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
     * Спросить, сколько единиц купить — карточка из {@see ResourceShopScreenService::buyCardModel()}.
     */
    protected function askForQuantity(int $characterId, int $resourceId, int $needQty = 0): ServerResponse
    {
        $card = (new ResourceShopScreenService())->buyCardModel($characterId, $resourceId, $needQty);
        if ($card === null) {
            return $this->respondWithMessage("Ресурс не найден.");
        }

        // Идея #15 (Arseny, 16.04.2025): прозрачная торговля — итог в кнопках, чтобы игрок видел,
        // сколько потратит ДО клика. Цена и итог — из того же сервиса, что проводит сделку.
        $text = "🧺 *Выберите желаемое количество*\n"
            . "📦 _{$card['name']}_ *для покупки.*\n"
            . "Текущая цена за 1 ед: ~*{$card['unit_text']}* 💰\n\n"
            . "Реальная цена может быть другой исходя из спроса ресурса.";

        // Arseny report 2026-05-26: «Нужна кнопка назад» — шаг назад на список ресурсов
        // той же редкости (а не на выбор редкости через 2 шага).
        $backCallback = $card['rarity'] > 0 ? "buy_rarity_{$card['rarity']}" : 'buy';

        // Пришли с экрана нехватки — первой кнопкой ровно то количество, которого не хватает,
        // чтобы «докупить» было одним тапом, а не арифметикой в уме. Своё число рядом:
        // одиночная кнопка в ряду запрещена.
        $topRow = [['text' => '📝 Своё число', 'callback_data' => "buy_custom_{$resourceId}"]];
        if ($card['need'] !== null) {
            array_unshift($topRow, [
                'text'          => "🎯 Не хватает {$card['need']['qty']} → " . number_format($card['need']['total']) . '💰',
                'callback_data' => "buy_quantity_{$resourceId}_{$card['need']['qty']}",
            ]);
            $text .= "\n\n🎯 Для задуманного не хватает *{$card['need']['qty']}* ед.";
        }

        $rows   = SellResourceAction::presetRows($card['presets'], "buy_quantity_{$resourceId}_");
        $rows[] = $topRow;
        $rows[] = [
            ['text' => '⬅️ Назад',  'callback_data' => $backCallback],
            ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        // #12 edit-in-place (ADR-018): экран выбора количества для покупки — навигация.
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
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
        $chatId  = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        $result  = $svc->buyResource($charArr, $resourceId, $quantity, $chatId);

        // Возврат в список той же редкости — «купить ещё» в один тап.
        // ⚠️ find() отдаёт ResourceEntity, а не массив — читаем через ArrayAccess
        // (как askForQuantity выше), иначе rarity=0 и кнопка не появится никогда.
        $resource  = $this->resourceModel->find($resourceId);
        $rawRarity = $resource['rarity'] ?? null;
        $rarity    = is_numeric($rawRarity) ? (int) $rawRarity : 0;
        $topRow    = $rarity > 0
            ? [
                ['text' => "⬅️ К редкости {$rarity}", 'callback_data' => "buy_rarity_{$rarity}"],
                ['text' => '🛍️ Другая редкость',      'callback_data' => 'buy'],
            ]
            : [];

        return $this->respondWithMessage($result['message'], $topRow);
    }
}

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
// Если хотим сразу пересчитывать цены после сделки
use App\TaskHandlers\ResourceBankUpdateHandler;

class SellResourceAction extends BaseAction
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
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $callbackData = $this->callbackQuery->getData();
        $params       = explode('_', $callbackData);

        // sellResource
        //  └── rarity_{число}
        //  └── {resourceId}_quantity
        //  └── {resourceId}_{число}_sell

        // Выбор редкости?
        if (count($params) == 3 && $params[0] === 'sellResource' && $params[1] === 'rarity') {
            $rarity = (int)$params[2];
            return $this->showResourcesOfRarity($character['id'], $rarity);
        }

        // Если callback_data = sellResource_{resourceId}_quantity
        if (count($params) >= 3) {
            $resourceId = (int)$params[1];

            // пользователь выбрал ресурс, не указав кол-во
            if ($params[2] === 'quantity' && count($params) == 3) {
                return $this->askForQuantity($character['id'], $resourceId);
            }

            // Идея #6 (Arseny, 21.01.2025): свободный ввод qty через ForceReply.
            if ($params[2] === 'custom' && count($params) == 3) {
                return $this->promptCustomQuantity($resourceId);
            }

            // Если callback_data = sellResource_{resourceId}_{quantity}_sell
            if (count($params) == 4 && $params[3] === 'sell') {
                $quantity = $params[2]; // может быть 'all' или число
                return $this->finalizeSale($character, $resourceId, $quantity);
            }
        }

        // Нераспознанный формат callback (устаревшая кнопка) — гасим «часики»
        // и объясняем причину (no-silent-failures), а не молчим.
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => '⚠️ Кнопка устарела. Открой продажу ресурсов заново.',
            'show_alert'        => true,
        ]);
        return Request::emptyResponse();
    }

    /**
     * Идея #6: ForceReply prompt для произвольного qty.
     * Маркер `SELL:{id}` в тексте позволяет GenericmessageCommand роутить ответ
     * обратно в SellResourceAction::finalizeSale. БЕЗ квадратных скобок:
     * при parse_mode=Markdown Telegram их «съедал», ответ не находил маркер
     * (баг 2026-05-11 — «Не понял…» на ввод своего числа продажи).
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
            'text'         => "📝 Введите число для продажи *{$resource['name']}* (1 ед. = {$resource['sell_price']}💰).\n\nОтветьте на это сообщение числом.\n_(код заявки: SELL:{$resourceId})_",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['force_reply' => true, 'selective' => true]),
        ]);
    }

    /**
     * Показать ресурсы нужной редкости — данные из {@see ResourceShopScreenService::sellRarityModel()}
     * (W2.N6: та же модель, что у веба `/play`).
     */
    protected function showResourcesOfRarity(int $characterId, int $rarity): ServerResponse
    {
        $model = (new ResourceShopScreenService())->sellRarityModel($characterId, $rarity);
        if (! $model['known']) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Ресурсы редкости {$rarity} не найдены!",
            ]);
        }

        $text            = "📦 *Ресурсы редкости {$rarity}:*\n\n";
        $keyboardButtons = [];

        foreach ($model['rows'] as $row) {
            $text .= "*{$row['name']}* | "
                . "Единиц: *" . number_format($row['quantity']) . "* | "
                . "На сумму: ~" . number_format($row['total']) . "💰\n";

            // Кнопка для выбора этого ресурса
            $keyboardButtons[] = [[
                'text'          => "{$row['name']} | 📦 " . number_format($row['quantity']) . " | ~" . number_format($row['total']) . "💰",
                'callback_data' => "sellResource_{$row['resource_id']}_quantity",
            ]];
        }

        // Если не нашлось ничего
        if (empty($keyboardButtons)) {
            $text = "У вас нет ресурсов редкости {$rarity} для продажи.";
        } else {
            // Добавляем пояснение о том, что цена может отличаться
            $text .= "\n*❗️Реальная цена может быть другой исходя из спроса ресурса❗️*";
        }

        // ADR-096 — оптовая продажа внутри редкости: ряд «💰 N%» (продать долю всех
        // показанных ресурсов этой редкости). Модель отдаёт доли, только если есть ходовой ресурс и фича вкл.
        if ($model['bulk'] !== []) {
            $text .= "\n🧺 *Оптом по этой редкости* — продать долю всех показанных ресурсов:";
            $keyboardButtons[] = BulkSellAction::buttonsRow("rarity_{$rarity}", $model['bulk']);
        }

        // Arseny report 2026-05-26: «Нужна кнопка назад» — шаг назад на выбор редкости.
        $keyboardButtons[] = [
            ['text' => '⬅️ Назад',  'callback_data' => 'sell'],
            ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
        ];

        $keyboard = ['inline_keyboard' => $keyboardButtons];
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // #12 edit-in-place (ADR-018): список ресурсов редкости — навигация → редактируем
        // сообщение, на котором нажата кнопка (fallback на новое при ошибке).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Предложить пользователю выбрать кол-во — карточка из
     * {@see ResourceShopScreenService::sellCardModel()}.
     */
    protected function askForQuantity(int $characterId, int $resourceId): ServerResponse
    {
        $card = (new ResourceShopScreenService())->sellCardModel($characterId, $resourceId);
        if ($card === null) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Ресурс не найден.',
            ]);
        }

        // Идея #15 (Arseny, 16.04.2025): прозрачная торговля — итог прямо в кнопках.
        // Цена и итог — из того же сервиса, что проводит сделку (см. ResourceTradeService::totalFor).
        $text = "Выберите количество для продажи ресурса:\n 📦 *{$card['name']}*:\n"
            . "Текущая цена продажи (за 1 ед.) = *{$card['unit_text']}* 💰";

        // Arseny report 2026-05-26: «Нужна кнопка назад» — шаг назад на список ресурсов
        // той же редкости (а не на выбор редкости через 2 шага).
        $backCallback = $card['rarity'] > 0 ? "sellResource_rarity_{$card['rarity']}" : 'sell';

        $keyboardButtons   = self::presetRows($card['presets'], "sellResource_{$resourceId}_", '_sell');
        $keyboardButtons[] = [['text' => '📝 Своё число', 'callback_data' => "sellResource_{$resourceId}_custom"]];
        $keyboardButtons[] = [
            ['text' => '⬅️ Назад',  'callback_data' => $backCallback],
            ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
        ];

        $keyboard = ['inline_keyboard' => $keyboardButtons];
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        // #12 edit-in-place (ADR-018): экран выбора количества — навигация → редактируем
        // сообщение, на котором нажата кнопка (fallback на новое при ошибке).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Пресеты количества «N → итог💰» по четыре в ряд. Общая для продажи и покупки. Чистая функция.
     *
     * @param list<array{qty:int, total:int}> $presets
     * @return list<list<array{text:string, callback_data:string}>>
     */
    public static function presetRows(array $presets, string $prefix, string $suffix = ''): array
    {
        $btns = array_map(
            static fn (array $p): array => [
                'text'          => "{$p['qty']} → " . number_format($p['total']) . '💰',
                'callback_data' => $prefix . $p['qty'] . $suffix,
            ],
            $presets
        );

        return array_chunk($btns, 4);
    }

    /**
     * Собственно, продажа — делегирует в ResourceTradeService.
     */
    protected function finalizeSale(array|\App\Entities\CharacterEntity $character, int $resourceId, $quantityAction): ServerResponse
    {
        // ⚠️ `(array) $entity` по CI4-Entity даёт mangled-ключи (`\0*\0attributes`) —
        // нужен `->toArray()`, иначе `$character['id']` === null → «Undefined array key "id"»
        // в ResourceTradeService (prod-баг 2026-05-11, та же причина, что в handleTradeReply).
        $charArr = $character instanceof \App\Entities\CharacterEntity ? $character->toArray() : $character;
        $svc     = new \App\Services\Player\Trade\ResourceTradeService();
        $result  = $svc->sellResource($charArr, $resourceId, $quantityAction);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        if (!$result['success']) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => $result['message']]);
        }

        // Arseny report 2026-05-26 (хвост): после сделки — возврат в список той же
        // редкости, чтобы «продать ещё» не требовало заново идти Магазин → Продать → редкость.
        // ⚠️ find() отдаёт ResourceEntity, а не массив — читаем через ArrayAccess
        // (как askForQuantity выше), иначе rarity=0 и кнопка не появится никогда.
        // Тот же ряд отдаёт имя для человекочитаемой записи ниже (story
        // chat-requests-batch-12) — второго запроса ради имени не появляется.
        $rows      = [];
        $resource  = $this->resourceModel->find($resourceId);
        $nameRaw   = $resource['name'] ?? null;
        $nameSafe  = is_string($nameRaw) && $nameRaw !== '' ? $nameRaw : "Ресурс#{$resourceId}";
        $qtySafe   = is_numeric($result['qty'] ?? null) ? (int) $result['qty'] : 0;
        $goldSafe  = is_numeric($result['amount'] ?? null) ? (int) $result['amount'] : 0;

        // Логируем расход сырья в action_log (форензика «куда делись ресурсы?»); тот же
        // человекочитаемый голос, что налог/смерть (story 05/11) — экран «Куда ушло»
        // (story 06) показывает description дословно, без разбора.
        $this->logActivity(
            is_numeric($charArr['id'] ?? null) ? (int) $charArr['id'] : null,
            'SELL_RESOURCE',
            \App\Services\Player\Trade\ResourceTradeService::describeTrade('Продажа', $nameSafe, $qtySafe, $goldSafe)
        );

        $rawRarity = $resource['rarity'] ?? null;
        $rarity    = is_numeric($rawRarity) ? (int) $rawRarity : 0;
        if ($rarity > 0) {
            $rows[] = [
                ['text' => "⬅️ К редкости {$rarity}", 'callback_data' => "sellResource_rarity_{$rarity}"],
                ['text' => '🛒 Магазин',              'callback_data' => 'shop'],
            ];
        }
        $rows[] = [
            ['text' => '💰 Продать', 'callback_data' => 'sell'],
            ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
        ];
        $rows[] = [
            ['text' => '◀️ Я', 'callback_data' => 'character'],
            ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
        ];
        $keyboard = ['inline_keyboard' => $rows];
        $imagePath = base_url('uploads/telegram/vendor_kiosk_in_the_game_world.png');
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $result['message'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}

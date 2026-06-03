<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Caravan;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CaravanModel;
use App\Models\ResourceModel;
use App\Services\Player\CaravanService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * V25 (ADR-057) — экран странствующего NPC-каравана на клетке игрока.
 *
 * Callback: `caravanLook` — без аргументов; ищет ВСЕ активные караваны на
 * `characters.cell_number`. Если несколько — рисует список; если один — сразу
 * детальная карточка с кнопкой «🛍 Купить N».
 */
class CaravanLookAction extends BaseAction
{
    private CaravanModel $caravanModel;
    private ResourceModel $resModel;
    private CaravanService $service;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->caravanModel = new CaravanModel();
        $this->resModel     = new ResourceModel();
        $this->service      = new CaravanService();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return $this->errReply($chatId, 'Пользователь не найден.');
        }
        if (! $this->service->enabled()) {
            return $this->errReply($chatId, 'Караванов сейчас не видно.');
        }

        $cellNumber = $this->extractInt($character, 'cell_number');
        if ($cellNumber <= 0) {
            return $this->errReply($chatId, 'Невозможно определить твою клетку.');
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $caravans = $this->caravanModel->findActiveOnCell($cellNumber);
        if (empty($caravans)) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🚚 На этой клетке нет каравана. Возможно, он уже ушёл.",
                'parse_mode' => 'Markdown',
            ]);
        }

        $caravan        = $caravans[0];
        $rawOfferType   = $caravan['offer_type'] ?? 'resource';
        $offerType      = is_string($rawOfferType) ? $rawOfferType : 'resource';
        $rawId          = $caravan['id']             ?? null;
        $caravanId      = is_numeric($rawId)    ? (int) $rawId    : 0;
        $rawExpires     = $caravan['expires_at'] ?? '';
        $expires        = is_string($rawExpires) ? $rawExpires : '';
        $expiresShort   = $expires !== '' ? substr($expires, 11, 5) : '??:??';
        $haveGold       = $this->extractInt($character, 'gold');

        // W14b (ADR-068): bargained-режим (callback caravanLookBargain) — детерминированная
        // скидка торга от trading_karma. RNG-fence safe.
        $bargained    = ((string) $this->callbackQuery->getData()) === 'caravanLookBargain';
        $tradingKarma = $this->extractInt($character, 'trading_karma');

        // W15 (ADR-069): фракция игрока для faction-affinity (0 = нет фракции/Нейтрал).
        $playerFactionId = (new \App\Models\CharacterFactionModel())->getFactionId($this->extractInt($character, 'id'));

        // W14a (ADR-068): богатый караван (multi-resource bundle) — несколько строк
        // с общим caravan_group_id на клетке. Рендерим списком, покупка поресурсно.
        $rawGroupId = $caravan['caravan_group_id'] ?? null;
        $groupId    = is_numeric($rawGroupId) ? (int) $rawGroupId : 0;
        if ($groupId > 0) {
            $groupRows = array_values(array_filter(
                $caravans,
                fn ($c) => is_numeric($c['caravan_group_id'] ?? null) && (int) $c['caravan_group_id'] === $groupId
            ));
            return $this->renderBundle($chatId, $groupRows, $haveGold, $expiresShort, $bargained, $tradingKarma, $playerFactionId);
        }

        // W5 (ADR-064): drone-offer branch.
        if ($this->service->isDroneOfferType($offerType)) {
            return $this->renderDroneOffer($chatId, $caravan, $caravanId, $offerType, $haveGold, $expiresShort);
        }

        // V25 resource-offer branch (unchanged).
        $rawResId = $caravan['resource_id'] ?? null;
        $resourceId = is_numeric($rawResId) ? (int) $rawResId : 0;
        $resName  = $this->resolveResourceName($resourceId);

        $rawQty   = $caravan['quantity']       ?? null;
        $rawPrice = $caravan['price_per_unit'] ?? null;
        $qty       = is_numeric($rawQty)   ? (int) $rawQty   : 0;
        $basePrice = is_numeric($rawPrice) ? (int) $rawPrice : 0;

        // W15 (ADR-069): faction-affinity (член → скидка / ривал → наценка) ДО торга.
        $caravanFactionId = $this->extractInt($caravan, 'faction_id');
        $factionPrice = $this->service->applyFactionAffinity($basePrice, $caravanFactionId, $playerFactionId);
        // W14b: bargained-режим → договорная цена (детерминированно от trading_karma) поверх faction.
        $price = $bargained ? $this->service->applyBargain($factionPrice, $tradingKarma) : $factionPrice;
        $total = $qty * $price;

        $text  = "🚚 *Странствующий караван*\n\n";
        $text .= "Перед тобой стоит крытая повозка. Торговец предлагает:\n\n";
        $affinityLine = $this->affinityLine($caravanFactionId, $playerFactionId);
        if ($affinityLine !== '') {
            $text .= $affinityLine;
        }
        $text .= "📦 *{$resName}* — {$qty} шт.\n";
        if ($bargained && $price < $basePrice) {
            $pct = $this->service->bargainDiscountPct($tradingKarma);
            $text .= "💰 Договорная цена: *{$price}* 🪙/шт. (торг −{$pct}%, было {$basePrice})\n";
        } else {
            $text .= "💰 Цена: *{$price}* 🪙 за единицу (скидка к рынку!)\n";
        }
        $text .= "💼 Полная сделка: *{$total}* 🪙\n";
        $text .= "⏱ Караван уйдёт около *{$expiresShort}*.\n\n";
        $text .= "💰 У тебя: *{$haveGold}* 🪙";

        if ($qty <= 0) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $text . "\n\n_Караван уже распродал всё._",
                'parse_mode' => 'Markdown',
            ]);
        }

        if ($haveGold < $price) {
            $text .= "\n\n❌ _Не хватает золота даже на 1 шт. ({$price} 🪙)._";
            $keyboard = ['inline_keyboard' => [
                [['text' => '🗺 Карта', 'callback_data' => 'move']],
                [['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']],
            ]];
        } else {
            $affordableQty = (int) floor($haveGold / $price);
            $canBuy        = min($qty, $affordableQty);
            $buyCallback   = $bargained ? "caravanBuyBargain_{$caravanId}" : "caravanBuyAll_{$caravanId}";
            $buyLabel      = $bargained
                ? "🤝 Купить по торгу (×{$canBuy} = " . ($canBuy * $price) . " 🪙)"
                : "🛍 Купить всё (×{$canBuy} = " . ($canBuy * $price) . " 🪙)";

            $rows = [[['text' => $buyLabel, 'callback_data' => $buyCallback]]];
            // W14b: кнопка «Торговаться» — только в обычном режиме при включённом торге.
            if (! $bargained && $this->service->bargainEnabled() && $this->service->bargainDiscountPct($tradingKarma) > 0) {
                $pct = $this->service->bargainDiscountPct($tradingKarma);
                $rows[] = [['text' => "💱 Торговаться (−{$pct}%)", 'callback_data' => 'caravanLookBargain']];
            }
            $rows[] = [['text' => '🗺 Карта', 'callback_data' => 'move'], ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']];
            $keyboard = ['inline_keyboard' => $rows];
        }

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function extractInt(mixed $row, string $key): int
    {
        if (is_array($row)) {
            $v = $row[$key] ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        if (is_object($row)) {
            $v = $row->{$key} ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        return 0;
    }

    /**
     * W5 (ADR-064): рендер карточки drone-offer'а.
     *
     * @param array<string,mixed> $caravan
     */
    private function renderDroneOffer(
        int $chatId,
        array $caravan,
        int $caravanId,
        string $offerType,
        int $haveGold,
        string $expiresShort
    ): ServerResponse {
        $droneType = $this->service->droneTypeFromOffer($offerType);
        $catalog   = $this->service->droneOfferCatalog();
        $meta      = $catalog[$droneType] ?? null;
        if (! is_array($meta)) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => '🚚 *Караван*' . "\n\n" . '_Этот лот не идентифицирован — попробуй позже._',
                'parse_mode' => 'Markdown',
            ]);
        }

        $rawGold     = $caravan['gold_price'] ?? null;
        $goldPrice   = is_numeric($rawGold) ? (int) $rawGold : 0;
        $emoji       = $meta['emoji'];
        $nameRus     = $meta['name_rus'];

        $capability  = match ($droneType) {
            'scout'  => 'Открывает 21×21 клеток вокруг тебя без передвижения. Заряжается на базе ~2 часа.',
            'cargo'  => 'Перевозит до 30 кг ресурсов на твою базу за один взлёт. Заряжается на базе ~3 часа.',
            'repair' => 'Один взлёт чинит ВСЕХ твоих роботов разом за чистое золото. Заряжается ~4 часа.',
            'combat' => 'Активируется по запросу: +12% инициативы защитнику в PvP-бою на 30 мин (cap 25% с Дозорной вышкой). Заряжается ~6 часов.',
            default  => '',
        };

        $text  = "🚚 *Странствующий караван*\n\n";
        $text .= "На повозке — собранный кустарным мастером *готовый дрон*.\n\n";
        $text .= "{$emoji} *{$nameRus}* (full battery)\n";
        $text .= "_{$capability}_\n\n";
        $text .= "💰 Цена: *{$goldPrice}* 🪙 (premium-наценка vs крафт)\n";
        $text .= "⏱ Караван уйдёт около *{$expiresShort}*.\n\n";
        $text .= "💰 У тебя: *{$haveGold}* 🪙";

        if ($goldPrice <= 0) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $text . "\n\n_Лот не выставлен на продажу._",
                'parse_mode' => 'Markdown',
            ]);
        }

        if ($haveGold < $goldPrice) {
            $need = $goldPrice - $haveGold;
            $text .= "\n\n❌ _Не хватает {$need} 🪙._";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🗺 Карта',     'callback_data' => 'move']],
                    [['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']],
                ],
            ];
        } else {
            $text .= "\n\n✅ _Готов к покупке._";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => "🛍 Купить ({$goldPrice} 🪙)", 'callback_data' => "caravanBuyDrone_{$caravanId}"]],
                    [['text' => '🗺 Карта', 'callback_data' => 'move'], ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']],
                ],
            ];
        }

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * W14a (ADR-068): рендер богатого каравана — список товаров + кнопка покупки
     * на каждый (reuse caravanBuyAll_<id>; каждый товар = обычная caravan-строка).
     *
     * @param list<array<string,mixed>> $groupRows
     */
    private function renderBundle(int $chatId, array $groupRows, int $haveGold, string $expiresShort, bool $bargained = false, int $tradingKarma = 0, int $playerFactionId = 0): ServerResponse
    {
        $pct = $bargained ? $this->service->bargainDiscountPct($tradingKarma) : 0;
        // W15: faction-affinity богатого каравана (все строки группы — один faction_id).
        $caravanFactionId = isset($groupRows[0]) ? $this->extractInt($groupRows[0], 'faction_id') : 0;

        $text  = "🚛 *Богатый караван*\n\n";
        $text .= "Большой обоз с несколькими товарами. Торговец предлагает:\n\n";
        $affinityLine = $this->affinityLine($caravanFactionId, $playerFactionId);
        if ($affinityLine !== '') {
            $text .= $affinityLine;
        }

        $buttons = [];
        foreach ($groupRows as $row) {
            $rawResId   = $row['resource_id'] ?? null;
            $resourceId = is_numeric($rawResId) ? (int) $rawResId : 0;
            $rawId      = $row['id'] ?? null;
            $rowId      = is_numeric($rawId) ? (int) $rawId : 0;
            $rawQty     = $row['quantity'] ?? null;
            $rawPrice   = $row['price_per_unit'] ?? null;
            $qty        = is_numeric($rawQty)   ? (int) $rawQty   : 0;
            $basePrice  = is_numeric($rawPrice) ? (int) $rawPrice : 0;
            $resName    = $this->resolveResourceName($resourceId);

            if ($qty <= 0 || $basePrice <= 0) {
                $text .= "📦 *{$resName}* — _распродано_\n";
                continue;
            }
            // W15 faction-affinity → W14b bargain поверх.
            $factionPrice = $this->service->applyFactionAffinity($basePrice, $caravanFactionId, $playerFactionId);
            $price = $bargained ? $this->service->applyBargain($factionPrice, $tradingKarma) : $factionPrice;
            if ($bargained && $price < $basePrice) {
                $text .= "📦 *{$resName}* — {$qty} шт. по *{$price}* 🪙 _(торг, было {$basePrice})_\n";
            } else {
                $text .= "📦 *{$resName}* — {$qty} шт. по *{$price}* 🪙\n";
            }

            if ($haveGold >= $price && $rowId > 0) {
                $canBuy   = min($qty, (int) floor($haveGold / $price));
                $callback = $bargained ? "caravanBuyBargain_{$rowId}" : "caravanBuyAll_{$rowId}";
                $buttons[] = [[
                    'text'          => "🛍 {$resName} ×{$canBuy} (" . ($canBuy * $price) . " 🪙)",
                    'callback_data' => $callback,
                ]];
            }
        }

        $text .= "\n⏱ Караван уйдёт около *{$expiresShort}*.\n";
        $text .= "💰 У тебя: *{$haveGold}* 🪙";
        if ($bargained && $pct > 0) {
            $text .= "\n_Договорная цена (торг −{$pct}%)._";
        }
        if ($buttons === []) {
            $text .= "\n\n❌ _Не хватает золота ни на один товар (или всё распродано)._";
        }

        // W14b: «Торговаться» — только в обычном режиме при включённом торге.
        if (! $bargained && $this->service->bargainEnabled() && $this->service->bargainDiscountPct($tradingKarma) > 0) {
            $hagglePct = $this->service->bargainDiscountPct($tradingKarma);
            $buttons[] = [['text' => "💱 Торговаться (−{$hagglePct}%)", 'callback_data' => 'caravanLookBargain']];
        }
        $buttons[] = [
            ['text' => '🗺 Карта', 'callback_data' => 'move'],
            ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
        ];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $buttons]),
        ]);
    }

    /** W15 (ADR-069) — статус-строка faction-affinity (пусто если выключено/нейтральный караван). */
    private function affinityLine(int $caravanFactionId, int $playerFactionId): string
    {
        if (! $this->service->factionAffinityEnabled() || $caravanFactionId <= 0) {
            return '';
        }
        $fname = $this->factionName($caravanFactionId);
        return match ($this->service->factionAffinity($caravanFactionId, $playerFactionId)) {
            'member' => "🤝 *Свой караван* ({$fname}) — скидка члену фракции.\n",
            'rival'  => "⚔️ *Караван соперников* ({$fname}) — наценка чужой фракции.\n",
            default  => "🏳 Караван фракции *{$fname}* (нейтрально к тебе).\n",
        };
    }

    private function factionName(int $id): string
    {
        return [1 => 'Милитари', 2 => 'Партизаны', 3 => 'Инженеры', 4 => 'Фермеры'][$id] ?? '???';
    }

    private function resolveResourceName(int $resourceId): string
    {
        if ($resourceId <= 0) {
            return '???';
        }
        $row = $this->resModel->find($resourceId);
        if ($row === null) {
            return '???';
        }
        // ResourceModel::find() возвращает ResourceEntity (F1.4). Читаем через ArrayAccess.
        $name = $row['name'] ?? null;
        return is_string($name) && $name !== '' ? $name : '???';
    }

    private function errReply(int $chatId, string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
        ]);
        return Request::sendMessage(['chat_id' => $chatId, 'text' => $msg]);
    }
}

<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\CallbackQuery;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\Services\Notifications\MediaSender;
use App\Services\Player\Trade\ResourceTradeService;

/**
 * 🧺 Оптовая продажа ресурсов (Asana «В меню продажи ресурсов добавить кнопки
 * оптовой продажи», ADR-096).
 *
 * «Продать N% всех ресурсов» из экрана выбора редкости (scope=all) и внутри
 * конкретной редкости (scope=rarity). Доля берётся от КАЖДОГО запаса (floor);
 * продаются только ходовые ресурсы (sell_price>0, is_tradeable=1) — расчёт в
 * {@see ResourceTradeService::planBulkSale()}.
 *
 * 🔴 Необратимо + крупные суммы → ОБЯЗАТЕЛЬНЫЙ шаг подтверждения (выбор владельца,
 * 2026-06-03): тап «N%» → предпросмотр «будет продано ~X видов на ~Y💰» →
 * [✅ Да, продать] / [⬅️ Назад]. Сделку выполняет ТОЛЬКО confirm-ветка (go_*),
 * через ResourceTradeService::bulkSellResources (атомарно, золото одним апдейтом).
 *
 * Exact-роут 'bulkSell' (CallbackRoutes); хвосты разбирает сам через explode('_')
 * — НЕ prefix (урок мёртвых `npcAct_` ADR-089). Текстовые экраны (editTextOrSend)
 * полноценны в media-off (caption несёт весь смысл). Проценты и killswitch —
 * live-tunable GameSettings economy.bulk_sell.* (ADR-024). Discoverability: кнопки
 * сидят прямо на экранах продажи (SellAction / SellResourceAction).
 */
class BulkSellAction extends BaseAction
{
    use GameSettingsReaderTrait;

    public const KEY_ENABLED      = 'economy.bulk_sell.enabled';
    public const KEY_PERCENTS     = 'economy.bulk_sell.percent_options';
    public const DEFAULT_PERCENTS = '10,25,50,100';

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        if (! $this->gsBool(self::KEY_ENABLED, true)) {
            return $this->screen(
                '🧺 Оптовая продажа временно недоступна.',
                [[['text' => '🛒 Магазин', 'callback_data' => 'shop']]]
            );
        }

        $params   = explode('_', (string) $this->callbackQuery->getData());
        $count    = count($params);
        $percents = self::parsePercents($this->gsString(self::KEY_PERCENTS, self::DEFAULT_PERCENTS));

        // bulkSell
        //  └── all_{pct}            → предпросмотр (все ресурсы)
        //  └── rarity_{r}_{pct}     → предпросмотр (редкость r)
        //  └── go_all_{pct}         → выполнить (все)
        //  └── go_rarity_{r}_{pct}  → выполнить (редкость r)

        // Выполнение (go) — проверяем раньше (длиннее), сделку делает только эта ветка.
        if ($count >= 4 && $params[1] === 'go') {
            if ($params[2] === 'all' && $count === 4) {
                return $this->execute($character, null, (int) $params[3], $percents);
            }
            if ($params[2] === 'rarity' && $count === 5) {
                return $this->execute($character, (int) $params[3], (int) $params[4], $percents);
            }
        }

        // Предпросмотр (подтверждение).
        if ($count === 3 && $params[1] === 'all') {
            return $this->preview($character, null, (int) $params[2], $percents);
        }
        if ($count === 4 && $params[1] === 'rarity') {
            return $this->preview($character, (int) $params[2], (int) $params[3], $percents);
        }

        return $this->backToScope(null);
    }

    /**
     * Шаг подтверждения: оценка, что и на сколько будет продано (без мутаций).
     *
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     * @param list<int> $percents
     */
    private function preview($character, ?int $rarity, int $percent, array $percents): ServerResponse
    {
        if (! in_array($percent, $percents, true) || ! $this->validRarity($rarity)) {
            return $this->backToScope($rarity);
        }

        $preview    = (new ResourceTradeService())->bulkSellPreview($this->charArray($character), $percent, $rarity);
        $scopeTitle = $rarity === null ? 'всех ресурсов' : "ресурсов редкости {$rarity}";

        if ($preview['typesCount'] <= 0 || $preview['totalQty'] <= 0) {
            return $this->screen(
                "🧺 *Оптовая продажа*\n\n"
                . "Продавать по *{$percent}%* {$scopeTitle} сейчас нечего — нет ходовых ресурсов в достаточном объёме.\n\n"
                . "_Оптом продаются только ресурсы с ценой больше 0; при {$percent}% мелкие стопки могут давать 0 единиц._",
                [$this->backRow($rarity)]
            );
        }

        $goScope     = $rarity === null ? "all_{$percent}" : "rarity_{$rarity}_{$percent}";
        $headLabel   = $percent >= 100 ? "ВСЁ — {$scopeTitle}" : "{$percent}% {$scopeTitle}";
        $confirmText = $percent >= 100 ? '✅ Да, продать всё' : "✅ Да, продать {$percent}%";
        $shareNote   = $percent >= 100
            ? '_Будут проданы все ходовые ресурсы в этом объёме. Реальная цена может отличаться от спроса. Действие необратимо._'
            : '_Доля берётся от каждого запаса. Реальная цена может отличаться от спроса. Действие необратимо._';

        $text = "🧺 *Оптовая продажа — {$headLabel}*\n\n"
            . "Будет продано: *{$preview['typesCount']}* вид(ов), всего *" . number_format($preview['totalQty']) . "* ед.\n"
            . "Примерная выручка: *~" . number_format($preview['totalGold']) . "* 💰\n\n"
            . $shareNote . "\n\n"
            . "Продолжить?";

        return $this->screen($text, [
            [['text' => $confirmText, 'callback_data' => "bulkSell_go_{$goScope}"]],
            $this->backRow($rarity),
        ]);
    }

    /**
     * Подтверждённое выполнение оптовой продажи.
     *
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     * @param list<int> $percents
     */
    private function execute($character, ?int $rarity, int $percent, array $percents): ServerResponse
    {
        if (! in_array($percent, $percents, true) || ! $this->validRarity($rarity)) {
            return $this->backToScope($rarity);
        }

        $charArr = $this->charArray($character);
        $result  = (new ResourceTradeService())->bulkSellResources($charArr, $percent, $rarity);

        if (! $result['success']) {
            return $this->screen("🧺 {$result['message']}", [$this->backRow($rarity)]);
        }

        $scopeTitle = $rarity === null ? 'всех ресурсов' : "редкости {$rarity}";

        // Логируем оптовый расход сырья в action_log (форензика «куда делись ресурсы?»);
        // тот же человекочитаемый голос, что налог/смерть (story 05/11) и одиночная
        // сделка (story 12) — состав режется «и ещё N» (ResourceTradeService::joinWithLimit),
        // не разносит ленту простынёй на богатом инвентаре.
        $this->logActivity(
            is_numeric($charArr['id'] ?? null) ? (int) $charArr['id'] : null,
            'BULK_SELL',
            ResourceTradeService::describeBulkTrade("Продажа опт {$percent}% {$scopeTitle}", $result['lines'], $result['totalGold'])
        );
        $text = "✅ *Оптовая продажа выполнена*\n\n"
            . "Продано по *{$percent}%* {$scopeTitle}: *{$result['typesSold']}* вид(ов), всего *"
            . number_format($result['totalQty']) . "* ед.\n"
            . "Выручка: *+" . number_format($result['totalGold']) . "* 💰";

        return $this->screen($text, [
            [
                ['text' => '💰 Продать ещё', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить',      'callback_data' => 'buy'],
            ],
            [
                ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ],
        ]);
    }

    private function validRarity(?int $rarity): bool
    {
        return $rarity === null || ($rarity >= 1 && $rarity <= 10);
    }

    /**
     * Ряд кнопок «⬅️ Назад» (на нужный экран продажи) + «🛒 Магазин».
     *
     * @return list<array{text:string,callback_data:string}>
     */
    private function backRow(?int $rarity): array
    {
        $back = $rarity === null
            ? ['text' => '⬅️ Назад', 'callback_data' => 'sell']
            : ['text' => '⬅️ Назад', 'callback_data' => "sellResource_rarity_{$rarity}"];

        return [$back, ['text' => '🛒 Магазин', 'callback_data' => 'shop']];
    }

    private function backToScope(?int $rarity): ServerResponse
    {
        return $this->screen('🧺 Действие недоступно. Вернитесь к продаже.', [$this->backRow($rarity)]);
    }

    /**
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     * @return array<string,mixed>
     */
    private function charArray($character): array
    {
        if ($character instanceof \App\Entities\CharacterEntity) {
            return $character->toArray();
        }
        return $character; // по сигнатуре уже array<string,mixed>
    }

    /**
     * Парсер CSV процентов из GameSettings → отсортированный уникальный список (1..100).
     * Пустой/мусорный список → дефолт [10,25,50]. Чистый, юнит-тестируемый.
     *
     * @return list<int>
     */
    public static function parsePercents(string $csv): array
    {
        $vals = [];
        foreach (explode(',', $csv) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $p = (int) $part;
                if ($p >= 1 && $p <= 100) {
                    $vals[$p] = true;
                }
            }
        }
        $list = array_keys($vals);
        sort($list);

        return $list !== [] ? $list : [10, 25, 50];
    }

    /**
     * Ряд кнопок оптовой продажи для scope ('all' | 'rarity_{r}'). Используется на
     * экранах SellAction (scope=all) и SellResourceAction (scope=rarity_{r}).
     * Чистый, юнит-тестируемый.
     *
     * @param list<int> $percents
     * @return list<array{text:string,callback_data:string}>
     */
    public static function buttonsRow(string $scope, array $percents): array
    {
        $row = [];
        foreach ($percents as $p) {
            // 100% = «продать всё» — отдельная метка (🧺 Всё), не «💰 100%», для ясности.
            $label = $p >= 100 ? '🧺 Всё' : "💰 {$p}%";
            $row[] = ['text' => $label, 'callback_data' => "bulkSell_{$scope}_{$p}"];
        }

        return $row;
    }

    /**
     * Единый рендер экрана (edit-in-place, ADR-018; текст полноценен в media-off).
     *
     * @param list<list<array<string,string>>> $rows
     */
    private function screen(string $text, array $rows): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }
}

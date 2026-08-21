<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Craft\CraftShortfallBuyService;
use App\Services\Craft\CraftShortfallQuote;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Player\Trade\ResourceTradeService;
use App\Services\Telegram\ButtonPacker;
use App\Services\Telegram\Request;
use Config\CraftRecipes;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * Story `craft-shortfall-buy-09` — экран подтверждения докупки недостающего сырья
 * у торговца и проведение сделки. Три callback'а по контракту плана
 * (`docs/specs/craft-shortfall-buy/plan.md` §Contracts):
 *
 *   `craftBuy_<RecipeKey>_<qty>`     — экран подтверждения, числа СТРОГО из `quote()`.
 *   `craftBuyGo_<RecipeKey>_<qty>`   — купить недостающее и сразу начать сборку.
 *   `craftBuyOnly_<RecipeKey>_<qty>` — только положить купленное в рюкзак, сборка не стартует.
 *
 * §Правила сделки: 1) проверить, что крафт вообще МОЖЕТ стартовать (эксклюзивный
 * слот ADR-167, очередь/слоты, база, постройки, золото сборки — единая точка
 * {@see GenericCraftActionStart::checkCanStartWithoutMaterials()}), только потом
 * купить, только потом стартовать; если старт невозможен — покупка не совершается
 * вовсе. 2) Цена пересчитывается тем же `quote()` в момент подтверждения; если она
 * успела измениться с момента показа экрана — новая сумма показывается заново,
 * а не списывается старая (короткий кэш ожидаемой суммы, TTL меньше минуты — цены
 * живые, ADR-175). 3) Отмена крафта возвращает ресурсы по общему правилу игры,
 * золото — никогда, и это сказано ДО тапа.
 *
 * «Просто добрать» намеренно НЕ проходит гейт «может ли крафт стартовать» — это и
 * есть смысл второй двери: верстак занят прямо сейчас, но докупить сырьё впрок
 * игрок всё равно хочет (design-draft.md).
 *
 * Реальный старт крафта после покупки — не копия логики `GenericCraftActionStart`,
 * а прямой вызов её `handle()` с синтетическим callback_data `genericCraft_<Key>_<qty>`
 * (та же связка chat/message для edit-in-place): один код старта, а не два расходящихся.
 */
class CraftShortfallBuyAction extends BaseAction
{
    /** Контракт story 06/07: лимит штук за одну докупку — выше не поднимаем. */
    public const KEY_MAX_UNITS = 'craft.shortfall_buy.max_units_per_purchase';

    /** TTL кэша ожидаемой суммы — меньше минуты (цены живые, ADR-175). */
    private const QUOTE_CACHE_TTL_SEC = 50;

    /**
     * Ревью-находка 4: двойной тап / повтор вебхука Telegram проводили сделку дважды.
     * Один узел, один процесс (`AGENTS.md` профиль) — замка на связке персонаж+рецепт
     * на время самой покупки достаточно; TTL — страховка на случай, если процесс
     * упадёт между `acquireLock()` и `releaseLock()`.
     */
    private const PURCHASE_LOCK_TTL_SEC = 15;

    public const REASON_TEXT = [
        CraftShortfallBuyService::REASON_NOT_TRADEABLE  => 'У торговца нет этого сырья — его можно только добыть.',
        CraftShortfallBuyService::REASON_LEVEL_REQUIRED => 'Нужен более высокий уровень, чтобы купить это сырьё у торговца.',
        CraftShortfallBuyService::REASON_NOT_ON_SALE    => 'Не хватает крафтового компонента — его нельзя купить, только собрать самому.',
        CraftShortfallBuyService::REASON_PROFIT_GATE    => 'Докупка сейчас невыгодна: цена превысит то, что ты выручишь за готовый предмет.',
        CraftShortfallBuyService::REASON_PRICE_UNKNOWN  => 'Не удалось посчитать цену готового предмета — докупка пока недоступна. Собери его сам из добытого сырья.',
        CraftShortfallBuyService::REASON_KILLSWITCH_OFF => 'Докупка у торговца сейчас выключена.',
        CraftShortfallBuyService::REASON_MIN_GOLD       => 'Не хватает золота на докупку.',
    ];

    protected CraftShortfallBuyService $shortfall;
    protected ResourceTradeService     $trade;
    protected GameSettingsService      $settings;

    protected string $prefix    = '';
    protected string $recipeKey = '';
    protected int    $quantity  = 1;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->shortfall = new CraftShortfallBuyService();
        $this->trade     = new ResourceTradeService();
        $this->settings  = new GameSettingsService();

        $parts = explode('_', $callbackQuery->getData());
        $this->prefix    = $parts[0];
        $this->recipeKey = $parts[1] ?? '';
        if (isset($parts[2]) && is_numeric($parts[2])) {
            $this->quantity = max(1, (int) $parts[2]);
        }
    }

    public function handle(): ServerResponse
    {
        if ($this->recipeKey === '') {
            return $this->sendError('Не указан рецепт для докупки.');
        }

        /** @var CraftRecipes $cfg */
        $cfg    = config('CraftRecipes');
        $recipe = $cfg->get($this->recipeKey);
        if ($recipe === null) {
            // Находка 13: ключ рецепта приходит из callback_data (изменяемый клиентом
            // ввод) — экранируем ДО подстановки в Markdown-ответ, как везде в файле.
            return $this->sendError('Неизвестный рецепт: ' . $this->safe($this->recipeKey));
        }

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь или персонаж не найден.');
        }

        // `CharacterModel` отдаёт `CharacterEntity`, не `array` — приводим ОДИН раз
        // здесь (`toArray()`, не `toRawArray()`: касты БД-строки в типы нужны, иначе
        // `gold`/`level` придут строками из сырой БД-строки). Дальше по методу везде
        // плоский `array`, как и ожидает `ResourceTradeService::buyResource()` под
        // `strict_types` (см. memory `feedback_entity_strict_array_typehint_trap`).
        $character = $character instanceof \App\Entities\CharacterEntity ? $character->toArray() : $character;

        // Ревью-находка 18 (мелкая, вытекает из below_effect_text ключа в админке):
        // «0 — фича фактически выключена, докупить нельзя ни одной единицы» — раньше
        // `maxUnitsPerPurchase()` подменяла 0 единицей и докупка молча оставалась
        // включённой. Гейт — раньше расчёта quote(), деньги не читаются вовсе.
        $maxUnits = $this->maxUnitsPerPurchase();
        if ($maxUnits <= 0) {
            return $this->sendError(self::REASON_TEXT[CraftShortfallBuyService::REASON_KILLSWITCH_OFF], $recipe);
        }

        // Контракт story 06/07: лимит штук за одну докупку. Выше настройки не
        // поднимаем — от конвейера защищает именно абсолютный лимит, не доля.
        $this->quantity = min($this->quantity, $maxUnits);

        return match ($this->prefix) {
            'craftBuy'     => $this->showConfirmation($recipe, $character),
            'craftBuyGo'   => $this->confirmAndBuy($recipe, $character, $user, startCraft: true),
            'craftBuyOnly' => $this->confirmAndBuy($recipe, $character, $user, startCraft: false),
            default        => $this->sendError('Неизвестное действие докупки.', $recipe),
        };
    }

    /**
     * @param array<string,mixed> $recipe
     * @param array<string,mixed> $character
     */
    private function showConfirmation(array $recipe, array $character): ServerResponse
    {
        $quote = $this->shortfall->quote($character, $this->recipeForQuote($recipe), $this->quantity);
        if (!$quote->available) {
            return $this->sendRefusal($quote, $recipe);
        }

        // Порядок сделки начинается ЗДЕСЬ, а не на втором тапе: показывать кнопку
        // «докупить и собрать», которая всё равно упрётся в отказ на следующем шаге,
        // — обман. Гейт не тратит золото, только читает.
        //
        // Находка 3: гейт судит по золоту, которое ОСТАНЕТСЯ после докупки, а не по
        // снапшоту ДО неё — иначе рецепт с `gold_required` пропускал показ кнопки
        // «докупить и собрать», покупка списывала золото, а старт всё равно отказывал.
        $gateError = $this->canStartError($recipe, $this->characterAfterPurchase($character, $quote));

        $this->cacheQuoteTotal($this->intField($character, 'id'), $quote->total);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $this->confirmationText($recipe, $quote, $character, $gateError),
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($this->confirmationKeyboard($recipe, $gateError === null)),
        ]);
    }

    /**
     * @param array<string,mixed> $recipe
     * @param array<string,mixed> $character
     * @param array<string,mixed> $user
     */
    protected function confirmAndBuy(array $recipe, array $character, array $user, bool $startCraft): ServerResponse
    {
        $charId = $this->intField($character, 'id');

        // Цена — ВСЕГДА заново, тем же quote() (ADR-175: цены живые). То, что было
        // на экране минуту назад, здесь не участвует.
        $quote = $this->shortfall->quote($character, $this->recipeForQuote($recipe), $this->quantity);
        if (!$quote->available) {
            return $this->sendRefusal($quote, $recipe);
        }

        $cachedTotal = $this->cachedQuoteTotal($charId);
        if ($this->priceChanged($cachedTotal, $quote->total)) {
            // Цена успела измениться, пока игрок решал(а) — списывать старую сумму
            // нельзя, переспрашиваем на актуальной.
            $this->cacheQuoteTotal($charId, $quote->total);
            $gateError = $startCraft ? $this->canStartError($recipe, $this->characterAfterPurchase($character, $quote)) : null;

            Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
                'text'              => 'Цена обновилась, пока ты решал(а) — проверь и подтверди снова.',
                'show_alert'        => true,
            ]);

            return Request::sendMessage([
                'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'         => "⚠️ *Цена изменилась* — торговец пересчитал курс, пока ты решал(а).\n\n"
                    . $this->confirmationText($recipe, $quote, $character, $gateError),
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($this->confirmationKeyboard($recipe, $gateError === null)),
            ]);
        }

        if (!$startCraft) {
            $purchase = $this->executePurchase($character, $quote, $this->intField($user, 'id'));

            return $purchase['success']
                ? $this->sendPurchasedOnly($recipe, $quote)
                : $this->sendError($purchase['message'], $recipe);
        }

        // 🔴 Порядок сделки (## Правила сделки плана): СНАЧАЛА проверить, что крафт
        // вообще может стартовать, ПОТОМ купить. `attemptStartCraft()` — единственное
        // место, где закодирован этот порядок; переворот порядка внутри него красит
        // тест `CraftShortfallBuyActionTest::testOrderGateBeforePurchase*`.
        $attempt = $this->attemptStartCraft($recipe, $character, $quote, $this->intField($user, 'id'));
        if ($attempt['gateError'] !== null) {
            $this->logRejected($charId, "CRAFT_SHORTFALL_BUY_{$this->recipeKey}", 'cannot_start');

            return $this->sendError($attempt['gateError'], $recipe);
        }
        if (!$attempt['purchased']) {
            return $this->sendError($attempt['message'], $recipe);
        }

        return $this->startCraftAfterPurchase();
    }

    /**
     * 🔴 Единственное место, где закодирован порядок сделки: гейт «может ли крафт
     * стартовать» вызывается СТРОГО раньше покупки, и если он отказал — покупка
     * не совершается вовсе (золото не списывается). Чистая оркестрация без
     * Telegram I/O — прямой объект теста порядка.
     *
     * @param array<string,mixed> $recipe
     * @param array<string,mixed> $character
     * @return array{gateError:string|null,purchased:bool,message:string}
     */
    protected function attemptStartCraft(array $recipe, array $character, CraftShortfallQuote $quote, int $chatId): array
    {
        // Находка 3: гейт обязан судить по золоту, которое останется ПОСЛЕ докупки —
        // иначе рецепт с `gold_required` пропускал бы гейт по снапшоту ДО покупки,
        // покупка списывала бы золото, а `GenericCraftActionStart::handle()` при
        // реальном старте отказал бы уже после того, как деньги ушли.
        $gateError = $this->canStartError($recipe, $this->characterAfterPurchase($character, $quote));
        if ($gateError !== null) {
            return ['gateError' => $gateError, 'purchased' => false, 'message' => ''];
        }

        $purchase = $this->executePurchase($character, $quote, $chatId);

        return ['gateError' => null, 'purchased' => $purchase['success'], 'message' => $purchase['message']];
    }

    /**
     * Копия `$character` с золотом, уменьшенным на `quote->total` — то, что реально
     * останется ПОСЛЕ докупки. Единая точка для находки 3: любой гейт «может ли
     * крафт стартовать», вызываемый в рамках сделки докупки, обязан судить по этому
     * снапшоту, а не по золоту ДО покупки.
     *
     * @param array<string,mixed> $character
     * @return array<string,mixed>
     */
    protected function characterAfterPurchase(array $character, CraftShortfallQuote $quote): array
    {
        $goldRaw = $character['gold'] ?? 0;
        $gold    = is_numeric($goldRaw) ? (int) $goldRaw : 0;

        $character['gold'] = max(0, $gold - $quote->total);

        return $character;
    }

    /**
     * Живые цены (ADR-175) — то, что показали минуту назад, могло устареть.
     *
     * Находка 5: отсутствие закэшированной суммы (`cachedTotal === null` — TTL истёк
     * или игрок дошёл сюда напрямую по `craftBuyGo_*`, минуя экран подтверждения)
     * ТОЖЕ считается изменением цены: сделка не проводится по цене, которую игрок
     * никогда не подтверждал, а переспрашивает.
     */
    protected function priceChanged(?int $cachedTotal, int $freshTotal): bool
    {
        return $cachedTotal === null || $cachedTotal !== $freshTotal;
    }

    /**
     * §Правила сделки п.1 — единая точка «может ли крафт вообще стартовать»,
     * без учёта сырья/крафт-компонентов (та же проверка, что и обычный старт).
     *
     * @param array<string,mixed> $recipe
     * @param array<string,mixed> $character
     */
    protected function canStartError(array $recipe, array $character): ?string
    {
        $taskNameRaw = $recipe['task_name'] ?? null;
        if (!is_string($taskNameRaw) || $taskNameRaw === '') {
            return 'Конфигурационная ошибка рецепта: не задана задача крафта.';
        }
        $taskRowRaw = $this->taskModel->where('name', $taskNameRaw)->first();
        if (!is_array($taskRowRaw)) {
            return "Задача '{$taskNameRaw}' не найдена в базе.";
        }
        /** @var array<string,mixed> $taskRow */
        $taskRow = $taskRowRaw;

        return (new GenericCraftActionStart($this->callbackQuery))
            ->checkCanStartWithoutMaterials($this->recipeKey, $recipe, $character, $taskRow, $this->quantity);
    }

    /**
     * Покупает все недостающие покупаемые позиции одной транзакцией — если
     * какая-то строка сорвалась (гонка на редком ресурсе), откатываются все
     * уже проведённые в рамках этого вызова: частичная оплата не остаётся
     * у игрока на руках как потеря.
     *
     * Находка 2: `buyLine()` списывает только базовую цену строки (то же, что видит
     * магазин) — наценку торговца (`quote->total - Σ базовых цен`) нигде не списывал
     * никто. Разницу списываем ОДНИМ явным `chargeExtraGold()` в конце той же
     * транзакции — так фактически списанное всегда равно `quote->total`, что было
     * показано на экране подтверждения, а не сумме без наценки.
     *
     * Находка 4: единственный choke-point обеих сделочных кнопок (`craftBuyGo`
     * покупает-и-стартует, `craftBuyOnly` только кладёт в рюкзак) — cache-замок на
     * связке персонаж+рецепт держится на всё время метода. Повторный тап/повтор
     * вебхука, пришедший ПОКА первый ещё не отпустил замок, получает честный отказ,
     * а не вторую покупку (и, как следствие для `craftBuyGo`, не второй крафт в
     * очередь — `startCraftAfterPurchase()` вызывается только если `executePurchase()`
     * вернул `success`).
     *
     * Находка 9/6: транзакция и откат больше не спрятаны за тестовой заглушкой —
     * DB-обвязка (`beginPurchaseTransaction`/`commitPurchaseTransaction`/
     * `rollbackPurchaseTransaction`) и сама покупка строки (`buyLine`) вынесены
     * в переопределяемые seam'ы (тот же приём, что `CraftPoolConsumptionTest` уже
     * применяет к `GenericCraftActionStart`), а оркестрация — порядок вызовов, when
     * откатывать, что списывать сверх базовой цены — настоящая и проверяется тестом
     * напрямую. Любой сбой внутри транзакции (включая непредвиденное исключение из
     * seam'ов) ловится здесь: откат явный, ответ игроку честный, наружу ничего не летит.
     *
     * @param array<string,mixed>    $character
     * @return array{success:bool,message:string}
     */
    protected function executePurchase(array $character, CraftShortfallQuote $quote, int $chatId): array
    {
        $charId  = $this->intField($character, 'id');
        $lockKey = $this->purchaseLockKey($charId);

        if (!$this->acquireLock($lockKey, self::PURCHASE_LOCK_TTL_SEC)) {
            return ['success' => false, 'message' => 'Эта докупка уже обрабатывается — подожди пару секунд и проверь инвентарь.'];
        }

        try {
            return $this->runPurchaseTransaction($character, $quote, $chatId, $charId);
        } finally {
            $this->releaseLock($lockKey);
        }
    }

    /**
     * @param array<string,mixed> $character
     * @return array{success:bool,message:string}
     */
    private function runPurchaseTransaction(array $character, CraftShortfallQuote $quote, int $chatId, int $charId): array
    {
        $lines = array_values(array_filter(
            $quote->lines,
            static fn (array $line): bool => $line['buyable'] && $line['gap'] > 0 && $line['resourceId'] > 0
        ));
        if ($lines === []) {
            return ['success' => false, 'message' => 'Докупать нечего — недостача уже закрыта.'];
        }

        $this->beginPurchaseTransaction();

        try {
            $spent       = 0;
            $boughtNames = [];
            foreach ($lines as $line) {
                $result = $this->buyLine($character, $line, $chatId);
                if (!$result['success']) {
                    $this->rollbackPurchaseTransaction();

                    return ['success' => false, 'message' => $result['message']];
                }
                $spent        += (int) ($result['cost'] ?? 0);
                $boughtNames[] = $line['name'] . ' x' . $line['gap'];
            }

            // Находка 2 — наценка нигде не списывалась: то, что реально ушло за
            // строки (базовая цена), почти всегда меньше `quote->total` (наценка +
            // `min_markup_gold`). Разницу списываем явно, той же операцией, которой
            // покупка уже пользуется для честного списания (см. `decreaseGold`
            // twin-fix памяти `feedback_lost_update`).
            $markupGold = $quote->total - $spent;
            if ($markupGold > 0 && !$this->chargeExtraGold($charId, $markupGold)) {
                $this->rollbackPurchaseTransaction();

                return ['success' => false, 'message' => 'Не удалось списать наценку торговца — попробуй ещё раз.'];
            }
            if ($markupGold > 0) {
                $spent += $markupGold;
            }

            if (!$this->commitPurchaseTransaction()) {
                return ['success' => false, 'message' => 'Не удалось провести докупку — попробуй ещё раз.'];
            }
        } catch (\Throwable $e) {
            // Находка 6: сбой внутри транзакции обязан закончиться явным откатом и
            // честным ответом, а не исключением, летящим наверх в Telegram-обвязку.
            $this->rollbackPurchaseTransaction();
            log_message('error', "[CraftShortfallBuyAction:{$this->recipeKey}] сбой докупки для character {$charId}: " . $e->getMessage());

            return ['success' => false, 'message' => 'Не удалось провести докупку — попробуй ещё раз.'];
        }

        // Событие докупки — сумма, доля, предмет: без этого судить о пороге
        // тревоги (## Порог тревоги плана) нечем.
        $this->logActivity(
            $charId > 0 ? $charId : null,
            'CRAFT_SHORTFALL_BUY',
            sprintf(
                'recipe=%s items=%s spent=%d share=%.2f',
                $this->recipeKey,
                implode(',', $boughtNames),
                $spent,
                $quote->share
            )
        );

        return ['success' => true, 'message' => ''];
    }

    /**
     * Seam: покупка одной строки недостачи. Единственная точка, которая реально
     * трогает БД/золото/инвентарь через `ResourceTradeService` — тест переопределяет
     * её, оставляя транзакционную оркестрацию `runPurchaseTransaction()` настоящей.
     *
     * @param array<string,mixed>                                  $character
     * @param array{resourceId:int,name:string,gap:int,...}        $line
     * @return array{success:bool,message:string,cost?:int}
     */
    protected function buyLine(array $character, array $line, int $chatId): array
    {
        return $this->trade->buyResource($character, $line['resourceId'], $line['gap'], $chatId);
    }

    /** Seam: списание наценки — тем же атомарным `decreaseGold()`, что и сама покупка. */
    protected function chargeExtraGold(int $charId, int $amount): bool
    {
        return $this->characterModel->decreaseGold($charId, (float) $amount);
    }

    /**
     * Seam: начало DB-транзакции покупки. Без возврата handle'а — `Config\Database::connect()`
     * без явной группы отдаёт ОДНО и то же закэшированное соединение на запрос (CI4 кэширует
     * по имени группы), поэтому `commitPurchaseTransaction()`/`rollbackPurchaseTransaction()`
     * зовут `connect()` заново и попадают на то же соединение — передавать handle не нужно,
     * а тестовым двойникам не приходится подделывать типизированный `BaseConnection`.
     */
    protected function beginPurchaseTransaction(): void
    {
        \Config\Database::connect()->transStart();
    }

    /** Seam: коммит — `false`, если транзакция упала. */
    protected function commitPurchaseTransaction(): bool
    {
        $db = \Config\Database::connect();
        $db->transComplete();

        return $db->transStatus() !== false;
    }

    /** Seam: откат — вызывается на любом сорвавшемся шаге покупки. */
    protected function rollbackPurchaseTransaction(): void
    {
        \Config\Database::connect()->transRollback();
    }

    /**
     * Находка 4: cache-замок сделки. `get() !== null` перед `save()` — не CAS, но
     * один узел и один процесс (профиль проекта) делают гонку между этими двумя
     * вызовами практически недостижимой, а cache-хендлер в проекте (`file`) её
     * не поддерживает атомарно в принципе.
     */
    protected function acquireLock(string $key, int $ttlSec): bool
    {
        $cache = \Config\Services::cache();
        if ($cache->get($key) !== null) {
            return false;
        }

        return $cache->save($key, 1, $ttlSec);
    }

    protected function releaseLock(string $key): void
    {
        \Config\Services::cache()->delete($key);
    }

    private function purchaseLockKey(int $charId): string
    {
        return 'craft_shortfall_purchase_lock_' . $charId . '_' . $this->recipeKey;
    }

    /**
     * Прямой вызов уже проверенной цепочки старта — синтетический callback_data
     * `genericCraft_<Key>_<qty>` на ТОЙ ЖЕ связке chat/message (edit-in-place не рвётся).
     */
    private function startCraftAfterPurchase(): ServerResponse
    {
        $raw         = $this->callbackQuery->getRawData();
        $raw['data'] = 'genericCraft_' . $this->recipeKey . '_' . $this->quantity;
        $synthetic   = new CallbackQuery($raw, (string) $this->callbackQuery->getBotUsername());

        return (new GenericCraftActionStart($synthetic))->handle();
    }

    /**
     * @param array<string,mixed> $recipe
     */
    private function sendPurchasedOnly(array $recipe, CraftShortfallQuote $quote): ServerResponse
    {
        $itemName = $this->itemName($recipe);
        $text     = "🎒 Докупленное сырьё для *{$itemName}* уже в рюкзаке.\n\n"
            . "Списано: *{$quote->total}* 💰. Осталось золота: *{$quote->goldAfter}* 💰.\n\n"
            . '_Собери, когда будет удобно — верстак никуда не денется._';

        $buttons = [
            ['text' => '🔨 К рецепту', 'callback_data' => $this->recipeInfoCallback($recipe)],
            ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => ButtonPacker::pack($buttons)]),
        ]);
    }

    /**
     * @param array<string,mixed> $recipe
     */
    private function sendRefusal(CraftShortfallQuote $quote, array $recipe): ServerResponse
    {
        $reason = $quote->refusal ?? '';
        $text   = self::REASON_TEXT[$reason] ?? 'Докупка сейчас недоступна.';

        $buttons = [['text' => '⬅️ Назад', 'callback_data' => $this->recipeInfoCallback($recipe)]];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => '🛒 ' . $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => ButtonPacker::pack($buttons)]),
        ]);
    }

    /**
     * Текст экрана подтверждения — единственное место, где числа рендерятся;
     * сами числа целиком приходят из `quote()` (ничего не пересчитывается).
     *
     * @param array<string,mixed> $recipe
     * @param array<string,mixed> $character
     */
    protected function confirmationText(array $recipe, CraftShortfallQuote $quote, array $character, ?string $gateError): string
    {
        $itemName = $this->itemName($recipe);
        $lines    = [
            "🛒 *Докупка недостающего для «{$itemName}»*" . ($this->quantity > 1 ? " ×{$this->quantity}" : ''),
            '',
            '*Не хватает:*',
        ];

        foreach ($quote->lines as $line) {
            if (!$line['buyable'] || $line['gap'] <= 0) {
                continue;
            }
            $unit = $this->trade->formatUnitPrice((float) $line['unitPrice']);
            $lines[] = '• ' . $this->safe((string) $line['name']) . ' ×' . (int) $line['gap']
                . " — {$unit} 💰/шт. = " . (int) round((float) $line['lineTotal']) . ' 💰';
        }

        $lines[] = '';
        $lines[] = "Наценка торговца за срочность: *{$quote->markupPct}%* (уже в сумме ниже).";
        $lines[] = "Итого спишется: *{$quote->total}* 💰";
        $lines[] = "Останется золота: *{$quote->goldAfter}* 💰";

        $goldBeforeRaw = $character['gold'] ?? 0;
        $goldBefore    = is_numeric($goldBeforeRaw) ? (int) $goldBeforeRaw : 0;
        if ($this->eatsMoreThanHalfGold($quote->total, $goldBefore)) {
            $lines[] = '';
            $lines[] = "❗ Это больше половины твоего золота ({$quote->total} из {$goldBefore}).";
        }

        $lines[] = '';
        $lines[] = '_Отмена крафта вернёт ресурсы — золото не вернётся никогда._';

        $limit = $this->maxUnitsPerPurchase();
        $lines[] = "_Докупка ограничена {$limit} шт. за раз._";

        if ($gateError !== null) {
            $lines[] = '';
            $lines[] = "🔒 Собрать прямо сейчас нельзя: {$gateError}";
            $lines[] = 'Можно всё равно докупить впрок — кнопкой «Просто добрать».';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $recipe
     * @return array{inline_keyboard:list<list<array<string,string>>>}
     */
    protected function confirmationKeyboard(array $recipe, bool $canStartCraft): array
    {
        $buttons = [];
        if ($canStartCraft) {
            $buttons[] = ['text' => '🛒 Докупить и собрать', 'callback_data' => "craftBuyGo_{$this->recipeKey}_{$this->quantity}"];
        }
        $buttons[] = ['text' => '🎒 Просто добрать', 'callback_data' => "craftBuyOnly_{$this->recipeKey}_{$this->quantity}"];
        $buttons[] = ['text' => '⬅️ Назад', 'callback_data' => $this->recipeInfoCallback($recipe)];

        return ['inline_keyboard' => ButtonPacker::pack($buttons)];
    }

    /** 🔴 «Больше половины золота» — честная цифра последствия, а не запрет. */
    protected function eatsMoreThanHalfGold(int $total, int $goldBefore): bool
    {
        return $goldBefore > 0 && $total * 2 > $goldBefore;
    }

    protected function maxUnitsPerPurchase(): int
    {
        return $this->resolveMaxUnits($this->settings->get(self::KEY_MAX_UNITS, 1));
    }

    /**
     * Находка 18: `below_effect_text` ключа в админке обещает «0 — фича фактически
     * выключена, докупить нельзя ни одной единицы» — раньше `max(1, $val)` подменял
     * 0 единицей, и обещание не выполнялось. `0` (и любое отрицательное значение)
     * обязаны остаться нулём; нечисловое/отсутствующее — единственный случай,
     * где остаётся дефолт 1.
     */
    protected function resolveMaxUnits(mixed $raw): int
    {
        $val = is_numeric($raw) ? (int) $raw : 1;

        return max(0, $val);
    }

    /**
     * @param array<string,mixed> $recipe
     */
    private function itemName(array $recipe): string
    {
        $raw = $recipe['item_name_rus'] ?? null;

        return is_string($raw) && $raw !== '' ? $this->safe($raw) : 'этот предмет';
    }

    /**
     * @param array<string,mixed> $recipe
     */
    private function recipeInfoCallback(array $recipe): string
    {
        $raw = $recipe['info_callback'] ?? null;

        return is_string($raw) && $raw !== '' ? $raw : 'craft';
    }

    private function cacheQuoteTotal(int $charId, int $total): void
    {
        if ($charId <= 0) {
            return;
        }
        \Config\Services::cache()->save($this->quoteCacheKey($charId), $total, self::QUOTE_CACHE_TTL_SEC);
    }

    private function cachedQuoteTotal(int $charId): ?int
    {
        if ($charId <= 0) {
            return null;
        }
        $raw = \Config\Services::cache()->get($this->quoteCacheKey($charId));

        return is_int($raw) ? $raw : null;
    }

    private function quoteCacheKey(int $charId): string
    {
        return 'craft_shortfall_quote_' . $charId . '_' . $this->recipeKey . '_' . $this->quantity;
    }

    /**
     * Легаси parse_mode='Markdown' не экранирует служебные символы — одиночная
     * `*`/`_` в названии рвёт разметку (см. `CraftShortageService::safe()`).
     */
    protected function safe(string $value): string
    {
        return trim(str_replace(['*', '_', '`', '[', ']'], '', $value));
    }

    /** @param array<string,mixed> $arr */
    private function intField(array $arr, string $key): int
    {
        $raw = $arr[$key] ?? null;

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * `CraftShortfallBuyService::quote()` ожидает узкую форму (## Contracts плана),
     * а `Config\CraftRecipes::get()` отдаёт generic `array<string,mixed>` — форму
     * собираем явно, ничего не выдумывая (сами числа/цену считает только `quote()`).
     *
     * @param array<string,mixed> $recipe
     * @return array{resources?:array<string,int>,crafted_items?:array<string,int>,item_name_eng?:string}
     */
    private function recipeForQuote(array $recipe): array
    {
        $out = [];

        $resourcesRaw = $recipe['resources'] ?? null;
        if (is_array($resourcesRaw)) {
            $out['resources'] = $this->stringIntMap($resourcesRaw);
        }

        $craftedItemsRaw = $recipe['crafted_items'] ?? null;
        if (is_array($craftedItemsRaw)) {
            $out['crafted_items'] = $this->stringIntMap($craftedItemsRaw);
        }

        $itemNameEngRaw = $recipe['item_name_eng'] ?? null;
        if (is_string($itemNameEngRaw)) {
            $out['item_name_eng'] = $itemNameEngRaw;
        }

        return $out;
    }

    /**
     * @param array<mixed,mixed> $raw
     * @return array<string,int>
     */
    private function stringIntMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $name => $qty) {
            if (!is_string($name) || !is_numeric($qty)) {
                continue;
            }
            $out[$name] = (int) $qty;
        }

        return $out;
    }

    /**
     * Находка 15: отказы обязаны нести кнопку назад — раньше `sendError()` уходил
     * без клавиатуры, оставляя игрока в тупике. `$recipe` известен в большинстве
     * мест вызова; когда его нет (ошибка ещё до lookup'а рецепта), ведём на общий
     * `craft` — тот же fallback, что уже даёт `recipeInfoCallback()`.
     *
     * @param array<string,mixed>|null $recipe
     */
    private function sendError(string $message, ?array $recipe = null): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $message,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($this->errorKeyboard($recipe)),
        ]);
    }

    /**
     * Пул кнопки «Назад» для `sendError()` — вынесено из тела метода чистой
     * функцией, чтобы находка 15 («отказы без клавиатуры») проверялась тестом
     * напрямую, а не только источником.
     *
     * @param array<string,mixed>|null $recipe
     * @return array{inline_keyboard:list<list<array<string,string>>>}
     */
    protected function errorKeyboard(?array $recipe): array
    {
        $buttons = [['text' => '⬅️ Назад', 'callback_data' => $recipe !== null ? $this->recipeInfoCallback($recipe) : 'craft']];

        return ['inline_keyboard' => ButtonPacker::pack($buttons)];
    }
}

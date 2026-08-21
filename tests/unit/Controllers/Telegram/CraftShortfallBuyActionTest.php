<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\Commands\Actions\Craft\CraftShortfallBuyAction;
use App\Services\Craft\CraftShortfallBuyService;
use App\Services\Craft\CraftShortfallQuote;
use App\Services\Player\Trade\ResourceTradeService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Тест-дубль: пропускает родительский конструктор (нет реального `CallbackQuery`/БД/
 * Telegram), подменяет два DB/Telegram-зависимых seam'а (`canStartError()`,
 * `executePurchase()`) спаями с логом вызовов и открывает protected-оркестрацию
 * для прямой проверки. Паттерн зеркалит `ShuffleResourcesActionTestDouble`.
 */
final class CraftShortfallBuyActionTestDouble extends CraftShortfallBuyAction
{
    /** @var list<string> порядок вызовов — 'gate' / 'purchase' */
    public array $callLog = [];

    public ?string $canStartErrorReturn = null;

    /** @var list<array<string,mixed>> `$character`, с которым реально позвали canStartError() */
    public array $gateCharacterLog = [];

    /** @var array{success:bool,message:string} */
    public array $executePurchaseReturn = ['success' => true, 'message' => ''];

    public function __construct(string $recipeKey = 'Axe', int $quantity = 1)
    {
        // Намеренно НЕ зовём parent::__construct — нет CallbackQuery/БД, тестируем
        // чистую оркестрацию сделки и рендер текста/клавиатуры.
        $this->recipeKey = $recipeKey;
        $this->quantity  = $quantity;
        $this->trade     = new ResourceTradeService();
    }

    protected function canStartError(array $recipe, array $character): ?string
    {
        $this->callLog[]        = 'gate';
        $this->gateCharacterLog[] = $character;

        return $this->canStartErrorReturn;
    }

    /** @return array{success:bool,message:string} */
    protected function executePurchase(array $character, CraftShortfallQuote $quote, int $chatId): array
    {
        $this->callLog[] = 'purchase';

        return $this->executePurchaseReturn;
    }

    /** @return array{gateError:string|null,purchased:bool,message:string} */
    public function pubAttemptStartCraft(array $recipe, array $character, CraftShortfallQuote $quote, int $chatId): array
    {
        return $this->attemptStartCraft($recipe, $character, $quote, $chatId);
    }

    /** @return array<string,mixed> */
    public function pubCharacterAfterPurchase(array $character, CraftShortfallQuote $quote): array
    {
        return $this->characterAfterPurchase($character, $quote);
    }

    public function pubPriceChanged(?int $cached, int $fresh): bool
    {
        return $this->priceChanged($cached, $fresh);
    }

    public function pubConfirmationText(array $recipe, CraftShortfallQuote $quote, array $character, ?string $gateError): string
    {
        return $this->confirmationText($recipe, $quote, $character, $gateError);
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    public function pubConfirmationKeyboard(array $recipe, bool $canStartCraft): array
    {
        return $this->confirmationKeyboard($recipe, $canStartCraft);
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    public function pubErrorKeyboard(?array $recipe): array
    {
        return $this->errorKeyboard($recipe);
    }

    public function pubEatsMoreThanHalfGold(int $total, int $goldBefore): bool
    {
        return $this->eatsMoreThanHalfGold($total, $goldBefore);
    }

    public function pubSafe(string $value): string
    {
        return $this->safe($value);
    }

    public function pubResolveMaxUnits(mixed $raw): int
    {
        return $this->resolveMaxUnits($raw);
    }

    /** Реальный (не переопределённый) cache-замок — используется для теста примитива. */
    public function pubAcquireLock(string $key, int $ttlSec): bool
    {
        return $this->acquireLock($key, $ttlSec);
    }

    public function pubReleaseLock(string $key): void
    {
        $this->releaseLock($key);
    }

    protected function maxUnitsPerPurchase(): int
    {
        return 1;
    }
}

/**
 * Второй двойник — для находок 2/4/6/9: оставляет `executePurchase()`/`runPurchaseTransaction()`
 * НАСТОЯЩИМИ (не подменёнными), переопределяя только DB/Telegram-touching seam'ы
 * (`buyLine`, `chargeExtraGold`, транзакция, cache-замок) — тот же приём, каким
 * `CraftPoolConsumptionTest` уже проверяет `GenericCraftActionStart` без реальной БД.
 */
final class CraftShortfallBuyActionMoneyPathTestDouble extends CraftShortfallBuyAction
{
    /** @var list<string> */
    public array $callLog = [];

    /** @var array<int,array{success:bool,message:string,cost?:int}> resourceId => результат */
    public array $buyLineResults = [];

    public bool $chargeExtraGoldReturn = true;
    public ?int $chargeExtraGoldCalledWith = null;

    public bool $throwInBuyLine     = false;
    public bool $throwInChargeExtra = false;

    /** `false` — замок уже держит кто-то другой (симуляция двойного тапа). */
    public bool $lockAvailable = true;

    public function __construct(string $recipeKey = 'Axe', int $quantity = 1)
    {
        $this->recipeKey = $recipeKey;
        $this->quantity  = $quantity;
    }

    /** @return array{success:bool,message:string} */
    public function pubExecutePurchase(array $character, CraftShortfallQuote $quote, int $chatId): array
    {
        return $this->executePurchase($character, $quote, $chatId);
    }

    protected function acquireLock(string $key, int $ttlSec): bool
    {
        $this->callLog[] = 'acquireLock';
        if (!$this->lockAvailable) {
            return false;
        }
        $this->lockAvailable = false;

        return true;
    }

    protected function releaseLock(string $key): void
    {
        $this->callLog[] = 'releaseLock';
        $this->lockAvailable = true;
    }

    protected function buyLine(array $character, array $line, int $chatId): array
    {
        $this->callLog[] = 'buyLine:' . $line['resourceId'];
        if ($this->throwInBuyLine) {
            throw new \RuntimeException('гонка на редком ресурсе');
        }

        return $this->buyLineResults[$line['resourceId']]
            ?? ['success' => true, 'message' => '', 'cost' => (int) round($line['gap'] * $line['unitPrice'])];
    }

    protected function chargeExtraGold(int $charId, int $amount): bool
    {
        $this->callLog[]              = 'chargeExtraGold:' . $amount;
        $this->chargeExtraGoldCalledWith = $amount;
        if ($this->throwInChargeExtra) {
            throw new \RuntimeException('сбой списания наценки');
        }

        return $this->chargeExtraGoldReturn;
    }

    protected function beginPurchaseTransaction(): void
    {
        $this->callLog[] = 'begin';
    }

    protected function commitPurchaseTransaction(): bool
    {
        $this->callLog[] = 'commit';

        return true;
    }

    protected function rollbackPurchaseTransaction(): void
    {
        $this->callLog[] = 'rollback';
    }
}

/**
 * Story `craft-shortfall-buy-09` (+ ремонт `craft-shortfall-buy-fix-01`) — экран
 * подтверждения докупки и проведение сделки.
 */
final class CraftShortfallBuyActionTest extends CIUnitTestCase
{
    private function quote(int $total = 10, int $goldAfter = 90, float $share = 0.3): CraftShortfallQuote
    {
        return new CraftShortfallQuote(
            lines: [[
                'resourceId'  => 1,
                'name'        => 'Базальт',
                'need'        => 3,
                'have'        => 1,
                'gap'         => 2,
                'unitPrice'   => 5.0,
                'lineTotal'   => 10.0,
                'buyable'     => true,
                'blockReason' => null,
            ]],
            baseCost: 10.0,
            fullCost: 30.0,
            share: $share,
            markupPct: 15,
            total: $total,
            goldAfter: $goldAfter,
            available: true,
            refusal: null
        );
    }

    /** @param list<array{resourceId:int,name:string,gap:int,unitPrice:float,buyable:bool}> $lines */
    private function quoteWithLines(array $lines, int $total): CraftShortfallQuote
    {
        return new CraftShortfallQuote(
            lines: array_map(static fn (array $l): array => $l + [
                'need' => $l['gap'], 'have' => 0, 'lineTotal' => $l['gap'] * $l['unitPrice'], 'blockReason' => null,
            ], $lines),
            baseCost: array_sum(array_map(static fn (array $l): float => $l['gap'] * $l['unitPrice'], $lines)),
            fullCost: 100.0,
            share: 0.5,
            markupPct: 15,
            total: $total,
            goldAfter: 1000 - $total,
            available: true,
            refusal: null
        );
    }

    private function recipe(): array
    {
        return [
            'item_name_rus' => 'Топор дровосека',
            'info_callback' => 'genericCraftInfo_Axe',
        ];
    }

    // ── 🔴 порядок сделки: гейт СНАЧАЛА, покупка ПОТОМ, и только если гейт прошёл ──

    public function testOrderGateBeforePurchaseWhenStartPossible(): void
    {
        $action = new CraftShortfallBuyActionTestDouble();
        $action->canStartErrorReturn   = null; // крафт может стартовать
        $action->executePurchaseReturn = ['success' => true, 'message' => ''];

        $result = $action->pubAttemptStartCraft($this->recipe(), ['id' => 1, 'gold' => 100], $this->quote(), 777);

        $this->assertSame(['gate', 'purchase'], $action->callLog, 'гейт обязан идти раньше покупки');
        $this->assertNull($result['gateError']);
        $this->assertTrue($result['purchased']);
    }

    /**
     * 🔴 Это и есть смысл всей story: занятый эксклюзивный слот (ADR-167) — старт
     * невозможен, и покупка не совершается ВООБЩЕ. Если порядок в `attemptStartCraft()`
     * перевернуть (сначала купить, потом проверить старт), этот тест покраснеет:
     * `callLog` получит `'purchase'` даже при занятом гейте.
     */
    public function testOrderPurchaseNeverHappensWhenStartImpossible(): void
    {
        $action = new CraftShortfallBuyActionTestDouble();
        $action->canStartErrorReturn = 'Ты уже занят выполнением задачи: *Крафт*.';
        $action->executePurchaseReturn = ['success' => true, 'message' => ''];

        $result = $action->pubAttemptStartCraft($this->recipe(), ['id' => 1, 'gold' => 100], $this->quote(), 777);

        $this->assertSame(['gate'], $action->callLog, 'покупка не должна была случиться вовсе');
        $this->assertNotContains('purchase', $action->callLog, 'золото не списывается, если старт невозможен');
        $this->assertSame('Ты уже занят выполнением задачи: *Крафт*.', $result['gateError']);
        $this->assertFalse($result['purchased']);
    }

    public function testOrderGateFailureDoesNotStartCraftEvenIfPurchaseWouldSucceed(): void
    {
        // Покупка "сработала бы" (executePurchaseReturn success=true), но раз гейт
        // отказал — до executePurchase() дело дойти не должно вовсе.
        $action = new CraftShortfallBuyActionTestDouble();
        $action->canStartErrorReturn   = 'Все 3 слота крафта заняты.';
        $action->executePurchaseReturn = ['success' => true, 'message' => ''];

        $action->pubAttemptStartCraft($this->recipe(), ['id' => 1, 'gold' => 100], $this->quote(), 777);

        $this->assertCount(1, $action->callLog);
        $this->assertSame('gate', $action->callLog[0]);
    }

    // ── 🔴 находка 3: гейт судит по золоту ПОСЛЕ докупки, не до ──

    /**
     * Рецепт с `gold_required > 0`: золота 100, докупка стоит 90 (после неё
     * останется 10). Раньше `canStartError()` звался со снапшотом ДО покупки
     * (gold=100) — гейт пропускал бы сборку за 15 золота, докупка списывала
     * 90, а реальный старт `GenericCraftActionStart` отказал бы уже после
     * того, как деньги ушли. Теперь гейт обязан увидеть именно 10.
     */
    public function testCharacterAfterPurchaseSubtractsQuoteTotalFromGold(): void
    {
        $action = new CraftShortfallBuyActionTestDouble();

        $after = $action->pubCharacterAfterPurchase(['id' => 1, 'gold' => 100], $this->quote(total: 90));

        $this->assertSame(10, $after['gold'], 'гейт обязан видеть золото ПОСЛЕ докупки, а не снапшот до неё');
    }

    public function testCharacterAfterPurchaseNeverGoesNegative(): void
    {
        $action = new CraftShortfallBuyActionTestDouble();

        $after = $action->pubCharacterAfterPurchase(['id' => 1, 'gold' => 5], $this->quote(total: 90));

        $this->assertSame(0, $after['gold'], 'отрицательного золота в снапшоте для гейта быть не должно');
    }

    /**
     * Прямая сквозная проверка находки 3 через `attemptStartCraft()`: гейт получает
     * ИМЕННО скорректированный снапшот, а не оригинальный `$character`. Если кто-то
     * уберёт `characterAfterPurchase()` из вызова гейта, этот тест покраснеет —
     * `gateCharacterLog[0]['gold']` вернётся к 100.
     */
    public function testAttemptStartCraftGateSeesGoldAfterPurchaseNotBeforeIt(): void
    {
        $action = new CraftShortfallBuyActionTestDouble();
        $action->canStartErrorReturn   = null;
        $action->executePurchaseReturn = ['success' => true, 'message' => ''];

        $action->pubAttemptStartCraft($this->recipe(), ['id' => 1, 'gold' => 100], $this->quote(total: 90), 777);

        $this->assertSame(10, $action->gateCharacterLog[0]['gold']);
    }

    // ── цена пересчитывается в момент подтверждения; при изменении — переспросить ──

    /**
     * 🔴 находка 5: раньше отсутствие кэша (TTL истёк / прямой `craftBuyGo_*` мимо
     * экрана подтверждения) трактовалось как «цена не менялась» и сделка проходила
     * по свежей цене БЕЗ переспроса — то есть без единого подтверждения игроком.
     * Правильно: нет базы для сравнения = цена НЕ подтверждена = считать изменившейся.
     */
    public function testPriceTreatedAsUnconfirmedWhenNoCachedQuoteYet(): void
    {
        $action = new CraftShortfallBuyActionTestDouble();
        $this->assertTrue($action->pubPriceChanged(null, 250), 'нет закэшированной суммы — цена не подтверждена, обязана считаться изменившейся');
    }

    public function testPriceUnchangedWhenSameAsCached(): void
    {
        $action = new CraftShortfallBuyActionTestDouble();
        $this->assertFalse($action->pubPriceChanged(250, 250));
    }

    /**
     * Цены живые (крон, ADR-175): если итог на момент подтверждения отличается от
     * того, что видел игрок на экране, — обязана сработать ветка «переспросить»,
     * а не тихое списание по старой сумме.
     */
    public function testPriceChangedWhenTotalDiffersFromCached(): void
    {
        $action = new CraftShortfallBuyActionTestDouble();
        $this->assertTrue($action->pubPriceChanged(250, 300));
        $this->assertTrue($action->pubPriceChanged(250, 200));
    }

    // ── экран подтверждения: числа из quote(), золото не возвращается никогда ──

    public function testConfirmationTextWarnsGoldNeverReturnsBeforeTap(): void
    {
        $action = new CraftShortfallBuyActionTestDouble('Axe', 1);
        $text   = $action->pubConfirmationText($this->recipe(), $this->quote(total: 10, goldAfter: 90), ['id' => 1, 'gold' => 100], null);

        $this->assertStringContainsString('золото не вернётся никогда', $text);
        $this->assertStringContainsString('Итого спишется: *10*', $text);
        $this->assertStringContainsString('Останется золота: *90*', $text);
        $this->assertStringContainsString('15%', $text);
    }

    public function testConfirmationTextShowsHalfGoldWarningWhenOver50Percent(): void
    {
        $action = new CraftShortfallBuyActionTestDouble('Axe', 1);
        // total=60 из gold=100 -> больше половины.
        $text = $action->pubConfirmationText($this->recipe(), $this->quote(total: 60, goldAfter: 40), ['id' => 1, 'gold' => 100], null);

        $this->assertStringContainsString('больше половины твоего золота', $text);
    }

    public function testConfirmationTextNoHalfGoldWarningWhenUnderThreshold(): void
    {
        $action = new CraftShortfallBuyActionTestDouble('Axe', 1);
        // total=10 из gold=100 -> меньше половины.
        $text = $action->pubConfirmationText($this->recipe(), $this->quote(total: 10, goldAfter: 90), ['id' => 1, 'gold' => 100], null);

        $this->assertStringNotContainsString('больше половины твоего золота', $text);
    }

    public function testConfirmationTextExplainsLockedGateInsteadOfHidingIt(): void
    {
        $action = new CraftShortfallBuyActionTestDouble('Axe', 1);
        $text   = $action->pubConfirmationText(
            $this->recipe(),
            $this->quote(),
            ['id' => 1, 'gold' => 100],
            'Все 3 слота крафта заняты.'
        );

        $this->assertStringContainsString('Собрать прямо сейчас нельзя', $text);
        $this->assertStringContainsString('Все 3 слота крафта заняты.', $text);
    }

    // ── ни одна кнопка не стоит одна ──

    public function testConfirmationKeyboardHasNoLoneButtonWhenStartAllowed(): void
    {
        $action   = new CraftShortfallBuyActionTestDouble('Axe', 1);
        $keyboard = $action->pubConfirmationKeyboard($this->recipe(), true);

        foreach ($keyboard['inline_keyboard'] as $row) {
            $this->assertGreaterThanOrEqual(2, count($row), 'ни один ряд не должен быть из одной кнопки');
        }
        $flat = array_merge(...$keyboard['inline_keyboard']);
        $this->assertCount(3, $flat);
        $this->assertSame('craftBuyGo_Axe_1', $flat[0]['callback_data']);
    }

    public function testConfirmationKeyboardOmitsGoButtonWhenStartLocked(): void
    {
        $action   = new CraftShortfallBuyActionTestDouble('Axe', 1);
        $keyboard = $action->pubConfirmationKeyboard($this->recipe(), false);

        $flat = array_merge(...$keyboard['inline_keyboard']);
        $callbacks = array_column($flat, 'callback_data');

        $this->assertNotContains('craftBuyGo_Axe_1', $callbacks, 'кнопку старта не показываем, если стартовать нельзя');
        $this->assertContains('craftBuyOnly_Axe_1', $callbacks, '"просто добрать" доступна всегда');
        foreach ($keyboard['inline_keyboard'] as $row) {
            $this->assertGreaterThanOrEqual(2, count($row));
        }
    }

    // ── 🔴 находка 15: отказы обязаны нести кнопку назад ──

    public function testErrorKeyboardHasBackButtonWithRecipe(): void
    {
        $action   = new CraftShortfallBuyActionTestDouble('Axe', 1);
        $keyboard = $action->pubErrorKeyboard($this->recipe());

        $flat = array_merge(...$keyboard['inline_keyboard']);
        $this->assertNotEmpty($flat, 'отказ не должен оставлять игрока без единой кнопки');
        $this->assertSame('genericCraftInfo_Axe', $flat[0]['callback_data']);
    }

    public function testErrorKeyboardFallsBackToCraftMenuWithoutRecipe(): void
    {
        $action   = new CraftShortfallBuyActionTestDouble('Axe', 1);
        $keyboard = $action->pubErrorKeyboard(null);

        $flat = array_merge(...$keyboard['inline_keyboard']);
        $this->assertNotEmpty($flat, 'ошибка ДО lookup\'а рецепта тоже обязана вести куда-то, а не в тупик');
        $this->assertSame('craft', $flat[0]['callback_data']);
    }

    // ── 🔴 находка 13: ключ рецепта — пользовательский ввод, экранируется ──

    public function testSafeStripsMarkdownControlCharsFromUserSuppliedRecipeKey(): void
    {
        $action = new CraftShortfallBuyActionTestDouble();

        $this->assertSame('НеТотКлюч', $action->pubSafe('*НеТотКлюч*_[]`'));
    }

    // ── 🔴 находка 18: max_units_per_purchase=0 обязано выключать докупку целиком ──

    public function testResolveMaxUnitsZeroStaysZeroInsteadOfBeingForcedToOne(): void
    {
        $action = new CraftShortfallBuyActionTestDouble();

        $this->assertSame(0, $action->pubResolveMaxUnits(0), '0 в админке обещает «фича выключена» — не единица');
        $this->assertSame(0, $action->pubResolveMaxUnits('0'));
        $this->assertSame(3, $action->pubResolveMaxUnits(3));
        $this->assertSame(1, $action->pubResolveMaxUnits('не число'), 'нечисловое — единственный случай дефолта 1');
    }

    // ── лимит штук / прочие числовые границы ──

    public function testEatsMoreThanHalfGoldBoundary(): void
    {
        $action = new CraftShortfallBuyActionTestDouble();
        $this->assertTrue($action->pubEatsMoreThanHalfGold(51, 100));
        $this->assertFalse($action->pubEatsMoreThanHalfGold(50, 100));
        $this->assertFalse($action->pubEatsMoreThanHalfGold(10, 0));
    }

    // ── добавка координатора: у REASON_PRICE_UNKNOWN (fix-03) обязан быть свой текст ──

    public function testReasonTextHasEntryForPriceUnknown(): void
    {
        $this->assertArrayHasKey(CraftShortfallBuyService::REASON_PRICE_UNKNOWN, CraftShortfallBuyAction::REASON_TEXT);
        $text = CraftShortfallBuyAction::REASON_TEXT[CraftShortfallBuyService::REASON_PRICE_UNKNOWN];
        $this->assertNotSame('', trim($text));
        $this->assertStringNotContainsString('невыгодна', $text, 'это не находка profit_gate — цену просто не удалось посчитать');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔴 Денежный путь: executePurchase()/runPurchaseTransaction() — настоящая
    // оркестрация (не заглушка), только DB/Telegram-touching seam'ы переопределены.
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🔴 находка 2 — САМЫЙ ВАЖНЫЙ денежный тест story: `buyLine()` списывает только
     * базовую цену строк (10 + 3 = 13), `quote->total` с наценкой — 20. Разница (7)
     * обязана уйти отдельным `chargeExtraGold()`, а не потеряться. Если вернуть
     * старое поведение (списывать только строки, без наценки), `chargeExtraGoldCalledWith`
     * останется `null`, и тест покраснеет.
     */
    public function testExecutePurchaseChargesExactQuoteTotalIncludingMarkup(): void
    {
        $action = new CraftShortfallBuyActionMoneyPathTestDouble();
        $lines  = [
            ['resourceId' => 1, 'name' => 'Базальт', 'gap' => 2, 'unitPrice' => 5.0, 'buyable' => true],
            ['resourceId' => 2, 'name' => 'Смола',   'gap' => 1, 'unitPrice' => 3.0, 'buyable' => true],
        ];
        $quote = $this->quoteWithLines($lines, total: 20); // база 13 + наценка/min_markup_gold = 20

        $result = $action->pubExecutePurchase(['id' => 1, 'gold' => 1000], $quote, 777);

        $this->assertTrue($result['success']);
        $this->assertSame(7, $action->chargeExtraGoldCalledWith, 'наценка = quote->total(20) - Σ базовых цен строк(13)');
        $this->assertContains('commit', $action->callLog);
        $this->assertNotContains('rollback', $action->callLog);
    }

    /** Если базовая цена строк УЖЕ равна `quote->total` (наценки нет/min_markup_gold=0) — доплаты нет. */
    public function testExecutePurchaseSkipsExtraChargeWhenBaseAlreadyMatchesTotal(): void
    {
        $action = new CraftShortfallBuyActionMoneyPathTestDouble();
        $lines  = [['resourceId' => 1, 'name' => 'Базальт', 'gap' => 2, 'unitPrice' => 5.0, 'buyable' => true]];
        $quote  = $this->quoteWithLines($lines, total: 10);

        $result = $action->pubExecutePurchase(['id' => 1, 'gold' => 1000], $quote, 777);

        $this->assertTrue($result['success']);
        $this->assertNull($action->chargeExtraGoldCalledWith);
        $this->assertNotContains('chargeExtraGold:0', $action->callLog);
    }

    /**
     * 🔴 находка 9 — откат, покрытый настоящим тестом, а не заглушкой: вторая строка
     * срывается (гонка на редком ресурсе), первая уже "куплена" — обязан произойти
     * `rollback`, а НЕ `commit`, и `chargeExtraGold` (наценка) не должен звонить вовсе,
     * раз сама покупка не прошла. Удаление вызова `rollbackPurchaseTransaction()` из
     * ветки отказа красит этот тест: `callLog` перестанет содержать `'rollback'`.
     */
    public function testExecutePurchaseRollsBackWhenALineFails(): void
    {
        $action = new CraftShortfallBuyActionMoneyPathTestDouble();
        $action->buyLineResults[2] = ['success' => false, 'message' => 'Ресурс закончился на складе.'];
        $lines = [
            ['resourceId' => 1, 'name' => 'Базальт', 'gap' => 2, 'unitPrice' => 5.0, 'buyable' => true],
            ['resourceId' => 2, 'name' => 'Смола',   'gap' => 1, 'unitPrice' => 3.0, 'buyable' => true],
        ];
        $quote = $this->quoteWithLines($lines, total: 20);

        $result = $action->pubExecutePurchase(['id' => 1, 'gold' => 1000], $quote, 777);

        $this->assertFalse($result['success']);
        $this->assertSame('Ресурс закончился на складе.', $result['message']);
        $this->assertContains('rollback', $action->callLog);
        $this->assertNotContains('commit', $action->callLog);
        $this->assertNull($action->chargeExtraGoldCalledWith, 'наценка не списывается, если сама покупка сорвалась');
    }

    /** Откат срабатывает и когда сорвалось именно списание наценки, а не строка. */
    public function testExecutePurchaseRollsBackWhenExtraChargeFails(): void
    {
        $action = new CraftShortfallBuyActionMoneyPathTestDouble();
        $action->chargeExtraGoldReturn = false;
        $lines = [['resourceId' => 1, 'name' => 'Базальт', 'gap' => 2, 'unitPrice' => 5.0, 'buyable' => true]];
        $quote = $this->quoteWithLines($lines, total: 20); // база 10, наценка 10

        $result = $action->pubExecutePurchase(['id' => 1, 'gold' => 1000], $quote, 777);

        $this->assertFalse($result['success']);
        $this->assertContains('rollback', $action->callLog);
        $this->assertNotContains('commit', $action->callLog);
    }

    /**
     * 🔴 находка 6 — сбой (исключение) внутри транзакции обязан закончиться явным
     * откатом и честным ответом, а не долететь до вызывающего кода. Раньше
     * `executePurchase()` не ловил исключения вовсе.
     */
    public function testExecutePurchaseCatchesThrowableRollsBackAndAnswersHonestly(): void
    {
        $action = new CraftShortfallBuyActionMoneyPathTestDouble();
        $action->throwInBuyLine = true;
        $lines  = [['resourceId' => 1, 'name' => 'Базальт', 'gap' => 2, 'unitPrice' => 5.0, 'buyable' => true]];
        $quote  = $this->quoteWithLines($lines, total: 20);

        $result = $action->pubExecutePurchase(['id' => 1, 'gold' => 1000], $quote, 777);

        $this->assertFalse($result['success']);
        $this->assertNotSame('', $result['message']);
        $this->assertContains('rollback', $action->callLog);
        $this->assertNotContains('commit', $action->callLog);
    }

    public function testExecutePurchaseCatchesThrowableFromExtraChargeToo(): void
    {
        $action = new CraftShortfallBuyActionMoneyPathTestDouble();
        $action->throwInChargeExtra = true;
        $lines  = [['resourceId' => 1, 'name' => 'Базальт', 'gap' => 2, 'unitPrice' => 5.0, 'buyable' => true]];
        $quote  = $this->quoteWithLines($lines, total: 20);

        $result = $action->pubExecutePurchase(['id' => 1, 'gold' => 1000], $quote, 777);

        $this->assertFalse($result['success']);
        $this->assertContains('rollback', $action->callLog);
        $this->assertNotContains('commit', $action->callLog);
    }

    /**
     * 🔴 находка 4 — двойной тап / повтор вебхука. Замок уже держит другой (в
     * терминах теста — «первый ещё не отпустил») запрос: вторая покупка обязана
     * отказать НЕ дойдя ни до `buyLine`, ни до транзакции вовсе. Если снять проверку
     * `acquireLock()` из `executePurchase()`, `callLog` получит `'begin'`/`'buyLine:*'`,
     * и тест покраснеет.
     */
    public function testExecutePurchaseDeniedWhenLockAlreadyHeldByAnotherRequest(): void
    {
        $action = new CraftShortfallBuyActionMoneyPathTestDouble();
        $action->lockAvailable = false; // симулирует «первый тап ещё в процессе»
        $lines  = [['resourceId' => 1, 'name' => 'Базальт', 'gap' => 2, 'unitPrice' => 5.0, 'buyable' => true]];
        $quote  = $this->quoteWithLines($lines, total: 20);

        $result = $action->pubExecutePurchase(['id' => 1, 'gold' => 1000], $quote, 777);

        $this->assertFalse($result['success']);
        $this->assertSame(['acquireLock'], $action->callLog, 'ни строка, ни транзакция не должны стартовать под чужим замком');
    }

    /** Замок отпускается после успешной покупки — следующий (уже не двойной) тап проходит. */
    public function testExecutePurchaseReleasesLockAfterSuccess(): void
    {
        $action = new CraftShortfallBuyActionMoneyPathTestDouble();
        $lines  = [['resourceId' => 1, 'name' => 'Базальт', 'gap' => 2, 'unitPrice' => 5.0, 'buyable' => true]];
        $quote  = $this->quoteWithLines($lines, total: 10);

        $action->pubExecutePurchase(['id' => 1, 'gold' => 1000], $quote, 777);

        $this->assertContains('releaseLock', $action->callLog);
        $this->assertTrue($action->lockAvailable, 'после releaseLock() замок обязан быть свободен для следующего (не двойного) тапа');
    }

    /** Замок отпускается и на пути отказа (не только на успехе) — иначе TTL держит игрока в тупике. */
    public function testExecutePurchaseReleasesLockEvenOnFailure(): void
    {
        $action = new CraftShortfallBuyActionMoneyPathTestDouble();
        $action->throwInBuyLine = true;
        $lines  = [['resourceId' => 1, 'name' => 'Базальт', 'gap' => 2, 'unitPrice' => 5.0, 'buyable' => true]];
        $quote  = $this->quoteWithLines($lines, total: 20);

        $action->pubExecutePurchase(['id' => 1, 'gold' => 1000], $quote, 777);

        $this->assertContains('releaseLock', $action->callLog);
        $this->assertTrue($action->lockAvailable);
    }

    // ── 🔴 находка 4 (примитив): реальный cache-замок без переопределения ──

    public function testAcquireLockBlocksSecondCallUntilReleased(): void
    {
        $action = new CraftShortfallBuyActionTestDouble();
        $key    = 'test_craft_shortfall_lock_' . bin2hex(random_bytes(6));

        try {
            $this->assertTrue($action->pubAcquireLock($key, 5), 'первый тап получает замок');
            $this->assertFalse(
                $action->pubAcquireLock($key, 5),
                'второй тап (двойной тап/повтор вебхука), пришедший пока первый держит замок, обязан получить отказ'
            );

            $action->pubReleaseLock($key);
            $this->assertTrue($action->pubAcquireLock($key, 5), 'после освобождения замок снова доступен');
        } finally {
            $action->pubReleaseLock($key);
        }
    }
}

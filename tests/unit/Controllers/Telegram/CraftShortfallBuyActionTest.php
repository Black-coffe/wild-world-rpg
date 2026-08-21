<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\Commands\Actions\Craft\CraftShortfallBuyAction;
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
        $this->callLog[] = 'gate';

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

    public function pubEatsMoreThanHalfGold(int $total, int $goldBefore): bool
    {
        return $this->eatsMoreThanHalfGold($total, $goldBefore);
    }

    protected function maxUnitsPerPurchase(): int
    {
        return 1;
    }
}

/**
 * Story `craft-shortfall-buy-09` — экран подтверждения докупки и проведение сделки.
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

    // ── цена пересчитывается в момент подтверждения; при изменении — переспросить ──

    public function testPriceUnchangedWhenNoCachedQuoteYet(): void
    {
        $action = new CraftShortfallBuyActionTestDouble();
        $this->assertFalse($action->pubPriceChanged(null, 250), 'нет базы для сравнения — не считается изменением');
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

    // ── лимит штук ──

    public function testEatsMoreThanHalfGoldBoundary(): void
    {
        $action = new CraftShortfallBuyActionTestDouble();
        $this->assertTrue($action->pubEatsMoreThanHalfGold(51, 100));
        $this->assertFalse($action->pubEatsMoreThanHalfGold(50, 100));
        $this->assertFalse($action->pubEatsMoreThanHalfGold(10, 0));
    }
}

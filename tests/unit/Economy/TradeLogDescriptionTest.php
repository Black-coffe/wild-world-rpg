<?php

declare(strict_types=1);

namespace Tests\Unit\Economy;

use App\Services\Player\LedgerService;
use App\Services\Player\Trade\ResourceTradeService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * chat-requests-batch-12 — «Ivan Divan «лога движения средств тоже нету и не понятно
 * нихера»». `BUY_RESOURCE`/`SELL_RESOURCE`/`BULK_SELL` писали в `action_log.description`
 * машинный хвост (`res=12 qty=3 gold=-45`) — экран «Куда ушло» (story 06) показывает
 * его игроку ДОСЛОВНО, без разбора, так что читаемость обязана появиться на стороне
 * записи: `ResourceTradeService::describeTrade()`/`describeBulkTrade()`.
 *
 * Оба метода — чистые pure-функции (без БД), поэтому тест не трогает `action_log`
 * напрямую: реальная запись в БД остаётся ответственностью production call site'ов
 * (`ResourceTradeService::logPurchase()`, `SellResourceAction`, `BulkSellAction`,
 * `GenericmessageCommand::handleTradeReply()`), покрытых phpstan + ревью, не этим
 * тестом.
 *
 * @internal
 */
final class TradeLogDescriptionTest extends CIUnitTestCase
{
    // ── describeTrade(): одиночная сделка (BUY/SELL) ────────────────────────

    public function testDescribeTradeMatchesTaxDeathVoiceForPurchase(): void
    {
        $line = ResourceTradeService::describeTrade('Покупка', 'Дерево', 5, -125);

        $this->assertSame('Покупка: Дерево ×5; -125 золота', $line);
    }

    public function testDescribeTradeUsesPlusSignForPositiveGoldDelta(): void
    {
        $line = ResourceTradeService::describeTrade('Продажа', 'Вода', 3, 45);

        $this->assertSame('Продажа: Вода ×3; +45 золота', $line);
    }

    public function testDescribeTradeNoLongerLeaksMachineFormat(): void
    {
        $line = ResourceTradeService::describeTrade('Покупка', 'Дерево', 5, -125);

        $this->assertStringNotContainsString('res=', $line);
        $this->assertStringNotContainsString('qty=', $line);
        $this->assertStringNotContainsString('gold=', $line);
    }

    // ── describeBulkTrade(): оптовая сделка (BULK_SELL), ограничитель ───────

    public function testDescribeBulkTradeListsAllPositionsUnderLimit(): void
    {
        $line = ResourceTradeService::describeBulkTrade('Продажа опт 50% всех ресурсов', [
            ['name' => 'Дерево', 'qty' => 5],
            ['name' => 'Вода', 'qty' => 3],
        ], 84);

        $this->assertSame('Продажа опт 50% всех ресурсов: Дерево ×5, Вода ×3; +84 золота', $line);
    }

    /**
     * Богатый инвентарь не разносит ленту простынёй — тот же приём «и ещё N», что
     * `DeathService::joinWithLimit()` держит для состава потерь при смерти (story 11).
     */
    public function testDescribeBulkTradeTruncatesLongCompositionWithAndMoreCount(): void
    {
        $lines = [];
        for ($i = 1; $i <= 8; $i++) {
            $lines[] = ['name' => "Ресурс{$i}", 'qty' => $i];
        }

        $line = ResourceTradeService::describeBulkTrade('Продажа опт', $lines, 999, 5);

        $this->assertStringContainsString('и ещё 3', $line, '8 позиций, лимит 5 — хвост назван числом');
        $this->assertStringNotContainsString('Ресурс6', $line, 'обрезанные позиции не должны утечь поимённо');
        $this->assertStringContainsString('Ресурс5', $line, 'позиции ДО лимита остаются поимённо');
    }

    /** `planBulkSale()` (уже существующий batch-резолвер) кормит `describeBulkTrade()` напрямую, без адаптеров. */
    public function testPlanBulkSaleLinesFeedDescribeBulkTradeDirectly(): void
    {
        $plan = ResourceTradeService::planBulkSale([
            ['id' => 1, 'charResId' => 10, 'quantity' => 10, 'sell_price' => 2.0, 'is_tradeable' => 1, 'rarity' => 1, 'name' => 'Дерево', 'icon' => ''],
            ['id' => 2, 'charResId' => 11, 'quantity' => 4, 'sell_price' => 5.0, 'is_tradeable' => 1, 'rarity' => 1, 'name' => 'Вода', 'icon' => ''],
        ], 50);

        $lines = array_map(static fn (array $l): array => ['name' => $l['name'], 'qty' => $l['qty']], $plan['lines']);
        $text  = ResourceTradeService::describeBulkTrade('Продажа опт', $lines, $plan['totalGold']);

        $this->assertStringContainsString('Дерево ×5', $text);
        $this->assertStringContainsString('Вода ×2', $text);
    }

    // ── Non-goal: action_name у существующих кодов не меняется ──────────────

    public function testActionNameConstantsUnchangedInProductionCallSites(): void
    {
        $paths = [
            APPPATH . 'Services/Player/Trade/ResourceTradeService.php',
            APPPATH . 'Controllers/Telegram/Commands/Actions/Sell/SellResourceAction.php',
            APPPATH . 'Controllers/Telegram/Commands/Actions/Sell/BulkSellAction.php',
        ];
        $needles = ["'BUY_RESOURCE'", "'SELL_RESOURCE'", "'BULK_SELL'"];

        $combined = '';
        foreach ($paths as $path) {
            $combined .= (string) file_get_contents($path);
        }
        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $combined, "код {$needle} обязан остаться — по нему строятся отчёты");
        }
    }

    // ── markdown-safety: имя ресурса не ломает рендер экрана «Куда ушло» ─────

    /**
     * Требование team lead (довесок к story 06): то, что `LedgerService::actionLine()`
     * уже обезвреживает, обязано продолжать работать и после того, как в description
     * появилось имя ресурса из БД. Имя с `_`/`*` не должно валить Markdown ВСЕГО экрана.
     */
    public function testDangerousResourceNameSurvivesLedgerScreenSanitization(): void
    {
        $description = ResourceTradeService::describeTrade('Продажа', 'Верстак_2 Сталь*', 1, 100);
        $rendered    = LedgerService::actionLine('SELL_RESOURCE', $description);

        $this->assertStringNotContainsString('_', $rendered);
        $this->assertStringNotContainsString('*', $rendered);
        $this->assertSame(0, substr_count($rendered, '*') % 2);
    }

    public function testDangerousResourceNameInBulkCompositionSurvivesLedgerScreenSanitization(): void
    {
        $description = ResourceTradeService::describeBulkTrade('Продажа опт', [
            ['name' => 'Верстак_2', 'qty' => 1],
            ['name' => 'Сталь*', 'qty' => 3],
        ], 400);
        $rendered = LedgerService::actionLine('BULK_SELL', $description);

        $this->assertStringNotContainsString('_', $rendered);
        $this->assertStringNotContainsString('*', $rendered);
    }
}

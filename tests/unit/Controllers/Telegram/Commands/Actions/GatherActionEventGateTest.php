<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Telegram\Commands\Actions;

use App\Controllers\Telegram\Commands\Actions\GatherAction;
use App\Services\Events\ActiveDamageEventLookup;
use CodeIgniter\Test\CIUnitTestCase;
use Longman\TelegramBot\Entities\CallbackQuery;

/**
 * ADR-200 (sudden-death-1125 S2) — цена пассивного пола: с здоровьем ниже `health.event_gather_min` в биоме
 * активного события с уроном добыча не начинается; отказ называет событие и говорит, что делать
 * («на базу / подлечись»). Тот же HP в биоме без такого события — добыча стартует. Бот и `/play` идут через
 * один `GatherAction` (ADR-189).
 *
 * @internal
 */
final class GatherActionEventGateTest extends CIUnitTestCase
{
    public function testLowHealthInADamageEventBiomeIsRefusedWithTheWayOut(): void
    {
        $text = $this->action()->refusal(['health' => 12.4], 3, null);

        $this->assertNotNull($text, 'low HP in an event biome: gather does not start');
        $this->assertStringContainsString('Подземные толчки', $text, 'names the event');
        $this->assertStringContainsString('на базу', $text);
        $this->assertStringContainsString('подлечись', $text);
        $this->assertStringContainsString('30', $text, 'shows the threshold');
        $this->assertStringContainsString('12.4', $text, 'shows the current health');
    }

    public function testSameHealthWithoutSuchEventStarts(): void
    {
        $this->assertNull($this->action()->refusal(['health' => 12.4], 4, null));
    }

    public function testEnoughHealthStartsEvenInTheEventBiome(): void
    {
        $this->assertNull($this->action()->refusal(['health' => 30.0], 3, null), 'threshold itself is allowed');
        $this->assertNull($this->action()->refusal(['health' => 80.0], 3, null));
    }

    public function testMenuShowsTheLockBeforeThePlayerChoosesTime(): void
    {
        $hint = $this->action()->hint(['health' => 12.4, 'cell_number' => 1], 3);

        $this->assertStringContainsString('Подземные толчки', $hint);
        $this->assertStringContainsString('на базу', $hint);
        $this->assertSame('', $this->action()->hint(['health' => 12.4, 'cell_number' => 1], 4), 'no event — no hint');
        $this->assertSame('', $this->action()->hint(['health' => 90.0, 'cell_number' => 1], 3), 'healthy — no hint');
    }

    public function testRefusalTextIsHtmlSafe(): void
    {
        $text = GatherAction::eventGateText(5.0, 30.0, ['<b>Злое</b> & событие']);

        $this->assertNotNull($text);
        $this->assertStringContainsString('&lt;b&gt;Злое&lt;/b&gt; &amp; событие', $text);
        $this->assertNull(GatherAction::eventGateText(5.0, 30.0, []), 'no events — no refusal');
    }

    private function action(): GateProbe
    {
        return new GateProbe(new CallbackQuery([
            'id'            => 'cb',
            'from'          => ['id' => 1, 'is_bot' => false, 'first_name' => 'T'],
            'chat_instance' => 'ci',
            'data'          => 'gather_10',
        ]));
    }
}

final class GateProbe extends GatherAction
{
    /** @param array<string, mixed> $character */
    public function refusal(array $character, int $biomeId, ?string $biomeType): ?string
    {
        return $this->eventGateRefusal($character, $biomeId, $biomeType);
    }

    /** @param array<string, mixed> $character */
    public function hint(array $character, int $biomeId): string
    {
        return $this->eventGateHintForMenu($character, $biomeId, null);
    }

    protected function eventGatherMin(): float
    {
        return 30.0;
    }

    protected function damageEventLookup(): ActiveDamageEventLookup
    {
        return new class () extends ActiveDamageEventLookup {
            public function forBiome(int $biomeId, ?string $biomeType = null): array
            {
                return $biomeId === 3 ? [['event_id' => 9, 'name' => 'Подземные толчки', 'name_english' => 'Tremor']] : [];
            }
        };
    }
}

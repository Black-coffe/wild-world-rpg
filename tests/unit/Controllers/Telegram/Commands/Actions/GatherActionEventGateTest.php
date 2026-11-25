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
 * S5 (ask 10): порог по умолчанию 15, а на клетке своей базы гейт не отказывает, если событие базу не бьёт
 * (Толчки, base_idle 0); Эпидемия и Лихорадка бьют и на базе — гейт остаётся.
 *
 * @internal
 */
final class GatherActionEventGateTest extends CIUnitTestCase
{
    public function testLowHealthInADamageEventBiomeIsRefusedWithTheWayOut(): void
    {
        $text = $this->action()->refusal(['health' => 10.4], 3, null);

        $this->assertNotNull($text, 'low HP in an event biome: gather does not start');
        $this->assertStringContainsString('Подземные толчки', $text, 'names the event');
        $this->assertStringContainsString('на базу', $text);
        $this->assertStringContainsString('подлечись', $text);
        $this->assertStringContainsString('15', $text, 'shows the threshold');
        $this->assertStringContainsString('10.4', $text, 'shows the current health');
    }

    public function testSameHealthWithoutSuchEventStarts(): void
    {
        $this->assertNull($this->action()->refusal(['health' => 10.4], 4, null));
    }

    public function testEnoughHealthStartsEvenInTheEventBiome(): void
    {
        $this->assertNull($this->action()->refusal(['health' => 15.0], 3, null), 'threshold itself is allowed');
        $this->assertNull($this->action()->refusal(['health' => 80.0], 3, null));
    }

    /** S5: дефолт кода 15 — HP 20 в биоме события добывает (при 30 отказывало), HP 10 — нет. */
    public function testDefaultThresholdIsFifteen(): void
    {
        $this->assertSame(15.0, GatherAction::EVENT_GATHER_MIN_DEFAULT);
        $this->assertNull($this->action()->refusal(['health' => 20.0], 3, null), 'HP 20 gathers in the event biome');
        $this->assertNotNull($this->action()->refusal(['health' => 10.0], 3, null), 'HP 10 is refused');
    }

    /** S5: на своей базе Толчки не бьют — гейт не отказывает; Эпидемия / Лихорадка бьют и на базе — отказ. */
    public function testOwnBaseSkipsTheGateOnlyForEventsThatDoNotHitThere(): void
    {
        $this->assertNull(
            $this->action()->refusal(['health' => 10.0, 'on_base' => true], 3, null),
            'HP 10 on own base during Tremor: gather starts'
        );
        $this->assertSame('', $this->action()->hint(['health' => 10.0, 'cell_number' => 1, 'on_base' => true], 3), 'no lock hint on base');

        $text = $this->action()->refusal(['health' => 10.0, 'on_base' => true], 5, null);
        $this->assertNotNull($text, 'HP 10 on own base during Epidemic: refused');
        $this->assertStringContainsString('Эпидемия', $text);
        $this->assertStringNotContainsString('Подземные толчки', $text, 'Tremor does not hit on base');
        $this->assertStringContainsString('и на базе', $text, 'tells the base does not shelter from this one');
        $this->assertStringNotContainsString('Иди на базу', $text, 'does not send a player on base to the base');
        $this->assertStringContainsString('подлечись', $text);
        $hint = $this->action()->hint(['health' => 10.0, 'cell_number' => 1, 'on_base' => true], 5);
        $this->assertStringContainsString('Эпидемия', $hint);
        $this->assertStringContainsString('и на базе', $hint);
        $this->assertStringNotContainsString('Иди на базу', $hint);

        $offBase = $this->action()->refusal(['health' => 10.0, 'on_base' => false], 5, null);
        $this->assertNotNull($offBase);
        $this->assertStringContainsString('Подземные толчки', $offBase, 'off base both events count');
    }

    public function testMenuShowsTheLockBeforeThePlayerChoosesTime(): void
    {
        $hint = $this->action()->hint(['health' => 10.4, 'cell_number' => 1], 3);

        $this->assertStringContainsString('Подземные толчки', $hint);
        $this->assertStringContainsString('на базу', $hint);
        $this->assertSame('', $this->action()->hint(['health' => 10.4, 'cell_number' => 1], 4), 'no event — no hint');
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

    /** Порог — дефолт кода (сверка с посевом — в `SetEventGatherMinTo15Test`). */
    protected function eventGatherMin(): float
    {
        return GatherAction::EVENT_GATHER_MIN_DEFAULT;
    }

    /** @param array<string, mixed>|\App\Entities\CharacterEntity $character */
    protected function isOnOwnBase(array|\App\Entities\CharacterEntity $character): bool
    {
        return ($character['on_base'] ?? false) === true;
    }

    /**
     * Биом 3 — Толчки (на базе не бьют), биом 5 — Толчки и Эпидемия (бьёт и на базе); правило «бьёт ли на базе»
     * на настоящем конфиге — в `ActiveDamageEventLookupTest`.
     */
    protected function damageEventLookup(): ActiveDamageEventLookup
    {
        return new class () extends ActiveDamageEventLookup {
            public function forBiome(int $biomeId, ?string $biomeType = null, bool $onBase = false): array
            {
                $tremor   = ['event_id' => 9, 'name' => 'Подземные толчки', 'name_english' => 'Tremor'];
                $epidemic = ['event_id' => 11, 'name' => 'Эпидемия', 'name_english' => 'Epidemic'];
                $rows     = match ($biomeId) {
                    3       => [$tremor],
                    5       => [$tremor, $epidemic],
                    default => [],
                };

                return $onBase ? array_values(array_filter($rows, static fn (array $r): bool => $r['name_english'] !== 'Tremor')) : $rows;
            }
        };
    }
}

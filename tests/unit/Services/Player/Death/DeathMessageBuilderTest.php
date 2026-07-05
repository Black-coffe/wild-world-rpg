<?php

namespace Tests\Unit\Services\Player\Death;

use App\Services\Player\Death\DeathMessageBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Тесты на чистые методы {@see DeathMessageBuilder} (карточка
 * `inbox/2026-05-11-validation-card-death-notifications.md`, batch 2):
 * header() / lossLine() / adviceBlock(). Метод rouletteDeath() (читает active_events)
 * покрывается Telegram-smoke'ом на testbot.
 *
 * @internal
 */
final class DeathMessageBuilderTest extends CIUnitTestCase
{
    private DeathMessageBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new DeathMessageBuilder();
    }

    public function testHeaderInsuranceVsRegular(): void
    {
        $ins = $this->builder->header('Боб', 0.0);
        $this->assertStringContainsString('Боб', $ins);
        $this->assertStringContainsString('страховка', mb_strtolower($ins));

        $reg = $this->builder->header('Боб', 0.03);
        $this->assertStringContainsString('Боб', $reg);
        $this->assertStringContainsString('погиб', mb_strtolower($reg));
    }

    public function testLossLineByPenalty(): void
    {
        $insurance = $this->builder->lossLine(0.0);
        $this->assertStringContainsString('страховка', mb_strtolower($insurance));
        $this->assertStringNotContainsString('%', $insurance);

        $hasBase = $this->builder->lossLine(0.03);
        $this->assertStringContainsString('3%', $hasBase);
        $this->assertStringContainsString('база', mb_strtolower($hasBase));

        $noBase = $this->builder->lossLine(0.5);
        $this->assertStringContainsString('50%', $noBase);
        $this->assertStringContainsString('нет базы', mb_strtolower($noBase));
        $this->assertStringContainsString('лагерь', mb_strtolower($noBase)); // совет «построй базу»
    }

    public function testAdviceBlockMentionsHealthAndInsurance(): void
    {
        $base = $this->builder->adviceBlock(false);
        $this->assertStringContainsString('Как не допустить', $base);
        $this->assertStringContainsString('здоровь', mb_strtolower($base));
        $this->assertStringContainsString('страховк', mb_strtolower($base));
        $this->assertStringNotContainsString('событи', mb_strtolower($base)); // нет event-совета без флага

        $duringEvent = $this->builder->adviceBlock(true);
        $this->assertStringContainsString('событи', mb_strtolower($duringEvent)); // event-совет есть
        $this->assertStringContainsString('защитный предмет события', $duringEvent); // без имени предмета — общая формулировка
    }

    public function testAdviceBlockNamesProtectionItemWhenKnown(): void
    {
        $withItem = $this->builder->adviceBlock(true, 'Bandage');
        $this->assertStringContainsString('Bandage', $withItem);
        $this->assertStringContainsString('событи', mb_strtolower($withItem));

        // пустая строка трактуется как «нет предмета»
        $noItem = $this->builder->adviceBlock(true, '');
        $this->assertStringNotContainsString('Bandage', $noItem);
    }

    /**
     * 🔴 Регресс prod-report Ярик (2026-07-05): лечащее событие «Чистый родник»
     * (health_delta > 0) НЕ должно считаться причиной смерти. Только реальная просадка
     * HP (health_delta < 0) — damage-событие.
     *
     * @dataProvider harmedHealthProvider
     *
     * @param array<string,mixed> $entry
     */
    public function testLogEntryHarmedHealth(array $entry, bool $expected): void
    {
        $this->assertSame($expected, DeathMessageBuilder::logEntryHarmedHealth($entry));
    }

    /**
     * @return iterable<string, array{0: array<string,mixed>, 1: bool}>
     */
    public static function harmedHealthProvider(): iterable
    {
        yield 'урон HP (Hurricane)'                 => [['health_delta' => -23.5], true];
        yield 'лечение HP (Чистый родник)'          => [['health_delta' => 8.0], false];
        yield 'лечение +0.5'                        => [['health_delta' => 0.5], false];
        yield 'только выносливость (heal tired)'    => [['health_delta' => 0.0, 'tired_delta' => 6.0], false];
        yield 'золото/ресурсы, HP не тронуто'       => [['gold_delta' => 500], false];
        yield 'нечисловой health_delta'             => [['health_delta' => 'нет'], false];
        yield 'пустая запись'                       => [[], false];
    }
}

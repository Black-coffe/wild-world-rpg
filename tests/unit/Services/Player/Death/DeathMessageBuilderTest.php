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
     * ADR-200 (S4, ask 7) — причина смерти названа с уроном: какое событие, сколько ❤️, за какое время.
     * Удары одного события суммируются по фактически применённому `health_delta`; лечение не причина.
     */
    public function testCauseNamesTheEventItsDamageAndTime(): void
    {
        $now  = 1_800_000_000;
        $hits = [
            ['event_id' => 7, 'name' => 'Подземные толчки', 'health_delta' => -40.0, 'event_time' => date('Y-m-d H:i:s', $now - 25 * 60)],
            ['event_id' => 9, 'name' => 'Чистый родник', 'health_delta' => 5.0, 'event_time' => date('Y-m-d H:i:s', $now - 20 * 60)],
            ['event_id' => 7, 'name' => 'Подземные толчки', 'health_delta' => -30.0, 'event_time' => date('Y-m-d H:i:s', $now - 3 * 60)],
        ];

        $text = DeathMessageBuilder::causeBlock($hits, null, 0.42, $now);

        $this->assertStringContainsString('Подземные толчки', $text);
        $this->assertStringContainsString('❤️70', $text, 'сумма ударов события: 40 + 30');
        $this->assertStringContainsString('25 мин', $text, 'от первого удара до смерти');
        $this->assertStringNotContainsString('Чистый родник', $text, 'лечащее событие — не причина');
        $this->assertStringContainsString('0.42', $text);
        $this->assertSame(0, substr_count($text, '*') % 2, 'markdown-safe: парные *');
        $this->assertSame(7, DeathMessageBuilder::topEventId($hits));
    }

    public function testCauseWithoutEventsNamesTheMarchOrHunger(): void
    {
        $now   = 1_800_000_000;
        $march = ['hp' => 3.5, 'started' => date('Y-m-d H:i:s', $now - 40 * 60), 'cells' => 12];

        $byMarch = DeathMessageBuilder::causeBlock([], $march, 0.3, $now);
        $this->assertStringContainsString('поход', mb_strtolower($byMarch));
        $this->assertStringContainsString('❤️3.5', $byMarch);
        $this->assertStringContainsString('40 мин', $byMarch);
        $this->assertStringNotContainsString('голод', mb_strtolower($byMarch));

        $byHunger = DeathMessageBuilder::causeBlock([], null, 0.3, $now, ['hp' => 25.0, 'time' => date('Y-m-d H:i:s', $now - 90 * 60)]);
        $this->assertStringContainsString('голод', mb_strtolower($byHunger));
        $this->assertStringNotContainsString('поход', mb_strtolower($byHunger));
        $this->assertNull(DeathMessageBuilder::topEventId([]));
    }

    /**
     * Ревью раунд 1 (ask 7): голод назван с уроном и временем по записи о фактической просадке; без записи о
     * голоде причина его не называет (голод сам до рулетки больше не доводит — ask 1).
     */
    public function testCauseNamesHungerWithItsDamageAndTimeOnlyWhenRecorded(): void
    {
        $now    = 1_800_000_000;
        $hunger = ['hp' => 25.0, 'time' => date('Y-m-d H:i:s', $now - 90 * 60)];

        $text = DeathMessageBuilder::causeBlock([], null, 0.3, $now, $hunger);
        $this->assertStringContainsString('голод', mb_strtolower($text));
        $this->assertStringContainsString('❤️25', $text);
        $this->assertStringContainsString('90 мин', $text);

        $none = DeathMessageBuilder::causeBlock([], null, 0.3, $now);
        $this->assertStringNotContainsString('голод', mb_strtolower($none));

        // Голод половинит, событие добивает: названы оба, урон складывается.
        $hits = [['event_id' => 7, 'name' => 'Подземные толчки', 'health_delta' => -49.0, 'event_time' => date('Y-m-d H:i:s', $now - 10 * 60)]];
        $both = DeathMessageBuilder::causeBlock($hits, null, 0.3, $now, ['hp' => 50.0, 'time' => date('Y-m-d H:i:s', $now - 300 * 60)]);
        $this->assertStringContainsString('Подземные толчки', $both);
        $this->assertStringContainsString('❤️49', $both);
        $this->assertStringContainsString('голод', mb_strtolower($both));
        $this->assertStringContainsString('❤️50', $both);
        $this->assertSame(0, substr_count($both, '*') % 2, 'markdown-safe: парные *');
    }

    /** Худший случай причины (три события с длинными именами + поход) держит всё сообщение в бюджете 1024. */
    public function testWorstCaseCauseKeepsTheWholeMessageShort(): void
    {
        $now  = 1_800_000_000;
        $hits = [];
        foreach ([1, 2, 3, 4] as $id) {
            $hits[] = ['event_id' => $id, 'name' => 'Радиоактивный туман над *пустошью_', 'health_delta' => -33.33, 'event_time' => date('Y-m-d H:i:s', $now - 119 * 60)];
        }
        $cause = DeathMessageBuilder::causeBlock($hits, ['hp' => 12.34, 'started' => date('Y-m-d H:i:s', $now - 119 * 60), 'cells' => 60], 0.42, $now,
            ['hp' => 49.99, 'time' => date('Y-m-d H:i:s', $now - 1439 * 60)]);
        $text  = $this->builder->header('Длинноеимявыжившего', 0.5) . "\n\n" . $cause . "\n\n" . $this->builder->lossLine(0.5)
            . "\n\n" . $this->builder->adviceBlock(true, 'Противогаз');

        $this->assertSame(3, substr_count($cause, 'событие'), 'названы не больше трёх событий');
        $this->assertLessThanOrEqual(1024, mb_strlen($text));
        $this->assertSame(0, substr_count($cause, '*') % 2, 'служебные символы из имени события убраны');
        $this->assertSame(0, substr_count($cause, '_') % 2);
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

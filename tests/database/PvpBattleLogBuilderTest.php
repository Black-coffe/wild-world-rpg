<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\PVE\PvpBattleLogBuilder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Asana «Расширение логирования PVP» Фаза 1 — PvpBattleLogBuilder.
 *
 * Проверяет сборку log_data v2: структура, health_before/after (post из fightResult winner/loser
 * по id), outcome, координаты (cell=0 → null), экипировка (нет надетой → null/[]).
 *
 * @internal
 */
final class PvpBattleLogBuilderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private function fightResult(): array
    {
        return [
            'type'      => 'normal',
            'rounds'    => 3,
            'winner'    => ['id' => 999991, 'name' => 'Атакующий', 'health' => 60.0],
            'loser'     => ['id' => 999992, 'name' => 'Защитник', 'health' => 0.0],
            'roundLogs' => [['round' => 1, 'finalDamage' => 20.0]],
        ];
    }

    private function attacker(): array
    {
        return [
            'id' => 999991, 'name' => 'Атакующий', 'level' => 7,
            'strength' => 12, 'agility' => 9, 'intellect' => 8,
            'health' => 100, 'cell_number' => 0, 'faction' => ['name' => 'Милитари'],
        ];
    }

    private function defender(): array
    {
        return [
            'id' => 999992, 'name' => 'Защитник', 'level' => 6,
            'strength' => 10, 'agility' => 11, 'intellect' => 7,
            'health' => 80, 'cell_number' => 0, 'faction' => null,
        ];
    }

    public function testBuildsV2StructureWithPrePostHealth(): void
    {
        $log = (new PvpBattleLogBuilder())->build($this->attacker(), $this->defender(), $this->fightResult(), 'Леса');

        $this->assertSame(2, $log['version']);
        $this->assertSame('Леса', $log['biome']);

        $att = $log['characters']['attacker'];
        $this->assertSame(999991, $att['id']);
        $this->assertSame('Атакующий', $att['name']);
        $this->assertSame(7, $att['level']);
        $this->assertSame('Милитари', $att['faction']);
        $this->assertSame(100.0, $att['health_before']);
        $this->assertSame(60.0, $att['health_after']);   // из fightResult winner

        $def = $log['characters']['defender'];
        $this->assertSame(80.0, $def['health_before']);
        $this->assertSame(0.0, $def['health_after']);     // из fightResult loser
        $this->assertNull($def['faction']);
    }

    public function testOutcomeAndRounds(): void
    {
        $log = (new PvpBattleLogBuilder())->build($this->attacker(), $this->defender(), $this->fightResult(), 'Леса');

        $this->assertSame('normal', $log['outcome']['type']);
        $this->assertSame(999991, $log['outcome']['winnerId']);
        $this->assertSame(999992, $log['outcome']['loserId']);
        $this->assertCount(1, $log['rounds']);
    }

    public function testNoEquipmentAndNoCoordsForBareChars(): void
    {
        // cell_number=0 → coords null (без запроса карты); тестовые id без надетой экипировки.
        $log = (new PvpBattleLogBuilder())->build($this->attacker(), $this->defender(), $this->fightResult(), null);

        $this->assertNull($log['biome']);
        $this->assertNull($log['characters']['attacker']['coords']);
        $this->assertNull($log['characters']['attacker']['weapon']);
        $this->assertSame([], $log['characters']['attacker']['armor']);
    }

    public function testExhaustedHasNullPostHealth(): void
    {
        $fr = ['type' => 'exhausted', 'rounds' => 20, 'winner' => null, 'loser' => null, 'roundLogs' => []];
        $log = (new PvpBattleLogBuilder())->build($this->attacker(), $this->defender(), $fr, 'Леса');

        $this->assertNull($log['characters']['attacker']['health_after']);
        $this->assertNull($log['characters']['defender']['health_after']);
        $this->assertNull($log['outcome']['winnerId']);
    }
}

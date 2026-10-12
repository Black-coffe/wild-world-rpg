<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PVE;

use App\Services\PVE\PvpOutcomeExplainer;
use CodeIgniter\Test\CIUnitTestCase;
use Config\GameBalance;

/**
 * oct-pvp-clarity-01 — разбор «почему так вышло» из `log_data`.
 *
 * Фикстура — бой 75134 с прода (2026-10-12, Torch0010 L504 → sergiolamoz L120 у его базы), раунды
 * сокращены: срез стены 40 %, ограда, один удачный удар младшего, два удара насмерть, срезанных стеной.
 *
 * @internal
 */
final class PvpOutcomeExplainerTest extends CIUnitTestCase
{
    private const TORCH = 9001;
    private const SERGIO = 9002;

    /** @return array<string, mixed> */
    private function log75134(bool $withDefense = true): array
    {
        $log = [
            'version'    => 2,
            'characters' => [
                'attacker' => ['id' => self::TORCH, 'name' => 'Torch0010', 'level' => 504, 'strength' => 1149.0],
                'defender' => ['id' => self::SERGIO, 'name' => 'sergiolamoz', 'level' => 120, 'strength' => 319.7],
            ],
            'rounds' => [
                $this->round(1, 'Torch0010', 'sergiolamoz', 60, 38.4, 19.58, 100.0, false),
                $this->round(2, 'sergiolamoz', 'Torch0010', 0, 0.0, 30.2, 87.0, true),
                $this->round(3, 'Torch0010', 'sergiolamoz', 27, 38.4, 36.5, 60.84, false),
                $this->round(4, 'sergiolamoz', 'Torch0010', 0, 0.0, 20.1, 43.6, false),
                $this->round(5, 'Torch0010', 'sergiolamoz', 12, 38.4, 14.6, 24.34, false),
                $this->round(6, 'sergiolamoz', 'Torch0010', 0, 0.0, 22.0, 10.3, false),
            ],
            'outcome' => ['type' => 'normal', 'winnerId' => self::SERGIO, 'loserId' => self::TORCH],
        ];
        if ($withDefense) {
            $log['defense'] = [
                'owner_id'                 => self::SERGIO,
                'wall_reduction_percent'   => 40.0,
                'fence_damage_total'       => 39.0,
                'tower_initiative_percent' => 0.0,
                'drone'                    => false,
            ];
        }

        return $log;
    }

    /** @return array<string, mixed> */
    private function round(int $n, string $att, string $def, int $roll, float $chance, float $dmg, float $hpBefore, bool $lucky): array
    {
        return [
            'round' => $n, 'attacker' => $att, 'defender' => $def,
            'oneShotRoll' => $roll, 'oneShotChance' => $chance,
            'finalDamage' => $dmg, 'defenderHealthBefore' => $hpBefore,
            'defenderHealthAfter' => max(0.0, $hpBefore - $dmg), 'luckyStrikeApplied' => $lucky,
        ];
    }

    public function testFight75134ExplainsAllFactors(): void
    {
        $lines = (new PvpOutcomeExplainer())->explain('PVP', $this->log75134());
        $text  = implode("\n", $lines);

        $this->assertStringContainsString('Стена базы срезала каждый удар по хозяину базы (sergiolamoz) на 40%', $text);
        $this->assertStringContainsString('Колючая ограда сняла с нападавшего (Torch0010) 39 HP', $text);
        $this->assertStringContainsString('Удачный удар младшего по уровню у sergiolamoz: 1 раз, урон ×1.5', $text);
        $this->assertStringContainsString('Удар насмерть смягчён стеной базы: 2 раза', $text);
        $this->assertStringContainsString('Сила выше 100 даёт не больше +10% урона', $text);
        $this->assertStringContainsString('разница больше 5 уровней — не больше +10%', $text);
        $this->assertStringNotContainsString('инициатив', $text, 'вышки и дрона не было — строки нет');
        $this->assertCount(5, $lines);
    }

    public function testNumbersComeFromGameBalance(): void
    {
        $cfg                         = new GameBalance();
        $cfg->levelDiffCap           = 7;
        $cfg->luckyStrikeDamageMult  = 2.0;
        $cfg->statsBonusFactor       = 0.002;
        $text = implode("\n", (new PvpOutcomeExplainer($cfg))->explain('PVP', $this->log75134()));

        $this->assertStringContainsString('урон ×2.', $text);
        $this->assertStringContainsString('разница больше 7 уровней — не больше +14%', $text);
        $this->assertStringContainsString('Сила выше 50 даёт', $text);
    }

    public function testOldFightWithoutDefenseKeepsRoundFactors(): void
    {
        $text = implode("\n", (new PvpOutcomeExplainer())->explain('PVP', $this->log75134(false)));

        $this->assertStringNotContainsString('Стена базы срезала', $text);
        $this->assertStringNotContainsString('ограда', $text);
        $this->assertStringContainsString('Удачный удар младшего', $text);
        $this->assertStringContainsString('Удар насмерть смягчён', $text);
        $this->assertStringContainsString('Потолки', $text);
    }

    public function testOutsiderSeesDefenseWithoutNumbers(): void
    {
        $lines = (new PvpOutcomeExplainer())->explain('PVP', $this->log75134(), false);
        $text  = implode("\n", $lines);

        $this->assertStringContainsString('Бой шёл у базы sergiolamoz', $text);
        $this->assertStringNotContainsString('40%', $text);
        $this->assertStringNotContainsString('39 HP', $text);
        $this->assertStringContainsString('Удачный удар младшего', $text);
        $this->assertStringContainsString('Потолки', $text);
    }

    public function testCounterAttackOwnerInAttackerSlot(): void
    {
        $log                         = $this->log75134();
        $log['defense']['owner_id']  = self::TORCH;
        $log['defense']['drone']     = true;
        $log['defense']['tower_initiative_percent'] = 12.0;
        $text = implode("\n", (new PvpOutcomeExplainer())->explain('PVP', $log));

        $this->assertStringContainsString('по хозяину базы (Torch0010)', $text);
        $this->assertStringContainsString('с нападавшего (sergiolamoz)', $text);
        $this->assertStringContainsString('+12% к инициативе (вышка и боевой дрон)', $text);
    }

    public function testDuelPveAndBrokenLogGiveNothing(): void
    {
        $ex = new PvpOutcomeExplainer();
        $this->assertSame([], $ex->explain('DUEL', $this->log75134()));
        $this->assertSame([], $ex->explain('PVE', $this->log75134()));
        $this->assertSame([], $ex->explain('PVP', []));
        $this->assertSame([], $ex->explain('PVP', ['characters' => 'x', 'rounds' => 5]));
    }

    public function testEvenFightWithinCapsGivesNothing(): void
    {
        $log = [
            'characters' => [
                'attacker' => ['id' => 1, 'name' => 'A', 'level' => 10, 'strength' => 40.0],
                'defender' => ['id' => 2, 'name' => 'B', 'level' => 12, 'strength' => 55.0],
            ],
            'rounds' => [$this->round(1, 'A', 'B', 0, 0.0, 10.0, 100.0, false)],
        ];
        $this->assertSame([], (new PvpOutcomeExplainer())->explain('PVP', $log));
    }

    public function testLinesCarryNoMarkup(): void
    {
        foreach ((new PvpOutcomeExplainer())->explain('PVP', $this->log75134()) as $line) {
            $this->assertDoesNotMatchRegularExpression('/[*_<>`\[\]]/u', $line);
        }
    }
}

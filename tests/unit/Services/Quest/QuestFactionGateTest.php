<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Quest;

use App\Models\GameSettingsModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Quest\QuestChainService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-088 Фаза 3 — фракционный гейтинг квестов (QuestChainService::factionGateOk).
 * Не-фракционный квест (faction_id 0/null) всегда проходит. Фракционный (1-4) —
 * только при killswitch quests.faction_quests_enabled=ON И совпадении фракции игрока.
 *
 * @internal
 */
final class QuestFactionGateTest extends CIUnitTestCase
{
    private function service(bool $factionOn): QuestChainService
    {
        $model = new class ($factionOn) extends GameSettingsModel {
            public function __construct(private bool $on = false) {}

            public function findByKey(string $key): ?array
            {
                if ($key === 'quests.faction_quests_enabled') {
                    return [
                        'id'          => 1,
                        'setting_key' => $key,
                        'value_type'  => 'bool',
                        'value_bool'  => $this->on ? 1 : 0,
                        'hard_min'    => null,
                        'hard_max'    => null,
                    ];
                }
                return null;
            }
        };

        return new QuestChainService(new GameSettingsService($model));
    }

    public function testNonFactionQuestAlwaysPasses(): void
    {
        // faction_id отсутствует / null / 0 → не фракционный → ОК даже при killswitch OFF.
        $svc = $this->service(false);
        $this->assertTrue($svc->factionGateOk(['objective_type' => 'collect_resource'], 0));
        $this->assertTrue($svc->factionGateOk(['faction_id' => null], 3));
        $this->assertTrue($svc->factionGateOk(['faction_id' => 0], 1));
    }

    public function testFactionQuestMatchingFactionWhenEnabled(): void
    {
        $svc = $this->service(true);
        $this->assertTrue($svc->factionGateOk(['faction_id' => 3], 3));
    }

    public function testFactionQuestMismatchedFaction(): void
    {
        $svc = $this->service(true);
        $this->assertFalse($svc->factionGateOk(['faction_id' => 3], 1));
        // Нейтрал (0) / не выбрал — фракционный квест недоступен.
        $this->assertFalse($svc->factionGateOk(['faction_id' => 2], 0));
    }

    public function testFactionQuestBlockedWhenKillswitchOff(): void
    {
        $svc = $this->service(false);
        // Совпадающая фракция, но killswitch OFF → недоступно.
        $this->assertFalse($svc->factionGateOk(['faction_id' => 4], 4));
        $this->assertFalse($svc->factionQuestsEnabled());
    }

    public function testFactionOfNormalizesValue(): void
    {
        $svc = $this->service(true);
        $this->assertSame(2, $svc->factionOf(['faction_id' => 2]));
        $this->assertSame(2, $svc->factionOf(['faction_id' => '2']));
        $this->assertSame(0, $svc->factionOf(['faction_id' => null]));
        $this->assertSame(0, $svc->factionOf([]));
    }
}

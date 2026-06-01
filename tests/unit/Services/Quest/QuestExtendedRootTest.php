<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Quest;

use App\Models\GameSettingsModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Quest\QuestChainService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-088 — гейтинг расширенных startable-«корней» квестов.
 * Проверяет, что char_level/craft_item (этапы цепочек + demo branch-point) НЕ становятся
 * manual-startable, а новые типы (collect_resource/building_level/level_milestone/npc_kills)
 * без prerequisite — становятся, и только при killswitch ON.
 *
 * @internal
 */
final class QuestExtendedRootTest extends CIUnitTestCase
{
    private function service(bool $extendedOn): QuestChainService
    {
        // GameSettingsService — final (не мокается). Паттерн как в GameSettingsServiceTest:
        // реальный сервис + анонимный GameSettingsModel без БД.
        $model = new class ($extendedOn) extends GameSettingsModel {
            public function __construct(private bool $on = false) {}

            public function findByKey(string $key): ?array
            {
                if ($key === 'quests.extended_enabled') {
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

    /**
     * @param array<string,mixed> $quest
     * @dataProvider startableRoots
     */
    public function testExtendedRootsStartableWhenEnabled(array $quest): void
    {
        $this->assertTrue($this->service(true)->isExtendedStartableRoot($quest));
    }

    /**
     * @return list<array{array<string,mixed>}>
     */
    public static function startableRoots(): array
    {
        return [
            [['objective_type' => 'collect_resource', 'prerequisite_quest' => null]],
            [['objective_type' => 'building_level', 'prerequisite_quest' => null]],
            [['objective_type' => 'level_milestone', 'prerequisite_quest' => null]],
            [['objective_type' => 'npc_kills', 'prerequisite_quest' => null]],
        ];
    }

    /**
     * @param array<string,mixed> $quest
     * @dataProvider nonStartable
     */
    public function testNonRootsNotStartableEvenWhenEnabled(array $quest): void
    {
        $this->assertFalse($this->service(true)->isExtendedStartableRoot($quest));
    }

    /**
     * @return list<array{array<string,mixed>}>
     */
    public static function nonStartable(): array
    {
        return [
            // char_level / craft_item исключены (этапы цепочек + demo branch-point).
            [['objective_type' => 'char_level', 'prerequisite_quest' => null]],
            [['objective_type' => 'craft_item', 'prerequisite_quest' => null]],
            // discover_object — авто (StrategicLootHandler).
            [['objective_type' => 'discover_object', 'prerequisite_quest' => null]],
            // расширенный тип, НО это этап цепочки (есть prerequisite) → авто-advance, не manual.
            [['objective_type' => 'collect_resource', 'prerequisite_quest' => 'SomeRoot']],
            // bespoke (objective_type пуст).
            [['objective_type' => null, 'prerequisite_quest' => null]],
        ];
    }

    public function testNothingStartableWhenKillswitchOff(): void
    {
        $svc = $this->service(false);
        $this->assertFalse($svc->isExtendedStartableRoot(['objective_type' => 'collect_resource', 'prerequisite_quest' => null]));
        $this->assertFalse($svc->extendedEnabled());
    }
}

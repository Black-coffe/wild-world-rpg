<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Quest;

use App\Models\GameSettingsModel;
use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Quest\DailyTaskService;
use App\Services\Quest\QuestChainService;
use App\Services\Quest\QuestOverviewService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * E27 (ADR-126) — QuestOverviewService: чистая фильтрация classifyFrom + сборка summary.
 *
 * Без БД: модели/сервисы — анонимные дубли с пустым конструктором (не подключаются к БД),
 * QuestChainService со стаб-настройками (паттерн QuestExtendedRootTest).
 *
 * @internal
 */
final class QuestOverviewServiceTest extends CIUnitTestCase
{
    /** QuestChainService с включёнными extended/faction-гейтами (chains по умолчанию ON). */
    private function chain(bool $extended = true, bool $faction = true): QuestChainService
    {
        $model = new class ($extended, $faction) extends GameSettingsModel {
            public function __construct(private bool $extended, private bool $faction) {}

            public function findByKey(string $key): ?array
            {
                $map = [
                    'quests.extended_enabled'       => $this->extended,
                    'quests.faction_quests_enabled' => $this->faction,
                ];
                if (! array_key_exists($key, $map)) {
                    return null;
                }

                return [
                    'id'          => 1,
                    'setting_key' => $key,
                    'value_type'  => 'bool',
                    'value_bool'  => $map[$key] ? 1 : 0,
                    'hard_min'    => null,
                    'hard_max'    => null,
                ];
            }
        };

        return new QuestChainService(new GameSettingsService($model));
    }

    private function service(QuestChainService $chain): QuestOverviewService
    {
        // Модели не используются в classifyFrom — пустые дубли, чтобы не дёргать БД.
        $qm  = new class extends QuestModel { public function __construct() {} };
        $qsm = new class extends QuestStepsModel { public function __construct() {} };
        $dt  = new class extends DailyTaskService { public function __construct() {} };

        return new QuestOverviewService($qm, $qsm, $chain, $dt);
    }

    public function testExtendedRootBecomesAvailable(): void
    {
        $quests = [
            ['id' => 10, 'objective_type' => 'collect_resource', 'prerequisite_quest' => null, 'faction_id' => null],
        ];
        $res = $this->service($this->chain())->classifyFrom($quests, [], [], 0);

        $this->assertCount(1, $res['available']);
        $this->assertSame(10, (int) $res['available'][0]['id']);
        $this->assertSame([], $res['locked']);
    }

    public function testExistingStepExcludesQuest(): void
    {
        $quests = [
            ['id' => 10, 'objective_type' => 'collect_resource', 'prerequisite_quest' => null, 'faction_id' => null],
        ];
        // У персонажа уже есть шаг по квесту 10 → не показываем.
        $res = $this->service($this->chain())->classifyFrom($quests, [10], [], 0);

        $this->assertSame([], $res['available']);
        $this->assertSame([], $res['locked']);
    }

    public function testFactionGateHidesMismatchedFactionQuest(): void
    {
        $quests = [
            ['id' => 20, 'objective_type' => 'collect_resource', 'prerequisite_quest' => null, 'faction_id' => 2],
        ];
        $svc = $this->service($this->chain());

        // Совпадает фракция → доступен.
        $this->assertCount(1, $svc->classifyFrom($quests, [], [], 2)['available']);
        // Другая фракция → скрыт (не available и не locked — objective-квест просто отброшен).
        $mismatch = $svc->classifyFrom($quests, [], [], 3);
        $this->assertSame([], $mismatch['available']);
        $this->assertSame([], $mismatch['locked']);
    }

    public function testBespokeQuestLockedUntilPrerequisiteMet(): void
    {
        $quests = [
            ['id' => 30, 'objective_type' => null, 'prerequisite_quest' => 'RootQuest', 'faction_id' => null],
        ];
        $svc = $this->service($this->chain());

        // Предусловие НЕ выполнено → locked (тизер цепочки).
        $locked = $svc->classifyFrom($quests, [], [], 0);
        $this->assertSame([], $locked['available']);
        $this->assertCount(1, $locked['locked']);
        $this->assertSame('RootQuest', $locked['locked'][0]['prereq']);

        // Предусловие выполнено → available.
        $open = $svc->classifyFrom($quests, [], ['RootQuest'], 0);
        $this->assertCount(1, $open['available']);
        $this->assertSame([], $open['locked']);
    }

    public function testBespokeQuestWithoutPrerequisiteIsAvailable(): void
    {
        $quests = [
            ['id' => 40, 'objective_type' => null, 'prerequisite_quest' => null, 'faction_id' => null],
        ];
        $res = $this->service($this->chain())->classifyFrom($quests, [], [], 0);

        $this->assertCount(1, $res['available']);
        $this->assertSame([], $res['locked']);
    }

    public function testSummaryAggregatesAllSources(): void
    {
        $chain = $this->chain();

        $qm = new class extends QuestModel {
            public function __construct() {}

            public function getAvailableQuests(int $characterLevel): array
            {
                return [
                    ['id' => 10, 'objective_type' => 'collect_resource', 'prerequisite_quest' => null, 'faction_id' => null],
                ];
            }

            public function getCompletedQuestTitles(int $characterId): array
            {
                return ['A', 'B', 'C'];
            }
        };

        $daily = new class extends DailyTaskService {
            public function __construct() {}

            public function enabled(): bool
            {
                return true;
            }

            public function allDoneBonusGold(): int
            {
                return 500;
            }

            public function today(int $charId): array
            {
                return [
                    ['id' => 1, 'slot' => 1, 'task_key' => 'a', 'title' => 'A', 'verb' => 'v', 'objective_qty' => 3, 'progress' => 3, 'is_completed' => true, 'reward_gold' => 100],
                    ['id' => 2, 'slot' => 2, 'task_key' => 'b', 'title' => 'B', 'verb' => 'v', 'objective_qty' => 5, 'progress' => 1, 'is_completed' => false, 'reward_gold' => 100],
                ];
            }
        };

        $qsm = new class extends QuestStepsModel { public function __construct() {} };

        $svc = new class ($qm, $qsm, $chain, $daily) extends QuestOverviewService {
            protected function existingStepQuestIds(int $charId): array
            {
                return [];
            }

            protected function activeStepCount(int $charId): int
            {
                return 3;
            }

            protected function questgiverEnabled(): bool
            {
                return true;
            }
        };

        $s = $svc->summary(20, 777, 0);

        $this->assertSame(3, $s['active']);
        $this->assertSame(1, $s['available']);
        $this->assertSame(0, $s['locked']);
        $this->assertSame(3, $s['completed']);
        $this->assertTrue($s['daily']['enabled']);
        $this->assertTrue($s['daily']['assigned']);
        $this->assertSame(1, $s['daily']['done']);
        $this->assertSame(2, $s['daily']['total']);
        $this->assertSame(500, $s['daily']['bonus']);
        $this->assertSame(0, $s['branches']); // branching killswitch OFF → пусто без БД
        $this->assertTrue($s['npc_hint']);
    }
}

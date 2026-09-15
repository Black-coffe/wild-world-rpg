<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player;

use App\Entities\BattleCharacter;
use App\Models\CharacterModel;
use App\Models\NpcModel;
use App\Models\NpcSpawnModel;
use App\Models\TelegramUserModel;
use App\Services\PVE\BattleService;
use App\Services\PVE\BattleLogger;
use App\Services\PVE\DamageService;
use App\Services\PVE\EffectService;
use App\Services\PVE\EquipmentService;
use App\Services\PVE\PveNotificationSender;
use App\Services\PVE\RewardService;
use App\Services\Player\PvEService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * cron-delivery-integrity-03 — провал доставки PvE-уведомления не должен
 * оставаться немым на проде (там пишется только `error`, `warning` невидим,
 * см. reference_prod_info_not_logged_monitor_via_markers).
 *
 * Все побочные зависимости PvEService::attack() подменены test-double'ами
 * (как FakeLevelUpNotifier в LevelUpNotifierTest) — бой/награды здесь не
 * пересчитываются заново (это покрыто BattleService/RewardService тестами),
 * проверяется только контракт: notify-исключение не всплывает и логируется
 * `error`, а бой (characterModel->update) и награда (rewardService->grantRewards)
 * всё равно применены.
 *
 * @internal
 */
final class PvEServiceNotifyFailureTest extends CIUnitTestCase
{
    /**
     * `PveBattleLogWriter` (final, не подклассить) пишет в `battle_logs` — легаси-таблица без
     * создающей миграции (только `ALTER`/seed), поэтому на чистой «только-миграционной» БД её
     * нет. Тест обязан быть самодостаточным (не полагаться на ручной сетап БД) — создаём
     * минимальную совместимую таблицу в setUp и убираем в tearDown, только её.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect('tests');
        $db->query(
            'CREATE TABLE IF NOT EXISTS battle_logs ('
            . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
            . 'battle_type VARCHAR(50) NULL,'
            . 'player1_id INT NULL,'
            . 'player2_id INT NULL,'
            . 'winner_id INT NULL,'
            . 'created_at DATETIME NULL,'
            . 'finished_at DATETIME NULL,'
            . 'log_data TEXT NULL'
            . ') ENGINE=InnoDB'
        );
    }

    protected function tearDown(): void
    {
        \Config\Database::connect('tests')->query('DROP TABLE IF EXISTS battle_logs');
        parent::tearDown();
    }

    public function testNotifyExceptionIsLoggedAsErrorAndFightStillCounted(): void
    {
        $characterModel  = new NotifyTestCharacterModel();
        $rewardService   = new NotifyTestRewardService();
        // PveNotificationSender — `final class`, не подклассить. Заставляем реальный
        // send() бросить исключение через сломанную TelegramUserModel::find() —
        // ровно то же место, где на проде реально рвётся доставка (DB down/timeout).
        $notifySender    = new PveNotificationSender(new ThrowingTelegramUserModel());

        $service = new PvEService(
            new NotifyTestBattleService(),
            $rewardService,
            new NotifyTestEquipmentService(),
            $characterModel,
            new FakeNpcSpawnModel(),
            new FakeNpcModel(),
            null,
            null,
            null,
            $notifySender
        );

        $playerData = [
            'id'                => 501,
            'name'              => 'Тестер',
            'level'             => 1,
            'health'            => 50.0,
            'tired'             => 40.0,
            'strength'          => 1.0,
            'agility'           => 1.0,
            'intellect'         => 1.0,
            'cell_number'       => 100,
            'telegram_user_id'  => 25,
        ];
        $npcData = ['id' => 9001];

        $result = $service->attack($playerData, $npcData, 'desert');

        // Исключение из notificationSender->send() не всплыло из attack().
        $this->assertArrayHasKey('rewards', $result);
        $this->assertArrayHasKey('winner', $result);

        // Бой и награды по-прежнему засчитаны: update() персонажа и grantRewards() отработали.
        $this->assertTrue($characterModel->updated, 'characterModel->update() не был вызван');
        $this->assertTrue($rewardService->granted, 'rewardService->grantRewards() не был вызван');

        // Провал доставки виден на проде (threshold=4 логгера пишет только error и строже).
        $this->assertLogged('error', 'PvE notify failed (бой уже засчитан): telegram user lookup boom');
    }
}

/**
 * @internal
 */
final class ThrowingTelegramUserModel extends TelegramUserModel
{
    public function find($id = null)
    {
        throw new \RuntimeException('telegram user lookup boom');
    }
}

/**
 * `PveCombatValidator` — `final class`, не подклассить. Он строит `npcData` из двух
 * моделей (`NpcSpawnModel`/`NpcModel`, оба НЕ final) — подменяем их `find()`, оставляя
 * реальный валидатор реальным. Так тест не трогает таблицы `npcs`/`npc_spawns`
 * (их нет в схеме, собранной только миграциями).
 *
 * @internal
 */
final class FakeNpcSpawnModel extends NpcSpawnModel
{
    public function find($id = null)
    {
        return ['id' => 9001, 'npc_id' => 77, 'cell_number' => 100, 'current_health' => 1.0];
    }
}

/**
 * @internal
 */
final class FakeNpcModel extends NpcModel
{
    public function find($id = null)
    {
        return ['id' => 77, 'npc_name_ru' => 'Тестовый NPC', 'level' => 1, 'is_boss' => 0];
    }
}

/**
 * @internal
 */
final class NotifyTestEquipmentService extends EquipmentService
{
    public function __construct()
    {
        parent::__construct(service('logger'));
    }

    public function applyEquipmentBonuses(BattleCharacter $character): void
    {
        // no-op: бонусы экипировки не участвуют в этом контракте.
    }
}

/**
 * @internal
 */
final class NotifyTestBattleService extends BattleService
{
    public function __construct()
    {
        $logger = service('logger');
        parent::__construct(
            new DamageService($logger),
            new EffectService($logger),
            new BattleLogger($logger),
            $logger
        );
    }

    public function startFight(BattleCharacter $player, BattleCharacter $npc, string $biome): array
    {
        // Детерминированная победа игрока за 1 раунд — рандом боя вне контракта теста.
        $npc->health = 0.0;

        return [
            'log'           => [],
            'winner'        => $player,
            'loser'         => $npc,
            'rounds'        => 1,
            'firstAttacker' => $player->name,
        ];
    }
}

/**
 * @internal
 */
final class NotifyTestRewardService extends RewardService
{
    public bool $granted = false;

    public function grantRewards(BattleCharacter $winner, BattleCharacter $loser): array
    {
        $this->granted = true;

        return [
            'exp'         => 1.0,
            'gold'        => 1,
            'strength'    => 0.0,
            'agility'     => 0.0,
            'intellect'   => 0.0,
            'resource'    => null,
            'craftedItem' => null,
        ];
    }
}

/**
 * @internal
 */
final class NotifyTestCharacterModel extends CharacterModel
{
    public bool $updated = false;

    /** @return array<string,mixed> */
    public function find($id = null)
    {
        return [
            'id'                => 501,
            'name'              => 'Тестер',
            'health'            => 50.0,
            'tired'             => 40.0,
            'telegram_user_id'  => 25,
        ];
    }

    public function update($id = null, $row = null): bool
    {
        $this->updated = true;

        return true;
    }

    public function incrementNpcKills(int $characterId): bool
    {
        return true;
    }
}

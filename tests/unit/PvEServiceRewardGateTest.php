<?php

use App\Entities\BattleCharacter;
use App\Services\Player\PvEService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Регресс на FK-шторм `character_resources` (источник — AutoPveHandler-крон).
 *
 * Корень бага: в авто-PvE при ПОБЕДЕ NPC (игрок проиграл) PvEService::attack всё
 * равно звал RewardService::grantRewards($winner, ...) — а grantRewards пишет по
 * winner->id. Когда winner — это NPC, winner->id = id NPC (которого нет в characters)
 * → adjustGold/update/INSERT character_resources → FK fail. Ловило застрявших AFK
 * level-1 игроков у агрессивных NPC: ~1 ошибка/минуту (2706/день).
 *
 * Фикс — гейт `PvEService::playerIsWinner()`: награда выдаётся ТОЛЬКО когда победил
 * игрок (winner->id === player->id). Иначе grantRewards не вызывается, $rewards = 0.
 * Здесь тестируем сам предикат (чистый, без БД/боя).
 *
 * @internal
 */
final class PvEServiceRewardGateTest extends CIUnitTestCase
{
    public function testPlayerWinnerReturnsTrue(): void
    {
        $player = new BattleCharacter(['id' => 994, 'name' => 'Syrovar']);

        $this->assertTrue(PvEService::playerIsWinner($player, 994));
    }

    public function testNpcWinnerReturnsFalse(): void
    {
        // Победил NPC: winner->id (1914) !== player->id (994). Награду выдавать НЕЛЬЗЯ —
        // иначе FK на character_resources по несуществующему id персонажа.
        $npc = new BattleCharacter(['id' => 1914, 'name' => 'Рейдер Песчаных Волков']);

        $this->assertFalse(PvEService::playerIsWinner($npc, 994));
    }

    public function testNonBattleCharacterWinnerReturnsFalse(): void
    {
        $this->assertFalse(PvEService::playerIsWinner(null, 994));
        $this->assertFalse(PvEService::playerIsWinner(['id' => 994], 994));
        $this->assertFalse(PvEService::playerIsWinner('994', 994));
    }

    public function testIdComparisonIsTypeLoose(): void
    {
        // winner->id — int; player id приходит как (int) → сравнение по числу, без сюрпризов.
        $player = new BattleCharacter(['id' => 7]);

        $this->assertTrue(PvEService::playerIsWinner($player, 7));
        $this->assertFalse(PvEService::playerIsWinner($player, 8));
    }

    // ── ADR-106 — маркер победы над боссом (BOSS_KILL_<npc_name_en>) ──────────

    public function testBossKillFlagNameForBoss(): void
    {
        // npcData = merged выход PveCombatValidator (npcs + npc_spawns).
        $this->assertSame(
            'BOSS_KILL_boss_scar_butcher',
            PvEService::bossKillFlagName(['is_boss' => 1, 'npc_name_en' => 'boss_scar_butcher'])
        );
    }

    public function testBossKillFlagNameNullForRegularNpc(): void
    {
        $this->assertNull(PvEService::bossKillFlagName(['is_boss' => 0, 'npc_name_en' => 'sand_wolf_raider']));
        $this->assertNull(PvEService::bossKillFlagName(['npc_name_en' => 'sand_wolf_raider'])); // is_boss отсутствует
        $this->assertNull(PvEService::bossKillFlagName(['is_boss' => '0', 'npc_name_en' => 'x'])); // строковый '0'
    }

    public function testBossKillFlagNameNullWithoutEnName(): void
    {
        $this->assertNull(PvEService::bossKillFlagName(['is_boss' => 1]));
        $this->assertNull(PvEService::bossKillFlagName(['is_boss' => 1, 'npc_name_en' => '']));
    }
}

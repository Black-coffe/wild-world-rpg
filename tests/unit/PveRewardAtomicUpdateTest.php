<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Анти-дрейф (класс lost-update, ADR-151): наградные статы PvE-боя
 * (gold / experience / strength / agility / intellect) ОБЯЗАНЫ применяться
 * ОДНОЙ атомарной дельтой к СВЕЖИМ значениям под row-lock (CharacterStatsService),
 * а НЕ абсолютом из снапшота BattleCharacter, прочитанного ДО боя.
 *
 * До фикса: RewardService писал exp/str/agi/int абсолютом из снапшота $winner,
 * а PvEService переписывал gold/experience абсолютом из снапшота $player
 * (`$player->gold + reward`), затирая собственный атомарный adjustGold и любое
 * параллельное начисление. Окно широкое: AutoPveHandler крутит бой каждую
 * минуту, игрок параллельно мог купить в магазине / выиграть другой бой /
 * получить tribute → те начисления молча терялись (last-writer-wins).
 *
 * Source-scan (как UsePharmacyAtomicUpdateTest / GuideCatalogTest): юнитом
 * конкурентность двух FPM-воркеров не воспроизвести — фиксируем контракт по
 * исходнику.
 *
 * @internal
 */
final class PveRewardAtomicUpdateTest extends CIUnitTestCase
{
    private function rewardSource(): string
    {
        $src = file_get_contents(APPPATH . 'Services/PVE/RewardService.php');
        $this->assertIsString($src);

        return $src;
    }

    private function pveSource(): string
    {
        $src = file_get_contents(APPPATH . 'Services/Player/PvEService.php');
        $this->assertIsString($src);

        return $src;
    }

    public function testRewardStatsAppliedViaAtomicStatsService(): void
    {
        // Награда применяется через общий атомарный сервис…
        $this->assertStringContainsString('CharacterStatsService', $this->rewardSource());

        // …который держит контракт: свежее чтение с row-lock внутри транзакции.
        $svc = file_get_contents(APPPATH . 'Services/Player/CharacterStatsService.php');
        $this->assertIsString($svc);
        $this->assertStringContainsString('FOR UPDATE', $svc);
        $this->assertStringContainsString('transBegin', $svc);
        $this->assertStringContainsString('transCommit', $svc);
        $this->assertStringContainsString('transRollback', $svc);
    }

    public function testRewardServiceNoSnapshotAbsoluteStatWrite(): void
    {
        $src = $this->rewardSource();

        // Старый снапшот-абсолют убран: exp/str/agi/int больше не пишутся
        // read-modify-write через characterModel->update.
        $this->assertStringNotContainsString('$newExp', $src);
        $this->assertStringNotContainsString('$this->characterModel', $src);
    }

    public function testPveServiceNoSnapshotAbsoluteRewardWrite(): void
    {
        $src = $this->pveSource();

        // PvEService больше НЕ добавляет награду к снапшоту gold/experience
        // абсолютной записью — это владение RewardService (атомарно).
        $this->assertStringNotContainsString('$player->gold +', $src);
        $this->assertStringNotContainsString('$player->experience +', $src);
    }
}

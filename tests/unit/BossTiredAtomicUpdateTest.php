<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Анти-дрейф (класс lost-update, ADR-151): списание выносливости в бою с узлом
 * (`BossEncounterService` — спецприём и отступление) ОБЯЗАНО считаться от СВЕЖЕЙ
 * выносливости под row-lock (CharacterStatsService::mutate), а НЕ от снапшота
 * `$character['tired']`, прочитанного на входе.
 *
 * Бой с узлом ПОШАГОВЫЙ (tap = чанк раундов): между тапами игрок живёт своей
 * жизнью, `NodeHealthRegenHandler`/общий regen поднимают tired атомарно, а
 * снапшот-абсолют следующего тапа затирал бы этот тик.
 *
 * health-записи узла (`['health' => max(1, $playerHp)]`) — боевой HP из
 * boss_encounters.player_hp, absolute-by-design, НЕ конвертируются.
 *
 * Source-scan (как UsePharmacyAtomicUpdateTest).
 *
 * @internal
 */
final class BossTiredAtomicUpdateTest extends CIUnitTestCase
{
    private function source(): string
    {
        $src = file_get_contents(APPPATH . 'Services/PVE/BossEncounterService.php');
        $this->assertIsString($src);

        return $src;
    }

    public function testTiredCostViaAtomicStatsService(): void
    {
        $this->assertStringContainsString('CharacterStatsService', $this->source());
    }

    public function testNoSnapshotAbsoluteTiredWrite(): void
    {
        // Снапшот-переменная урезанной выносливости убрана (списание идёт
        // относительно свежего значения внутри mutate-callback).
        $this->assertStringNotContainsString('$newTired', $this->source());
    }
}

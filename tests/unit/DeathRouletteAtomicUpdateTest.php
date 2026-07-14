<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Анти-дрейф (класс lost-update, ADR-151): урезание exp/статов при смерти
 * (`DeathRouletteHandler::processDeathAndRespawn`) ОБЯЗАНО считаться от СВЕЖИХ
 * значений под row-lock (CharacterStatsService::mutate), а НЕ от снапшота
 * `find()`, прочитанного в начале обработки.
 *
 * Крон DeathRoulette бежит КАЖДУЮ МИНУТУ по всем персонажам с health<=0.99 —
 * старый паттерн (find → мультипликативная потеря от снапшота → update абсолютом)
 * молча затирал параллельный regen / начисление exp в окне find→update.
 *
 * Source-scan (как UsePharmacyAtomicUpdateTest / PveRewardAtomicUpdateTest).
 *
 * @internal
 */
final class DeathRouletteAtomicUpdateTest extends CIUnitTestCase
{
    private function source(): string
    {
        $src = file_get_contents(APPPATH . 'TaskHandlers/DeathRouletteHandler.php');
        $this->assertIsString($src);

        return $src;
    }

    public function testDeathLossViaAtomicStatsService(): void
    {
        $this->assertStringContainsString('CharacterStatsService', $this->source());
    }

    public function testNoSnapshotAbsoluteStatWrite(): void
    {
        $src = $this->source();

        // Убраны снапшот-переменные и абсолют-запись урезанных статов.
        $this->assertStringNotContainsString('$loserOldExp', $src);
        $this->assertStringNotContainsString('$updatedLoser', $src);
    }
}

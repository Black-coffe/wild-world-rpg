<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Commands\AuditCraftGoldLoop;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use ReflectionClass;

/**
 * Шапка отчёта `audit:craft-gold-loop` обязана называть фактический источник данных
 * подключения, а не захардкоженный текст об окружении (2026-08-21: прод-прогон объявил себя
 * «локальной БД, слепок testbot», хотя реально читал прод — следующий человек прочитал бы
 * боевые числа как локальные). Правится только шапка, замер и структура отчёта не тронуты.
 *
 * @internal
 */
final class AuditCraftGoldLoopSourceTest extends CIUnitTestCase
{
    /**
     * `connectionSource()` обязан вернуть реальные hostname/database активного подключения,
     * а не строку-заглушку — иначе фикс просто переносит захардкоженный текст на новое место.
     */
    public function testConnectionSourceReportsActualHostnameAndDatabase(): void
    {
        $command = $this->makeCommand();
        $source  = $this->invokePrivate($command, 'connectionSource');

        $db = Database::connect();
        $this->assertSame($db->hostname . '/' . $db->getDatabase(), $source);
        $this->assertNotSame('локальная БД, слепок testbot', $source);
    }

    /**
     * Регресс-щит: шапка отчёта обязана нести переданный источник и НЕ содержать старый
     * захардкоженный текст, даже если данные для замера (буквенные аргументы) — фиктивные.
     */
    public function testRenderedReportEmbedsSourceAndDropsHardcodedLabel(): void
    {
        $command = $this->makeCommand();
        $report  = $this->invokePrivate($command, 'renderMarkdown', [
            'some-prod-host/wildworld_prod',
            '2026-08-01 00:00:00',
            '2026-08-21 00:00:00',
            5, 5, 5, 5, 0, 0.0, 0.0, 0.0, 0.0, 0, [],
        ]);

        $this->assertStringContainsString('источник: some-prod-host/wildworld_prod', $report);
        $this->assertStringNotContainsString('локальная БД, слепок testbot', $report);
    }

    /**
     * Второе место того же дефекта: примечание про малую выборку (fullCycleCount < 20) раньше
     * жёстко утверждало «Локальная БД — старый/малый слепок testbot; ... на testbot» независимо
     * от реального источника — это и попало в боевой прод-отчёт от 2026-08-21. Примечание обязано
     * называть фактический источник этого прогона, а не предполагать окружение.
     */
    public function testSmallSampleNoteEmbedsSourceAndDropsTestbotAssumption(): void
    {
        $command = $this->makeCommand();
        $report  = $this->invokePrivate($command, 'renderMarkdown', [
            'some-prod-host/wildworld_prod',
            '2026-08-01 00:00:00',
            '2026-08-21 00:00:00',
            5, 5, 5, 5, 5, 0.0, 0.0, 0.0, 0.0, 0, [],
        ]);

        $this->assertStringContainsString('Выборка меньше 20', $report);
        $this->assertStringContainsString('Источник этого прогона — **some-prod-host/wildworld_prod**', $report);
        $this->assertStringNotContainsString('Локальная БД', $report);
        $this->assertStringNotContainsString('на testbot', $report);
    }

    private function makeCommand(): AuditCraftGoldLoop
    {
        $logger = service('logger');

        return new AuditCraftGoldLoop($logger, service('commands'));
    }

    /**
     * @param list<mixed> $args
     */
    private function invokePrivate(object $object, string $method, array $args = []): mixed
    {
        $ref    = new ReflectionClass($object);
        $method = $ref->getMethod($method);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $args);
    }
}

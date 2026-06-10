<?php

use App\Services\PVE\PveNotificationSender;
use App\TaskHandlers\NPC\AutoPveHandler;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Регресс на авто-PvE grind-loop (лог-ревью 2026-06-10).
 *
 * Корень: AutoPveHandler-крон бьёт каждую минуту без кулдауна и floor'ит HP
 * проигравшего в 1 → застрявший у агрессивного NPC игрок дрался вечно (чар 994:
 * 926 боёв/день от легаси L15 «Рейдера Песчаных Волков» у кромки новичковой зоны)
 * + уведомление за КАЖДЫЙ бой летело в заблокировавший бота аккаунт
 * (240 ERROR/день «bot was blocked»).
 *
 * Фикс — три гейта:
 *  • AutoPveHandler::shouldSkipByHealth — лежачий (health ≤ порога) пропускается;
 *  • pair-cooldown (cache TTL по pairCooldownKey) — пара (игрок, спавн) не чаще раза в N минут;
 *  • PveNotificationSender::isRecipientBlocked — заблокировавшим (blocked_at) не шлём.
 * Здесь тестируем чистые предикаты (без БД/cache/Telegram).
 *
 * @internal
 */
final class AutoPveAntiGrindGateTest extends CIUnitTestCase
{
    public function testFlooredPlayerIsSkipped(): void
    {
        // Прод-кейс: чар 994, HP=1.00 после проигрыша (бой floor'ит в 1) — пропускаем.
        $this->assertTrue(AutoPveHandler::shouldSkipByHealth(1.0, 1.0));
    }

    public function testHealthyPlayerIsNotSkipped(): void
    {
        $this->assertFalse(AutoPveHandler::shouldSkipByHealth(1.01, 1.0));
        $this->assertFalse(AutoPveHandler::shouldSkipByHealth(100.0, 1.0));
    }

    public function testZeroThresholdDisablesGate(): void
    {
        // 0 = гейт выключен (старое поведение): даже лежачий не пропускается.
        $this->assertFalse(AutoPveHandler::shouldSkipByHealth(1.0, 0.0));
        $this->assertFalse(AutoPveHandler::shouldSkipByHealth(0.5, 0.0));
    }

    public function testRaisedThresholdSkipsWeakened(): void
    {
        // Админ поднял порог до 5: ослабленные (HP ≤ 5) не трогаются авто-PvE.
        $this->assertTrue(AutoPveHandler::shouldSkipByHealth(3.31, 5.0));
        $this->assertFalse(AutoPveHandler::shouldSkipByHealth(5.5, 5.0));
    }

    public function testPairCooldownKeyIsPerPair(): void
    {
        // Ключ уникален по паре — кулдаун одного игрока не гасит бой другого с тем же спавном.
        $this->assertSame('autopve_pair_994_1914', AutoPveHandler::pairCooldownKey(994, 1914));
        $this->assertNotSame(
            AutoPveHandler::pairCooldownKey(994, 1914),
            AutoPveHandler::pairCooldownKey(1092, 1914)
        );
        $this->assertNotSame(
            AutoPveHandler::pairCooldownKey(994, 1914),
            AutoPveHandler::pairCooldownKey(994, 2000)
        );
    }

    public function testBlockedRecipientIsDetected(): void
    {
        $this->assertTrue(PveNotificationSender::isRecipientBlocked('2026-06-10 00:00:00'));
        $this->assertFalse(PveNotificationSender::isRecipientBlocked(null));
        $this->assertFalse(PveNotificationSender::isRecipientBlocked(''));
    }
}

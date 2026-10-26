<?php

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\BotController;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * S4 — вебхук fail-closed: в production пустой `telegram.WEBHOOK_SECRET` больше не пропускает
 * апдейт мимо проверки. Решение вынесено в чистый `BotController::webhookSecretVerdict()`.
 *
 * @internal
 */
final class BotControllerWebhookSecretTest extends CIUnitTestCase
{
    private const SECRET = 'a3f1c9e07b5d42e8a3f1c9e07b5d42e8a3f1c9e07b5d42e8a3f1c9e07b5d42e8';

    public function testProductionWithoutSecretRejectsEveryUpdate(): void
    {
        // Исходный случай S4: пустой секрет в production раньше пропускал любой POST.
        $this->assertFalse(BotController::webhookSecretVerdict('', '', 'production'));
        $this->assertFalse(BotController::webhookSecretVerdict('', 'anything', 'production'));
    }

    public function testProductionWithSecretChecksHeader(): void
    {
        $this->assertTrue(BotController::webhookSecretVerdict(self::SECRET, self::SECRET, 'production'));
        // Соседняя форма: чужой или пустой заголовок при заданном секрете.
        $this->assertFalse(BotController::webhookSecretVerdict(self::SECRET, 'wrong', 'production'));
        $this->assertFalse(BotController::webhookSecretVerdict(self::SECRET, '', 'production'));
    }

    public function testDevelopmentAndTestingWithoutSecretStillPass(): void
    {
        $this->assertTrue(BotController::webhookSecretVerdict('', '', 'development'));
        $this->assertTrue(BotController::webhookSecretVerdict('', '', 'testing'));
        $this->assertFalse(BotController::webhookSecretVerdict(self::SECRET, 'wrong', 'development'), 'заданный секрет сверяется в любой среде');
    }

    public function testWebhookRoutesThroughTheVerdict(): void
    {
        // Вызов вердикта стоит в webhook() до разбора тела и дедупа ADR-181.
        $src  = (string) file_get_contents(APPPATH . 'Controllers/Telegram/BotController.php');
        $body = substr($src, (int) strpos($src, 'public function webhook()'));
        $gate = strpos($body, 'self::webhookSecretVerdict(');
        $this->assertNotFalse($gate);
        $this->assertLessThan((int) strpos($body, 'getBody()'), $gate);
        $this->assertStringNotContainsString('if (!empty($expected))', $body, 'старый fail-open гейт удалён');
    }
}

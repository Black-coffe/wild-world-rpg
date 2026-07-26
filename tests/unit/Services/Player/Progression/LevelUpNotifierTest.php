<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player\Progression;

use App\Services\Player\Progression\LevelUnlockService;
use App\Services\Player\Progression\LevelUpNotifier;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Слайс «Видимая лестница L1→L10» — событие повышения уровня.
 *
 * Проверяем гейт (killswitch / только рост / верхний порог) и текст. Отправка и поиск чата —
 * seam'ы, подменённые test-double: ни Telegram, ни БД в unit-тесте не участвуют
 * (memory feedback_taskhandler_telegram_init_in_tests — eager-init валит CI и блокирует deploy).
 *
 * @internal
 */
final class LevelUpNotifierTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LevelUnlockService::resetCache();
    }

    /** @return array<string, mixed> */
    private function character(float $sum = 8.4): array
    {
        return [
            'id'               => 491,
            'telegram_user_id' => 25,
            'experience'       => $sum,
            'strength'         => 0.0,
            'agility'          => 0.0,
            'intellect'        => 0.0,
        ];
    }

    public function testDormantSendsNothing(): void
    {
        $svc     = new FakeLevelUpNotifier();
        $svc->on = false;

        $svc->notifyLevelUp($this->character(), 1, 2);

        $this->assertSame([], $svc->sent);
    }

    public function testLevelDropIsSilent(): void
    {
        // Потеря статов при смерти опускает уровень — добивать сообщением не за чем.
        $svc = new FakeLevelUpNotifier();

        $svc->notifyLevelUp($this->character(), 3, 2);

        $this->assertSame([], $svc->sent);
    }

    public function testUnchangedLevelIsSilent(): void
    {
        $svc = new FakeLevelUpNotifier();

        $svc->notifyLevelUp($this->character(), 2, 2);

        $this->assertSame([], $svc->sent);
    }

    public function testLevelUpSendsToOwnersChat(): void
    {
        $svc = new FakeLevelUpNotifier();

        $svc->notifyLevelUp($this->character(), 1, 2);

        $this->assertCount(1, $svc->sent);
        $this->assertSame(6995661239, $svc->sent[0][0]);
        $this->assertStringContainsString('Новый уровень: 2', $svc->sent[0][1]);
    }

    public function testMissingChatIsSilent(): void
    {
        $svc         = new FakeLevelUpNotifier();
        $svc->chatId = null;

        $svc->notifyLevelUp($this->character(), 1, 2);

        $this->assertSame([], $svc->sent);
    }

    public function testMaxLevelCapsNotifications(): void
    {
        $svc           = new FakeLevelUpNotifier();
        $svc->maxLevel = 10;

        $svc->notifyLevelUp($this->character(44.0), 10, 11);
        $this->assertSame([], $svc->sent);

        $svc->notifyLevelUp($this->character(40.0), 9, 10);
        $this->assertCount(1, $svc->sent);
    }

    public function testZeroMaxLevelMeansNoCap(): void
    {
        $svc           = new FakeLevelUpNotifier();
        $svc->maxLevel = 0;

        $svc->notifyLevelUp($this->character(204.0), 50, 51);

        $this->assertCount(1, $svc->sent);
    }

    // ── Текст ───────────────────────────────────────────────────────────

    public function testComposeCarriesLevelAndDistanceToNext(): void
    {
        $svc  = new FakeLevelUpNotifier();
        // Сумма 8.4 → только что взял L2, до L3 нужно 12.0, осталось 3.6.
        $text = $svc->compose($this->character(8.4), 2);

        $this->assertStringContainsString('🎉 *Новый уровень: 2*', $text);
        $this->assertStringContainsString('До уровня 3', $text);
        $this->assertStringContainsString('3.6', $text);
        $this->assertStringContainsString('12.0', $text);
    }

    public function testComposeMarkdownBalanced(): void
    {
        $svc = new FakeLevelUpNotifier();

        foreach ([2, 5, 10, 51] as $level) {
            $text = $svc->compose($this->character($level * 4.0 + 0.4), $level);
            $this->assertSame(0, substr_count($text, '*') % 2, "непарные * на уровне {$level}");
            $this->assertSame(0, substr_count($text, '_') % 2, "непарные _ на уровне {$level}");
        }
    }

    /** Media-off (ADR-020): весь смысл в тексте, картинок не шлём вовсе. */
    public function testTextIsSelfSufficient(): void
    {
        $text = (new FakeLevelUpNotifier())->compose($this->character(8.4), 2);

        $this->assertStringNotContainsString('на изображении', $text);
        $this->assertGreaterThan(40, mb_strlen($text));
    }
}

/**
 * Test-double: подменяет killswitch, порог, поиск чата и саму отправку.
 *
 * @internal
 */
final class FakeLevelUpNotifier extends LevelUpNotifier
{
    public bool $on = true;
    public int $maxLevel = 0;
    public ?int $chatId = 6995661239;

    /** @var list<array{0: int, 1: string}> */
    public array $sent = [];

    protected function gsBool(string $key, bool $default): bool
    {
        return $this->on;
    }

    protected function gsInt(string $key, int $default): int
    {
        return $this->maxLevel;
    }

    protected function chatIdFor(array|\App\Entities\CharacterEntity $character): ?int
    {
        return $this->chatId;
    }

    protected function unlocks(): LevelUnlockService
    {
        // Контент-таблиц в тестовой БД нет — детерминированный дубль без выборок.
        return new class extends LevelUnlockService {
            protected function countsFor(int $level): array
            {
                return ['resource' => 18];
            }

            protected function systemGatesFor(int $level): array
            {
                return [];
            }
        };
    }

    protected function send(int $chatId, string $text): void
    {
        $this->sent[] = [$chatId, $text];
    }
}

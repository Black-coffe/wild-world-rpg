<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Events;

use App\Services\Events\PassiveHealthWarning;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-200 (sudden-death-1125 S3) — одно предупреждение, когда удар события переводит здоровье через порог
 * `health.passive_warn_threshold` вниз; прямо из пути урона, а не из минутной рулетки.
 *
 * @internal
 */
final class PassiveHealthWarningTest extends CIUnitTestCase
{
    public function testCrossingTheThresholdSendsOneWarning(): void
    {
        $w = new WarningSpy(30.0);

        $this->assertTrue($w->maybeWarn($this->char(), 40.0, 25.0, 'Подземные толчки', null));
        $this->assertCount(1, $w->sent);
        $this->assertSame(777, $w->sent[0]['chat_id']);
        $this->assertStringStartsWith('Мой дорогой выживальщик *', $w->sent[0]['text'], 'тот же текст, что у крона: /play отсекает его как чужое фоновое');
        $this->assertStringContainsString('Подземные толчки', $w->sent[0]['text']);
        $this->assertStringContainsString('базу', $w->sent[0]['text']);
        $this->assertStringContainsString('25.00', $w->sent[0]['text']);
    }

    public function testOneHitJumpingToTheFloorSendsOne(): void
    {
        $w = new WarningSpy(30.0);

        $w->maybeWarn($this->char(), 40.0, 1.0, 'Подземные толчки', 'Bandage');

        $this->assertCount(1, $w->sent);
        $this->assertStringContainsString('Бинт', $w->sent[0]['text'], 'защитный предмет — русским именем');
        $this->assertStringNotContainsString('Bandage', $w->sent[0]['text']);
    }

    public function testAlreadyBelowSendsNone(): void
    {
        $w = new WarningSpy(30.0);

        $this->assertFalse($w->maybeWarn($this->char(), 25.0, 20.0, 'Подземные толчки', null));
        $this->assertSame([], $w->sent);
    }

    public function testStayingAboveOrHealingSendsNone(): void
    {
        $w = new WarningSpy(30.0);

        $w->maybeWarn($this->char(), 50.0, 31.0, 'Подземные толчки', null);
        $w->maybeWarn($this->char(), 20.0, 35.0, 'Чистый родник', null);

        $this->assertSame([], $w->sent);
    }

    public function testExactlyOnTheThresholdBeforeCounts(): void
    {
        $w = new WarningSpy(30.0);

        $w->maybeWarn($this->char(), 30.0, 29.0, 'Подземные толчки', null);

        $this->assertCount(1, $w->sent);
    }

    public function testOptedOutPlayerGetsNone(): void
    {
        $w = new WarningSpy(30.0);

        $this->assertFalse($w->maybeWarn($this->char(['health_warnings_enabled' => 0]), 40.0, 25.0, 'Подземные толчки', null));
        $this->assertFalse($w->maybeWarn($this->char(['health_warnings_enabled' => '0']), 40.0, 25.0, 'Подземные толчки', null));
        $this->assertSame([], $w->sent);
    }

    public function testNoChatSendsNothing(): void
    {
        $w         = new WarningSpy(30.0);
        $w->chatId = null;

        $this->assertFalse($w->maybeWarn($this->char(), 40.0, 25.0, 'Подземные толчки', null));
        $this->assertSame([], $w->sent);
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function char(array $over = []): array
    {
        return array_merge(['id' => 5, 'name' => 'Евгений', 'telegram_user_id' => 3, 'health' => 25.0, 'health_warnings_enabled' => 1], $over);
    }
}

/**
 * Шов: порог без GameSettings, чат без БД, отправка в массив.
 */
final class WarningSpy extends PassiveHealthWarning
{
    /** @var list<array{chat_id: int, text: string, keyboard: array<string, mixed>}> */
    public array $sent = [];

    public ?int $chatId = 777;

    public function __construct(private float $fixed)
    {
    }

    public function threshold(): float
    {
        return $this->fixed;
    }

    protected function chatIdFor(int $characterId): ?int
    {
        return $this->chatId;
    }

    protected function protectionName(string $nameEng): string
    {
        return $nameEng === 'Bandage' ? 'Бинт' : $nameEng;
    }

    protected function send(int $chatId, string $text, array $keyboard): void
    {
        $this->sent[] = ['chat_id' => $chatId, 'text' => $text, 'keyboard' => $keyboard];
    }
}

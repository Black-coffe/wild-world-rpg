<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\ColdOpenGreetingService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * S4 (ROADMAP-RETENTION-10, ADR-139) — cold-open framing /start (слайс 1b).
 *
 * Флаг — через test-double (без GameSettings/БД на CI). Текст — статичный, проверяем
 * инварианты копии (media-off / markdown-safe / тон Роби / короче легаси).
 *
 * @internal
 */
final class ColdOpenGreetingServiceTest extends CIUnitTestCase
{
    public function testGreetingEnabledReadsFlag(): void
    {
        $on = new FakeColdOpenGreetingService();
        $on->on = true;
        $this->assertTrue($on->greetingEnabled());

        $off = new FakeColdOpenGreetingService();
        $off->on = false;
        $this->assertFalse($off->greetingEnabled());
    }

    public function testGreetingNonEmptyAndRobiVoice(): void
    {
        $g = (new ColdOpenGreetingService())->greeting();
        $this->assertNotSame('', trim($g));
        $this->assertStringContainsString('Роби', $g);
    }

    /** Legacy Markdown: непарные звёздочки/подчёркивания → 400/silent no-send. Копия сбалансирована. */
    public function testGreetingMarkdownBalanced(): void
    {
        $g = (new ColdOpenGreetingService())->greeting();
        $this->assertSame(0, substr_count($g, '*') % 2, 'непарные *');
        $this->assertSame(0, substr_count($g, '_') % 2, 'непарные _');
    }

    /** Cold-open задумана КОРОТКОЙ (S1: 121-слово слишком плотно) — держим планку. */
    public function testGreetingIsShort(): void
    {
        $g = (new ColdOpenGreetingService())->greeting();
        $words = count(preg_split('/\s+/', trim($g)) ?: []);
        $this->assertLessThan(80, $words, "cold-open приветствие должно быть коротким, а не {$words} слов");
    }

    /** Социальный крючок (общий чат) сохранён — валидная markdown-ссылка. */
    public function testGreetingKeepsChatLink(): void
    {
        $g = (new ColdOpenGreetingService())->greeting();
        $this->assertStringContainsString('[общем чате](https://t.me/wild_world_info)', $g);
    }
}

/**
 * Test-double: подменяет killswitch-seam детерминированным значением.
 *
 * @internal
 */
final class FakeColdOpenGreetingService extends ColdOpenGreetingService
{
    public bool $on = false;

    protected function gsBool(string $key, bool $default): bool
    {
        return $this->on;
    }
}

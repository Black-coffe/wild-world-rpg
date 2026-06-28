<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\NewbieAtmosphereService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * S9 (ROADMAP-RETENTION-10, ADR-147) — ранняя выживальческая атмосфера (NewbieAtmosphereService).
 *
 * Лочит: телеграфированный текст шороха (буря-форшадоу + 3 выбора), разные исходы выбора (🔴 без
 * урона/наград), JIT-текст про воду, markdown-баланс, и on-move оркестрацию через test-double
 * (killswitch / newbie-гейт / one-shot / приоритет «вода → шорох» / шанс). Tier-3 — живой ход.
 *
 * @internal
 */
final class NewbieAtmosphereServiceTest extends CIUnitTestCase
{
    private static function balanced(string $s): bool
    {
        return substr_count($s, '*') % 2 === 0 && substr_count($s, '_') % 2 === 0;
    }

    // ── Чистый текст ──────────────────────────────────────────────────────────

    public function testRustleMessageTelegraphedWithThreeChoices(): void
    {
        $m = NewbieAtmosphereService::buildRustleMessage();
        $this->assertStringContainsString('буря', $m['text']);     // форшадоу телеграфирования
        $this->assertStringContainsString('шурш', mb_strtolower($m['text'])); // «зашуршало»
        $this->assertStringContainsString('atmRustle_peek', $m['reply_markup']);
        $this->assertStringContainsString('atmRustle_hide', $m['reply_markup']);
        $this->assertStringContainsString('atmRustle_leave', $m['reply_markup']);
        $this->assertTrue(self::balanced($m['text']), 'markdown парный');
    }

    public function testResolveChoiceDistinctNonLethalOutcomes(): void
    {
        $peek  = NewbieAtmosphereService::resolveChoice('peek');
        $hide  = NewbieAtmosphereService::resolveChoice('hide');
        $leave = NewbieAtmosphereService::resolveChoice('leave');

        $this->assertNotSame($peek, $hide);
        $this->assertNotSame($hide, $leave);
        $this->assertNotSame($peek, $leave);
        foreach ([$peek, $hide, $leave] as $t) {
            $this->assertTrue(self::balanced($t), 'markdown парный');
            // 🔴 несмертельно/без награды: исход — нарратив, не числа/выдача
            $this->assertStringNotContainsString('получаешь', mb_strtolower($t));
        }
    }

    public function testResolveChoiceUnknownFallsBackToSafeLeave(): void
    {
        $this->assertSame(
            NewbieAtmosphereService::resolveChoice('leave'),
            NewbieAtmosphereService::resolveChoice('garbage_key'),
            'неизвестный ключ → безопасный default (уйти)'
        );
    }

    public function testThirstText(): void
    {
        $t = NewbieAtmosphereService::thirstText();
        $this->assertStringContainsString('вод', $t);          // воды/воду/воде
        $this->assertStringContainsString('ыносливость', $t); // «Выносливость»
        $this->assertTrue(self::balanced($t));
    }

    // ── Оркестрация (через test-double) ───────────────────────────────────────

    public function testOffIsNoOp(): void
    {
        $s = new FakeAtmos();
        $s->on = false;
        $this->assertNull($s->maybeSendAtmosphere(['id' => 1, 'level' => 1, 'tired' => 5], 100));
        $this->assertSame([], $s->sent);
    }

    public function testNonNewbieSkipped(): void
    {
        $s = new FakeAtmos(); // maxLevel default 5
        $this->assertNull($s->maybeSendAtmosphere(['id' => 1, 'level' => 6, 'tired' => 5], 100));
        $this->assertSame([], $s->sent);
    }

    public function testThirstFiresOnceWhenTiredLow(): void
    {
        $s = new FakeAtmos();
        $s->rollResult = false; // изолируем: шорох не мешает
        $beat = $s->maybeSendAtmosphere(['id' => 7, 'level' => 2, 'tired' => 10], 100);
        $this->assertSame('thirst', $beat);
        $this->assertCount(1, $s->sent);
        $this->assertStringContainsString('вод', $s->sent[0]['text']);
        $this->assertContains(NewbieAtmosphereService::THIRST_FLAG, $s->written);
    }

    public function testThirstNotRepeatedWhenAlreadyMarked(): void
    {
        $s = new FakeAtmos();
        $s->rollResult = false;
        $s->marked[]   = NewbieAtmosphereService::THIRST_FLAG;
        $beat = $s->maybeSendAtmosphere(['id' => 7, 'level' => 2, 'tired' => 10], 100);
        $this->assertNull($beat, 'вода уже показана → не повторяем, шорох выключен ролом');
        $this->assertSame([], $s->sent);
    }

    public function testRustleFiresOnRollWhenTiredOk(): void
    {
        $s = new FakeAtmos();
        $s->rollResult = true;
        $beat = $s->maybeSendAtmosphere(['id' => 9, 'level' => 1, 'tired' => 95], 100);
        $this->assertSame('rustle', $beat);
        $this->assertCount(1, $s->sent);
        $this->assertStringContainsString('atmRustle_peek', (string) ($s->sent[0]['reply'] ?? ''));
        $this->assertContains(NewbieAtmosphereService::RUSTLE_FLAG, $s->written);
    }

    public function testRustleSkippedOnRollFail(): void
    {
        $s = new FakeAtmos();
        $s->rollResult = false;
        $this->assertNull($s->maybeSendAtmosphere(['id' => 9, 'level' => 1, 'tired' => 95], 100));
        $this->assertSame([], $s->sent);
    }

    public function testRustleNotRepeatedWhenAlreadyMarked(): void
    {
        $s = new FakeAtmos();
        $s->rollResult = true;
        $s->marked[]   = NewbieAtmosphereService::RUSTLE_FLAG;
        $this->assertNull($s->maybeSendAtmosphere(['id' => 9, 'level' => 1, 'tired' => 95], 100));
        $this->assertSame([], $s->sent);
    }

    public function testThirstTakesPriorityOverRustle(): void
    {
        $s = new FakeAtmos();
        $s->rollResult = true; // даже если шорох бы сработал
        $beat = $s->maybeSendAtmosphere(['id' => 5, 'level' => 1, 'tired' => 5], 100);
        $this->assertSame('thirst', $beat, 'вода важнее «вау» — приоритет за ход');
        $this->assertCount(1, $s->sent);
    }
}

/**
 * Test-double: конфиг + БД/RNG/Telegram seam'ы в памяти.
 *
 * @internal
 */
final class FakeAtmos extends NewbieAtmosphereService
{
    public bool $on = true;
    public bool $rollResult = false;
    /** @var list<string> */
    public array $marked = [];
    /** @var list<string> */
    public array $written = [];
    /** @var list<array{chatId:int,text:string,reply:?string}> */
    public array $sent = [];

    protected function gsBool(string $key, bool $default): bool
    {
        return $this->on;
    }

    protected function gsInt(string $key, int $default): int
    {
        return $default; // max_level=5, tired_threshold=20
    }

    protected function gsFloat(string $key, float $default): float
    {
        return $default;
    }

    protected function roll(float $chance): bool
    {
        return $this->rollResult;
    }

    protected function markerSet(int $charId, string $flag): bool
    {
        return in_array($flag, $this->marked, true);
    }

    protected function writeMarker(int $charId, int $chatId, string $flag, string $desc): void
    {
        $this->written[] = $flag;
        $this->marked[]  = $flag; // последующие markerSet видят его
    }

    protected function send(int $chatId, string $text, ?string $replyMarkup = null): void
    {
        $this->sent[] = ['chatId' => $chatId, 'text' => $text, 'reply' => $replyMarkup];
    }
}

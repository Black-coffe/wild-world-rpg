<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player;

use App\Services\Player\LastSeenService;
use App\Services\Player\ReturnDigestService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ROADMAP-100 E6 (ADR-108) Фаза 2 — ReturnDigestService.
 * Чистый format() + extractChatId() + гейтинг maybeSendDigest через seam-подмену
 * (без БД/Telegram).
 *
 * @internal
 */
final class ReturnDigestServiceTest extends CIUnitTestCase
{
    /** @return array{buildings:array<int,string>,crafts:int,gathers:int,attacks:int,attack_wins:int,events:array<int,string>} */
    private function emptyData(): array
    {
        return ['buildings' => [], 'crafts' => 0, 'gathers' => 0, 'attacks' => 0, 'attack_wins' => 0, 'events' => []];
    }

    // ── format ──────────────────────────────────────────────────────────────

    public function testFormatNullWhenNothingHappened(): void
    {
        $this->assertNull(ReturnDigestService::format($this->emptyData(), 30));
    }

    public function testFormatBuildingsAndCrafts(): void
    {
        $data = $this->emptyData();
        $data['buildings'] = ['Мастерская', 'Склад ×2'];
        $data['crafts']    = 3;
        $text = ReturnDigestService::format($data, 30);
        $this->assertNotNull($text);
        $this->assertStringContainsString('С возвращением', $text);
        $this->assertStringContainsString('🏗 Достроено: Мастерская, Склад ×2', $text);
        $this->assertStringContainsString('⚒️ Завершён крафт: 3 предмета', $text);
    }

    public function testFormatAttacksWithWins(): void
    {
        $data = $this->emptyData();
        $data['attacks']     = 5;
        $data['attack_wins'] = 2;
        $text = ReturnDigestService::format($data, 30);
        $this->assertNotNull($text);
        $this->assertStringContainsString('⚔️ На тебя нападали: 5 раз (отбился 2)', $text);
    }

    public function testFormatEventsAndGathers(): void
    {
        $data = $this->emptyData();
        $data['gathers'] = 1;
        $data['events']  = ['Песчаная буря', 'Ночная вылазка'];
        $text = ReturnDigestService::format($data, 50);
        $this->assertNotNull($text);
        $this->assertStringContainsString('⛏ Добыча завершена: 1 раз', $text);
        $this->assertStringContainsString('🌪 Пережитые события: Песчаная буря, Ночная вылазка', $text);
    }

    // ── humanGap ────────────────────────────────────────────────────────────

    public function testHumanGapHoursAndDays(): void
    {
        $this->assertSame('30 часов', ReturnDigestService::humanGap(30));
        $this->assertSame('1 час', ReturnDigestService::humanGap(1));
        $this->assertSame('2 дня', ReturnDigestService::humanGap(48));
        $this->assertSame('5 дней', ReturnDigestService::humanGap(120));
    }

    // ── extractChatId ───────────────────────────────────────────────────────

    public function testExtractChatIdFromMessage(): void
    {
        $u = ['message' => ['chat' => ['id' => 555], 'from' => ['id' => 555]]];
        $this->assertSame(555, LastSeenService::extractChatId($u));
    }

    public function testExtractChatIdFromCallbackQuery(): void
    {
        $u = ['callback_query' => ['message' => ['chat' => ['id' => 777]], 'data' => 'x']];
        $this->assertSame(777, LastSeenService::extractChatId($u));
    }

    public function testExtractChatIdNullWhenAbsent(): void
    {
        $this->assertNull(LastSeenService::extractChatId(['inline_query' => ['from' => ['id' => 9]]]));
        $this->assertNull(LastSeenService::extractChatId([]));
    }

    // ── maybeSendDigest gating (seam-подмена) ─────────────────────────────────

    public function testGatingDormantWhenDisabled(): void
    {
        $svc = $this->stubService(enabled: false, lastSeen: '2026-06-01 10:00:00', grace: 24);
        $svc->maybeSendDigest(123, 123);
        $this->assertSame([], $svc->sent);
    }

    public function testGatingSkipsWhenWithinGrace(): void
    {
        // last_seen 5 часов назад, grace 24 → не шлём.
        $recent = date('Y-m-d H:i:s', time() - 5 * 3600);
        $svc = $this->stubService(enabled: true, lastSeen: $recent, grace: 24);
        $svc->maybeSendDigest(123, 123);
        $this->assertSame([], $svc->sent);
    }

    public function testGatingSkipsWhenNullLastSeen(): void
    {
        // Новичок: last_seen null → hoursAgo null → не шлём.
        $svc = $this->stubService(enabled: true, lastSeen: null, grace: 24);
        $svc->maybeSendDigest(123, 123);
        $this->assertSame([], $svc->sent);
    }

    public function testGatingSkipsWhenNothingHappened(): void
    {
        // Давно не был, но фоновых изменений нет → format null → не шлём.
        $old = date('Y-m-d H:i:s', time() - 50 * 3600);
        $svc = $this->stubService(enabled: true, lastSeen: $old, grace: 24, data: $this->emptyData());
        $svc->maybeSendDigest(123, 123);
        $this->assertSame([], $svc->sent);
    }

    public function testGatingSendsWhenReturnedWithChanges(): void
    {
        $old  = date('Y-m-d H:i:s', time() - 50 * 3600);
        $data = $this->emptyData();
        $data['buildings'] = ['Теплица'];
        $svc = $this->stubService(enabled: true, lastSeen: $old, grace: 24, data: $data);
        $svc->maybeSendDigest(123, 999);
        $this->assertCount(1, $svc->sent);
        $this->assertSame(999, $svc->sent[0]['chat_id']);
        $text = $svc->sent[0]['text'];
        $this->assertIsString($text);
        $this->assertStringContainsString('🏗 Достроено: Теплица', $text);
    }

    /**
     * @param array{buildings:array<int,string>,crafts:int,gathers:int,attacks:int,attack_wins:int,events:array<int,string>}|null $data
     */
    private function stubService(bool $enabled, ?string $lastSeen, int $grace, ?array $data = null): DigestStubForTest
    {
        $stubData = $data;
        if ($stubData === null) {
            $stubData = $this->emptyData();
            $stubData['buildings'] = ['X'];
        }

        return new DigestStubForTest($enabled, $lastSeen, $grace, $stubData);
    }
}

/**
 * Тест-дабл ReturnDigestService: seam'ы подменены, отправки копятся в $sent.
 *
 * @internal
 */
final class DigestStubForTest extends ReturnDigestService
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    /** @param array{buildings:array<int,string>,crafts:int,gathers:int,attacks:int,attack_wins:int,events:array<int,string>} $data */
    public function __construct(
        private bool $en,
        private ?string $ls,
        private int $gr,
        private array $data,
    ) {
    }

    protected function enabled(): bool
    {
        return $this->en;
    }

    protected function graceHours(): int
    {
        return $this->gr;
    }

    protected function resolveContext(int $telegramId): array
    {
        return ['char_id' => 491, 'last_seen' => $this->ls];
    }

    protected function collect(int $charId, string $since, string $until): array
    {
        return $this->data;
    }

    protected function send(array $payload): void
    {
        $this->sent[] = $payload;
    }
}

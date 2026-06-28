<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player;

use App\Services\Player\ReferralService;
use App\Services\Player\TitleService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * S8 (ROADMAP-RETENTION-10, ADR-146) — реферальная петля «позови выжившего».
 *
 * Лочит: чистый парсинг deep-link (`ref_<id>` строго), сборку текста экрана (счётчик + fallback,
 * markdown-баланс), уведомление, и оркестрацию записи ребра / выдачи награды через test-double
 * (без БД). 🔴 Инварианты: anti-self-invite, cap, first-touch (dup), killswitch OFF = no-op,
 * награда только за дозревшего приглашённого. Tier-3 — живой цикл двух чаров.
 *
 * @internal
 */
final class ReferralServiceTest extends CIUnitTestCase
{
    // ── parseReferrerId (чистый) ──────────────────────────────────────────────

    public function testParseReferrerIdValid(): void
    {
        $svc = new ReferralService();
        $this->assertSame(123, $svc->parseReferrerId('ref_123'));
        $this->assertSame(5, $svc->parseReferrerId('  ref_5  '));
        $this->assertSame(7, $svc->parseReferrerId('ref_07'));
    }

    public function testParseReferrerIdRejectsNonReferral(): void
    {
        $svc = new ReferralService();
        $this->assertNull($svc->parseReferrerId(null));
        $this->assertNull($svc->parseReferrerId(''));
        $this->assertNull($svc->parseReferrerId('src_site_habr')); // маркетинг-атрибуция, не реферал
        $this->assertNull($svc->parseReferrerId('ref_abc'));
        $this->assertNull($svc->parseReferrerId('ref_0'));       // 0 невалиден
        $this->assertNull($svc->parseReferrerId('ref_12x'));
        $this->assertNull($svc->parseReferrerId('xref_12'));
        $this->assertNull($svc->parseReferrerId('ref_'));
    }

    // ── buildScreenText (чистый) ──────────────────────────────────────────────

    // Убирает code-спаны (backticks): внутри них Telegram legacy-Markdown НЕ парсит звёздочки и
    // подчёркивания — а ссылка-приглашение содержит ref_<id> с подчёркиванием.
    private static function stripCode(string $s): string
    {
        return preg_replace('/`[^`]*`/', '', $s) ?? $s;
    }

    public function testScreenTextWithCounts(): void
    {
        $t = ReferralService::buildScreenText('https://t.me/bot?start=ref_42', 5, 2);
        $this->assertStringContainsString('ref_42', $t);
        $this->assertStringContainsString('5', $t);   // откликнулось
        $this->assertStringContainsString('2', $t);   // освоились
        $this->assertStringContainsString('3', $t);   // обживаются = 5 - 2
        $this->assertStringContainsString('Зовущий', $t);
        $this->assertStringContainsString('не игровое преимущество', $t);
        $stripped = self::stripCode($t);
        $this->assertSame(0, substr_count($stripped, '*') % 2, 'парные * вне code-спанов');
        $this->assertSame(0, substr_count($stripped, '_') % 2, 'парные _ вне code-спанов');
    }

    public function testScreenTextEmptyFallback(): void
    {
        $t = ReferralService::buildScreenText('https://t.me/bot?start=ref_42', 0, 0);
        $this->assertStringContainsString('Пока никто', $t);
        $this->assertStringContainsString('ref_42', $t);
        $stripped = self::stripCode($t);
        $this->assertSame(0, substr_count($stripped, '*') % 2);
        $this->assertSame(0, substr_count($stripped, '_') % 2);
    }

    public function testNotifyTextMarkdownBalanced(): void
    {
        $t = ReferralService::notifyText();
        $this->assertStringContainsString('Зовущий', $t);
        $this->assertSame(0, substr_count($t, '*') % 2);
        $this->assertSame(0, substr_count($t, '_') % 2);
    }

    public function testInviteLinkContainsToken(): void
    {
        $svc = new FakeReferralService();
        $this->assertSame('https://t.me/testbot?start=ref_99', $svc->inviteLink(99));
    }

    public function testScreenPayloadHasShareAndBack(): void
    {
        $svc = new FakeReferralService();
        $svc->stats = ['invited' => 3, 'qualified' => 1];
        $p = $svc->screenPayload(99);
        $this->assertStringContainsString('ref_99', $p['text']);
        // json_encode экранирует / как \/, потому матчим по 'share' (часть share-url кнопки).
        $this->assertStringContainsString('share', $p['reply_markup']);
        $this->assertStringContainsString('character', $p['reply_markup'], 'кнопка назад → character');
    }

    // ── recordReferralOnRegister (оркестрация через seam'ы) ────────────────────

    public function testRecordDisabledWhenOff(): void
    {
        $svc = new FakeReferralService();
        $svc->on = false;
        $this->assertSame('disabled', $svc->recordReferralOnRegister(1, 2, 10));
        $this->assertCount(0, $svc->inserted);
    }

    public function testRecordAntiSelfInvite(): void
    {
        $svc = new FakeReferralService();
        $this->assertSame('self', $svc->recordReferralOnRegister(7, 7, 10));
        $this->assertCount(0, $svc->inserted);
    }

    public function testRecordInvalidGhostReferrer(): void
    {
        $svc = new FakeReferralService();
        $svc->referrerOk = false; // ref_<id> подделан, такого пользователя нет
        $this->assertSame('invalid', $svc->recordReferralOnRegister(1, 2, 10));
        $this->assertCount(0, $svc->inserted);
    }

    public function testRecordDuplicateFirstTouch(): void
    {
        $svc = new FakeReferralService();
        $svc->existingReferred = [2];
        $this->assertSame('dup', $svc->recordReferralOnRegister(1, 2, 10));
        $this->assertCount(0, $svc->inserted);
    }

    public function testRecordCapped(): void
    {
        $svc = new FakeReferralService();
        $svc->countByReferrer = [1 => 50]; // достиг cap (default 50)
        $this->assertSame('capped', $svc->recordReferralOnRegister(1, 2, 10));
        $this->assertCount(0, $svc->inserted);
    }

    public function testRecordHappyPath(): void
    {
        $svc = new FakeReferralService();
        $this->assertSame('recorded', $svc->recordReferralOnRegister(1, 2, 10));
        $this->assertSame([[1, 2, 10]], $svc->inserted);
    }

    // ── qualifyAndReward (оркестрация + награда через spy) ─────────────────────

    public function testQualifyNoOpWhenOff(): void
    {
        $svc = new FakeReferralService();
        $svc->on = false;
        $this->assertSame([], $svc->qualifyAndReward());
    }

    public function testQualifyNoOpWhenTitlesOff(): void
    {
        $spy = new SpyTitleService();
        $spy->on = false;
        $svc = new FakeReferralService($spy);
        $svc->qualified = [['id' => 1, 'referrer_user_id' => 1, 'referred_user_id' => 2]];
        $this->assertSame([], $svc->qualifyAndReward());
        $this->assertCount(0, $spy->awards);
    }

    public function testQualifyAwardsTitleAndNotifies(): void
    {
        $spy = new SpyTitleService();
        $svc = new FakeReferralService($spy);
        $svc->qualified = [
            ['id' => 11, 'referrer_user_id' => 1, 'referred_user_id' => 2],
            ['id' => 12, 'referrer_user_id' => 3, 'referred_user_id' => 4],
        ];
        $notices = $svc->qualifyAndReward();

        $this->assertCount(2, $notices);
        $this->assertCount(2, $spy->awards);
        $this->assertSame([10, 7], $spy->awards[0], 'referrer char 1*10=10, title 7');
        $this->assertSame([11, 12], $svc->rewarded, 'оба ребра закрыты');
        $this->assertStringContainsString('Зовущий', $notices[0]['text']);
        $this->assertSame(1001, $notices[0]['chat_id'], 'chat = referrer 1 → 1000+1');
    }

    public function testQualifySkipsReferrerWithoutCharacter(): void
    {
        $spy = new SpyTitleService();
        $svc = new FakeReferralService($spy);
        $svc->noChar = [1]; // у реферрера 1 нет персонажа
        $svc->qualified = [['id' => 11, 'referrer_user_id' => 1, 'referred_user_id' => 2]];
        $notices = $svc->qualifyAndReward();
        $this->assertSame([], $notices);
        $this->assertCount(0, $spy->awards);
        $this->assertSame([], $svc->rewarded, 'не награждён → ребро остаётся на следующий тик');
    }

    public function testQualifyNoOpWhenTitleMissing(): void
    {
        $spy = new SpyTitleService();
        $svc = new FakeReferralService($spy);
        $svc->titleId = 0; // титул «Зовущий» не найден/выключен
        $svc->qualified = [['id' => 11, 'referrer_user_id' => 1, 'referred_user_id' => 2]];
        $this->assertSame([], $svc->qualifyAndReward());
    }
}

/**
 * Test-double: все DB-seam'ы и конфиг в памяти (без БД).
 *
 * @internal
 */
final class FakeReferralService extends ReferralService
{
    public bool $on = true;
    public bool $referrerOk = true;
    /** @var list<int> */
    public array $existingReferred = [];
    /** @var array<int,int> referrer_user_id => count */
    public array $countByReferrer = [];
    /** @var list<array{0:int,1:int,2:?int}> */
    public array $inserted = [];
    /** @var list<array<string,mixed>> */
    public array $qualified = [];
    /** @var list<int> */
    public array $rewarded = [];
    /** @var list<int> referrer_user_id без персонажа */
    public array $noChar = [];
    public int $titleId = 7;
    /** @var array{invited:int,qualified:int} */
    public array $stats = ['invited' => 0, 'qualified' => 0];

    protected function gsBool(string $key, bool $default): bool
    {
        return $this->on;
    }

    protected function gsInt(string $key, int $default): int
    {
        return $default; // qualify_level=2, max_per_referrer=50
    }

    protected function botUsername(): string
    {
        return 'testbot';
    }

    protected function referrerExists(int $userId): bool
    {
        return $this->referrerOk;
    }

    protected function referralExists(int $referredUserId): bool
    {
        return in_array($referredUserId, $this->existingReferred, true);
    }

    protected function referralCount(int $referrerUserId): int
    {
        return $this->countByReferrer[$referrerUserId] ?? 0;
    }

    protected function insertReferral(int $referrerUserId, int $referredUserId, ?int $referredCharacterId): bool
    {
        $this->inserted[] = [$referrerUserId, $referredUserId, $referredCharacterId];

        return true;
    }

    protected function referralTitleId(): int
    {
        return $this->titleId;
    }

    /**
     * @return list<array<string,mixed>>
     */
    protected function findQualifiedUnrewarded(int $qualifyLevel, int $limit): array
    {
        return $this->qualified;
    }

    protected function referrerCharacterId(int $referrerUserId): int
    {
        return in_array($referrerUserId, $this->noChar, true) ? 0 : $referrerUserId * 10;
    }

    protected function referrerChatId(int $referrerUserId): int
    {
        return 1000 + $referrerUserId;
    }

    protected function markRewarded(int $referralId): void
    {
        $this->rewarded[] = $referralId;
    }

    /**
     * @return array{invited:int, qualified:int}
     */
    protected function statsRow(int $referrerUserId): array
    {
        return $this->stats;
    }
}

/**
 * Spy: ловит выдачи титулов без БД.
 *
 * @internal
 */
final class SpyTitleService extends TitleService
{
    public bool $on = true;
    /** @var list<array{0:int,1:int}> */
    public array $awards = [];

    public function __construct()
    {
        // намеренно НЕ вызываем parent — settings не нужен (enabled/award переопределены).
    }

    public function enabled(): bool
    {
        return $this->on;
    }

    public function award(int $characterId, int $titleId): bool
    {
        $this->awards[] = [$characterId, $titleId];

        return true;
    }
}

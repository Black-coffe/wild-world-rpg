<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\Services\Display\MarkdownSafe;
use App\Services\Social\LeaderboardScreen;
use App\Services\Social\LeaderboardService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Экран «🏆 Топ игроков» — рендер без БД (сервис замокан).
 *
 * Запирает решения, принятые по прод-данным (611 чаров, 529 из них на L1, а топ «за всё
 * время» наполовину из ветеранов, заблокировавших бота):
 *  - вкладка по умолчанию — ЖИВЫЕ, не легенды (иначе новичок видит стену из призраков);
 *  - «Ты: #N из M» присутствует всегда, иначе игрок себя в списке из 10 имён не находит;
 *  - рейтинг — ПРЕСТИЖ без наград (анти-абьюз, зеркало PvpLadder);
 *  - имена markdown-safe: непарная `*` в нике иначе валит парсинг ВСЕГО сообщения.
 *
 * @internal
 */
final class LeaderboardScreenTest extends CIUnitTestCase
{
    /** @param list<array{rank:int,id:int,name:string,level:int}> $active */
    private function screenWith(array $active, int $rankActive = 0, array $legends = []): LeaderboardScreen
    {
        $svc = $this->createMock(LeaderboardService::class);
        $svc->method('activeDays')->willReturn(7);
        $svc->method('topActive')->willReturn($active);
        $svc->method('topLegends')->willReturn($legends);
        $svc->method('rankOfActive')->willReturn($rankActive);
        $svc->method('rankOfLegends')->willReturn(3);
        $svc->method('totalActive')->willReturn(84);
        $svc->method('totalLegends')->willReturn(611);

        return new LeaderboardScreen($svc);
    }

    /** @return list<array{rank:int,id:int,name:string,level:int}> */
    private function rows(): array
    {
        return [
            ['rank' => 1, 'id' => 646, 'name' => 'Arich', 'level' => 215],
            ['rank' => 2, 'id' => 644, 'name' => 'Torch0010', 'level' => 175],
            ['rank' => 3, 'id' => 798, 'name' => 'San', 'level' => 113],
            ['rank' => 4, 'id' => 1106, 'name' => 'akukinakis', 'level' => 26],
        ];
    }

    public function testDefaultTabIsAliveNotLegends(): void
    {
        $payload = $this->screenWith($this->rows(), 4)->payload(1106);

        $this->assertStringContainsString('🔥 Живые', $payload['text']);
        $this->assertStringNotContainsString('Легенды острова*', $payload['text']);
        $this->assertStringContainsString('за последние 7 дн', $payload['text']);
    }

    public function testMedalsAndSelfHighlight(): void
    {
        $text = $this->screenWith($this->rows(), 4)->payload(1106)['text'];

        $this->assertStringContainsString('🥇 1. Arich — ур. 215', $text);
        $this->assertStringContainsString('🥈 2.', $text);
        $this->assertStringContainsString('🥉 3.', $text);
        // Себя игрок обязан находить взглядом.
        $this->assertStringContainsString('*akukinakis* ← ты', $text);
    }

    public function testShowsMyPositionOutOfTotal(): void
    {
        $text = $this->screenWith($this->rows(), 4)->payload(1106)['text'];

        $this->assertStringContainsString('👤 *Ты:* #4 из 84', $text);
    }

    public function testInactivePlayerGetsInvitationNotSilence(): void
    {
        // rankOfActive=0 → игрок не заходил в окно: не молчим, а объясняем, как попасть.
        $text = $this->screenWith($this->rows(), 0)->payload(999)['text'];

        $this->assertStringContainsString('пока не в списке живых', $text);
        $this->assertStringNotContainsString('#0', $text);
    }

    public function testPrestigeOnlyNoRewardsFootnote(): void
    {
        $text = $this->screenWith($this->rows(), 4)->payload(1106)['text'];

        $this->assertStringContainsString('наград за него нет', $text, 'Анти-абьюз: топ не должен обещать награду');
    }

    public function testTabsSwitchBothWays(): void
    {
        $active = $this->screenWith($this->rows(), 4)->payload(1106);
        $this->assertStringContainsString('leaderboardLegends', $active['reply_markup']);

        $legends = $this->screenWith($this->rows(), 4, $this->rows())->payload(1106, LeaderboardScreen::TAB_LEGENDS);
        $this->assertStringContainsString('👑 Легенды острова', $legends['text']);
        $this->assertStringContainsString('"leaderboard"', $legends['reply_markup']);
        $this->assertStringContainsString('👤 *Ты:* #3 из 611', $legends['text']);
    }

    public function testUnknownTabFallsBackToActive(): void
    {
        $text = $this->screenWith($this->rows(), 4)->payload(1106, 'мусор')['text'];

        $this->assertStringContainsString('🔥 Живые', $text);
    }

    public function testEmptyBoardDoesNotRenderBlank(): void
    {
        $text = $this->screenWith([], 0)->payload(1)['text'];

        $this->assertStringContainsString('Пока пусто', $text);
    }

    public function testMarkdownUnsafeNickCannotBreakMessage(): void
    {
        // Legacy-Markdown НЕ экранируется бэкслэшем → символы вырезаем.
        $this->assertSame('злой хакер', MarkdownSafe::name('*злой* _хакер_'));
        $this->assertSame('Выживший', MarkdownSafe::name('***'));
        $this->assertSame('База', MarkdownSafe::name('', 'База'));
        $this->assertStringNotContainsString('[', MarkdownSafe::name('a[b]c`d'));
    }

    public function testEveryTabRendersParsableMarkdownPairs(): void
    {
        foreach ([LeaderboardScreen::TAB_ACTIVE, LeaderboardScreen::TAB_LEGENDS] as $tab) {
            $text = $this->screenWith($this->rows(), 4, $this->rows())->payload(1106, $tab)['text'];
            $this->assertSame(
                0,
                substr_count($text, '*') % 2,
                "Вкладка {$tab}: непарная `*` → Telegram вернёт 400 и сообщение потеряется."
            );
        }
    }
}

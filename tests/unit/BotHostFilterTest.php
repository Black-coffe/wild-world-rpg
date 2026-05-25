<?php

use App\Filters\BotHostFilter;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\URI;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-052 — фильтр SEO-разведения сайта (wildworld.fun) и хоста бота (bot.wildworld.fun).
 *
 * @internal
 */
final class BotHostFilterTest extends CIUnitTestCase
{
    private function requestFor(string $url): IncomingRequest
    {
        $request = $this->createMock(IncomingRequest::class);
        $request->method('getUri')->willReturn(new URI($url));

        return $request;
    }

    public function testSiteHostIsUntouched(): void
    {
        $filter = new BotHostFilter();
        $this->assertNull($filter->before($this->requestFor('https://wildworld.fun/devblog')));
        $this->assertNull($filter->before($this->requestFor('https://wildworld.fun/')));
        $this->assertNull($filter->before($this->requestFor('https://testbot.wildworld.fun/wiki/biomes')));
    }

    public function testBotWebhookPassesThrough(): void
    {
        $filter = new BotHostFilter();
        $this->assertNull($filter->before($this->requestFor('https://bot.wildworld.fun/telegram/webhook')));
        $this->assertNull($filter->before($this->requestFor('https://bot.wildworld.fun/telegram')));
    }

    public function testBotRootServesNoindexStub(): void
    {
        $result = (new BotHostFilter())->before($this->requestFor('https://bot.wildworld.fun/'));

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertNotInstanceOf(RedirectResponse::class, $result);
        $this->assertStringContainsStringIgnoringCase('noindex', $result->getHeaderLine('X-Robots-Tag'));
        $this->assertStringContainsString('wildworld.fun', (string) $result->getBody());
    }

    public function testBotDeepPathRedirects301ToSite(): void
    {
        $result = (new BotHostFilter())->before($this->requestFor('https://bot.wildworld.fun/devblog'));

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame(301, $result->getStatusCode());
        $this->assertSame('https://wildworld.fun/devblog', $result->getHeaderLine('Location'));
    }

    public function testBotRedirectPreservesQueryString(): void
    {
        $result = (new BotHostFilter())->before($this->requestFor('https://bot.wildworld.fun/devblog?page=2'));

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame('https://wildworld.fun/devblog?page=2', $result->getHeaderLine('Location'));
    }
}

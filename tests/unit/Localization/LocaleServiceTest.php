<?php

declare(strict_types=1);

namespace Tests\Unit\Localization;

use App\Services\Localization\LocaleService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * W27 (ADR-082) — LocaleService::localeFor: валидная локаль персонажа с fallback на ru.
 *
 * @internal
 */
final class LocaleServiceTest extends CIUnitTestCase
{
    private LocaleService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new LocaleService();
    }

    public function testEnLocale(): void
    {
        $this->assertSame('en', $this->svc->localeFor(['locale' => 'en']));
    }

    public function testRuLocale(): void
    {
        $this->assertSame('ru', $this->svc->localeFor(['locale' => 'ru']));
    }

    public function testNullCharacterDefaultsRu(): void
    {
        $this->assertSame('ru', $this->svc->localeFor(null));
    }

    public function testMissingLocaleDefaultsRu(): void
    {
        $this->assertSame('ru', $this->svc->localeFor(['id' => 1]));
    }

    public function testUnsupportedLocaleFallsBackRu(): void
    {
        $this->assertSame('ru', $this->svc->localeFor(['locale' => 'fr']));
        $this->assertSame('ru', $this->svc->localeFor(['locale' => '']));
    }

    public function testCaseAndWhitespaceNormalised(): void
    {
        $this->assertSame('en', $this->svc->localeFor(['locale' => ' EN ']));
    }

    public function testSupportedListMatchesConfig(): void
    {
        // Anti-drift: SUPPORTED должен совпадать с Config\App::$supportedLocales.
        $this->assertSame(['ru', 'en'], LocaleService::SUPPORTED);
        $this->assertSame(['ru', 'en'], config('App')->supportedLocales);
    }
}

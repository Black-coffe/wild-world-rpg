<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\Commands\StartCommand;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Атрибуция интейка — нормализация payload `/start <src_*>` в acquisition_source.
 * Pure-хелпер, без БД/Telegram.
 *
 * @internal
 */
final class StartCommandAcquisitionSourceTest extends CIUnitTestCase
{
    public function testNullPayloadGivesNull(): void
    {
        $this->assertNull(StartCommand::extractAcquisitionSource(null));
    }

    public function testEmptyOrWhitespaceGivesNull(): void
    {
        $this->assertNull(StartCommand::extractAcquisitionSource(''));
        $this->assertNull(StartCommand::extractAcquisitionSource('   '));
    }

    public function testKeepsValidSrcTag(): void
    {
        $this->assertSame('src_site_stalker_cta', StartCommand::extractAcquisitionSource('src_site_stalker_cta'));
        $this->assertSame('src_habr_1042014', StartCommand::extractAcquisitionSource('src_habr_1042014'));
        $this->assertSame('src-pikabu-11371465', StartCommand::extractAcquisitionSource('src-pikabu-11371465'));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $this->assertSame('src_site_baza', StartCommand::extractAcquisitionSource('  src_site_baza  '));
    }

    public function testStripsUnsafeChars(): void
    {
        // Telegram и так ограничивает payload, но защищаемся от мусора/инъекций:
        // опасные символы (пробел/кавычка/`;`/`/`/`<>`) выскоблены, остаются только [a-zA-Z0-9_-].
        $this->assertSame('srcsiteDROP', StartCommand::extractAcquisitionSource("src site';DROP"));
        $this->assertSame('abc123', StartCommand::extractAcquisitionSource('abc/123<>'));
    }

    public function testCapsLengthAt191(): void
    {
        $long = str_repeat('a', 250);
        $out  = StartCommand::extractAcquisitionSource($long);
        $this->assertNotNull($out);
        $this->assertSame(191, mb_strlen($out));
    }

    public function testOnlyUnsafeCharsGivesNull(): void
    {
        $this->assertNull(StartCommand::extractAcquisitionSource('!@#$%^&*()'));
    }
}

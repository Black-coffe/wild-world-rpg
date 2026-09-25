<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Web;

use App\Services\Web\WebDelivery;
use CodeIgniter\Test\CIUnitTestCase;
use GuzzleHttp\Psr7\Stream;

/**
 * web-bridge-p1-19 — `WebDelivery::photoUrl()`: поток, чей `uri` — http(s)-адрес
 * (`Request::encodeFile(base_url(…))`), отдаёт этот адрес; пути к файлам ведут себя как прежде.
 * Сеть не открывается: `http`/`https` на время теста подменены пустой обёрткой потока.
 *
 * @internal
 */
final class WebDeliveryPhotoUrlTest extends CIUnitTestCase
{
    private const URL = 'https://example.test/uploads/x.png';

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->cleanup = [];
        foreach (['http', 'https'] as $proto) {
            if (in_array($proto, stream_get_wrappers(), true)) {
                @stream_wrapper_restore($proto);
            }
        }
        parent::tearDown();
    }

    public function testHttpStreamResourceReturnsItsUri(): void
    {
        stream_wrapper_unregister('https');
        stream_wrapper_register('https', FakeHttpStreamWrapper::class);

        $fh = fopen(self::URL, 'rb');
        $this->assertIsResource($fh);
        $this->assertSame(self::URL, stream_get_meta_data($fh)['uri']);

        $this->assertSame(self::URL, WebDelivery::photoUrl($fh));
        fclose($fh);
    }

    public function testPlainHttpStreamResourceReturnsItsUri(): void
    {
        stream_wrapper_unregister('http');
        stream_wrapper_register('http', FakeHttpStreamWrapper::class);

        $url = 'http://example.test/uploads/x.png';
        $fh  = fopen($url, 'rb');
        $this->assertIsResource($fh);

        $this->assertSame($url, WebDelivery::photoUrl($fh));
        fclose($fh);
    }

    public function testStreamInterfaceWithHttpUriReturnsIt(): void
    {
        $stream = new Stream(fopen('php://memory', 'r+b'), ['metadata' => ['uri' => self::URL]]);

        $this->assertSame(self::URL, WebDelivery::photoUrl($stream));
    }

    public function testHttpStringUnchanged(): void
    {
        $this->assertSame(self::URL, WebDelivery::photoUrl(self::URL));
    }

    public function testFileUnderPublicMapsToBaseUrl(): void
    {
        $name = 'wd_photo_test_' . bin2hex(random_bytes(4)) . '.png';
        $file = FCPATH . $name;
        file_put_contents($file, 'x');
        $this->cleanup[] = $file;

        helper('url');
        $this->assertSame(base_url($name), WebDelivery::photoUrl($file));

        $fh = fopen($file, 'rb');
        $this->assertSame(base_url($name), WebDelivery::photoUrl($fh));
        fclose($fh);
    }

    public function testFileOutsidePublicIsNull(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wdp');
        $this->assertIsString($file);
        $this->cleanup[] = $file;

        $this->assertNull(WebDelivery::photoUrl($file));

        $fh = fopen($file, 'rb');
        $this->assertNull(WebDelivery::photoUrl($fh));
        fclose($fh);
    }

    public function testNonHttpSchemesDoNotPassAsUrls(): void
    {
        foreach (['file:///etc/x.png', 'php://memory', 'data:image/png;base64,AAAA', '//example.test/x.png', 'ftp://example.test/x.png'] as $uri) {
            $this->assertNull(WebDelivery::photoUrl($uri), $uri);
            $stream = new Stream(fopen('php://memory', 'r+b'), ['metadata' => ['uri' => $uri]]);
            $this->assertNull(WebDelivery::photoUrl($stream), $uri);
        }

        $mem = fopen('php://memory', 'r+b');
        $this->assertNull(WebDelivery::photoUrl($mem));
        fclose($mem);
    }
}

/**
 * Пустая обёртка вместо `http(s)`: `fopen()` возвращает ресурс с `uri` = адрес, без сети.
 *
 * @internal
 */
final class FakeHttpStreamWrapper
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
    }
}

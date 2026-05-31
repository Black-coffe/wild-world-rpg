<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seo;

use App\Services\Seo\GoogleSearchConsoleService;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCache;
use Config\Seo as SeoConfig;
use Config\Services;
use RuntimeException;

/**
 * Шаг 6a SEO-пайплайна — offline-тесты GoogleSearchConsoleService.
 *
 * Покрывают код БЕЗ сети и без реального GSC: HTTP мокается (CURLRequest),
 * приватный ключ генерируется на лету (openssl_pkey_new) → проверяем, что
 * (а) JWT подписывается и обменивается на токен, строки Search Analytics
 * корректно маппятся; (б) при отсутствии ключа — понятная ошибка;
 * (в) HTTP-ошибка API пробрасывается с сообщением Google.
 *
 * @internal
 */
final class GoogleSearchConsoleServiceTest extends CIUnitTestCase
{
    private string $keyPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('cache', new MockCache());

        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($res === false) {
            $this->fail('openssl_pkey_new не создал тестовый ключ (нет ext-openssl?)');
        }
        $privateKey = '';
        openssl_pkey_export($res, $privateKey);

        $this->keyPath = WRITEPATH . 'gsc-test-key-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($this->keyPath, $this->j([
            'type'         => 'service_account',
            'client_email' => 'gsc-test@example.iam.gserviceaccount.com',
            'private_key'  => $privateKey,
        ]));
    }

    protected function tearDown(): void
    {
        if ($this->keyPath !== '' && is_file($this->keyPath)) {
            unlink($this->keyPath);
        }
        parent::tearDown();
    }

    public function testSearchAnalyticsMapsRows(): void
    {
        $svc  = new GoogleSearchConsoleService($this->config($this->keyPath), $this->httpReturning([
            'rows' => [
                ['keys' => ['крафт гайд'], 'clicks' => 5, 'impressions' => 120, 'ctr' => 0.0416, 'position' => 7.3],
                ['keys' => ['wild world'], 'clicks' => 0, 'impressions' => 40, 'ctr' => 0.0, 'position' => 15.0],
            ],
        ]));
        $rows = $svc->searchAnalytics('2026-05-01', '2026-05-28', ['query'], 100);

        $this->assertCount(2, $rows);
        $this->assertSame('крафт гайд', $rows[0]['keys'][0]);
        $this->assertSame(5.0, $rows[0]['clicks']);
        $this->assertSame(120.0, $rows[0]['impressions']);
        $this->assertEqualsWithDelta(0.0416, $rows[0]['ctr'], 0.0001);
        $this->assertSame(0.0, $rows[1]['clicks']);
        $this->assertSame(40.0, $rows[1]['impressions']);
    }

    public function testEmptyResultReturnsEmptyArray(): void
    {
        $svc  = new GoogleSearchConsoleService($this->config($this->keyPath), $this->httpReturning(['responseAggregationType' => 'byProperty']));
        $rows = $svc->searchAnalytics('2026-05-01', '2026-05-28', ['query'], 10);

        $this->assertSame([], $rows);
    }

    public function testThrowsWhenKeyFileMissing(): void
    {
        $svc = new GoogleSearchConsoleService($this->config(WRITEPATH . 'no-such-key.json'), $this->createMock(CURLRequest::class));

        $this->expectException(RuntimeException::class);
        $svc->searchAnalytics('2026-05-01', '2026-05-28', ['query'], 10);
    }

    public function testThrowsOnApiErrorWithGoogleMessage(): void
    {
        $svc = new GoogleSearchConsoleService($this->config($this->keyPath), $this->httpReturning(
            ['error' => ['message' => 'User does not have sufficient permission']],
            403
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sufficient permission/');
        $svc->searchAnalytics('2026-05-01', '2026-05-28', ['query'], 10);
    }

    // ------------------------------------------------------------------ helpers

    private function config(string $path): SeoConfig
    {
        $cfg                     = new SeoConfig();
        $cfg->gscCredentialsPath = $path;
        $cfg->gscSiteUrl         = 'https://example.test/';

        return $cfg;
    }

    /**
     * Мок CURLRequest: на token-URL отдаёт access_token, на остальное — заданное тело API.
     *
     * @param array<string,mixed> $apiBody
     */
    private function httpReturning(array $apiBody, int $apiStatus = 200): CURLRequest
    {
        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willReturnCallback(function (string $method, string $url, array $options = []) use ($apiBody, $apiStatus): ResponseInterface {
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return $this->response($this->j(['access_token' => 'tok-test', 'expires_in' => 3600]));
            }

            return $this->response($this->j($apiBody), $apiStatus);
        });

        return $http;
    }

    private function response(string $body, int $status = 200): ResponseInterface
    {
        $resp = $this->createMock(ResponseInterface::class);
        $resp->method('getBody')->willReturn($body);
        $resp->method('getStatusCode')->willReturn($status);

        return $resp;
    }

    /** @param array<string,mixed> $data */
    private function j(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR) ?: '{}';
    }
}

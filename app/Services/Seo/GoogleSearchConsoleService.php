<?php

declare(strict_types=1);

namespace App\Services\Seo;

use CodeIgniter\HTTP\CURLRequest;
use Config\Seo as SeoConfig;
use Config\Services;
use RuntimeException;

/**
 * Доступ к Google Search Console API через service-account (Шаг 6a SEO-пайплайна).
 *
 * Аутентификация без google/apiclient: подписываем JWT (RS256, openssl_sign) приватным
 * ключом из JSON service-account'а → меняем на OAuth2 access-token у oauth2.googleapis.com
 * → дёргаем Search Console API. Токен кэшируется (~55 мин) в CI4-кэше.
 *
 * Property — URL-префикс `https://wildworld.fun/` (Config\Seo::$gscSiteUrl).
 * Composer-зависимостей не добавляет: openssl (ext) + CURLRequest (CI4).
 */
class GoogleSearchConsoleService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE     = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const API_BASE  = 'https://searchconsole.googleapis.com/webmasters/v3';
    private const CACHE_KEY = 'seo_gsc_access_token';
    private const TOKEN_TTL = 3300; // 55 мин (токен Google живёт 3600с)

    private SeoConfig $config;
    private CURLRequest $http;

    public function __construct(?SeoConfig $config = null, ?CURLRequest $http = null)
    {
        $this->config = $config ?? config(SeoConfig::class);
        $this->http   = $http ?? Services::curlrequest(['timeout' => 30], null, null, false);
    }

    /**
     * Search Analytics: строки по заданным измерениям за период.
     *
     * @param list<string> $dimensions напр. ['query'] или ['page']
     *
     * @return list<array{keys:list<string>,clicks:float,impressions:float,ctr:float,position:float}>
     */
    public function searchAnalytics(string $startDate, string $endDate, array $dimensions, int $rowLimit = 1000): array
    {
        $resp = $this->apiPost(
            '/sites/' . rawurlencode($this->config->gscSiteUrl) . '/searchAnalytics/query',
            [
                'startDate'  => $startDate,
                'endDate'    => $endDate,
                'dimensions' => $dimensions,
                'rowLimit'   => $rowLimit,
            ]
        );

        $rows = [];
        $raw  = $resp['rows'] ?? null;
        if (! is_array($raw)) {
            return $rows;
        }

        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $keys = [];
            if (isset($row['keys']) && is_array($row['keys'])) {
                foreach ($row['keys'] as $k) {
                    $keys[] = is_scalar($k) ? (string) $k : '';
                }
            }
            $rows[] = [
                'keys'        => $keys,
                'clicks'      => $this->floatOf($row['clicks'] ?? 0),
                'impressions' => $this->floatOf($row['impressions'] ?? 0),
                'ctr'         => $this->floatOf($row['ctr'] ?? 0),
                'position'    => $this->floatOf($row['position'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * URL Inspection — статус индексации одного URL (требует «Полный» доступ в GSC).
     *
     * @return array<array-key,mixed> поле inspectionResult из ответа (пусто при ошибке)
     */
    public function inspectUrl(string $url): array
    {
        $token = $this->accessToken();
        $resp  = $this->http->request('POST', 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'inspectionUrl' => $url,
                'siteUrl'       => $this->config->gscSiteUrl,
            ], JSON_THROW_ON_ERROR),
            'http_errors' => false,
        ]);

        $decoded = json_decode((string) $resp->getBody(), true);
        if (is_array($decoded) && isset($decoded['inspectionResult']) && is_array($decoded['inspectionResult'])) {
            return $decoded['inspectionResult'];
        }

        return [];
    }

    /**
     * POST к Search Console API с bearer-токеном.
     *
     * @param array<string,mixed> $body
     *
     * @return array<array-key,mixed>
     */
    private function apiPost(string $path, array $body): array
    {
        $token = $this->accessToken();
        $resp  = $this->http->request('POST', self::API_BASE . $path, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'        => json_encode($body, JSON_THROW_ON_ERROR),
            'http_errors' => false,
        ]);

        $status  = $resp->getStatusCode();
        $decoded = json_decode((string) $resp->getBody(), true);
        $data    = is_array($decoded) ? $decoded : [];

        if ($status >= 400) {
            $error = $data['error'] ?? null;
            $msg   = is_array($error) && isset($error['message']) && is_string($error['message'])
                ? $error['message']
                : 'HTTP ' . $status;

            throw new RuntimeException('GSC API error: ' . $msg);
        }

        return $data;
    }

    /** Access-token (из кэша или свежий через JWT-обмен). */
    private function accessToken(): string
    {
        $cache  = Services::cache();
        $cached = $cache->get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->fetchToken();
        $cache->save(self::CACHE_KEY, $token, self::TOKEN_TTL);

        return $token;
    }

    /** JWT-assertion → access-token (grant urn:ietf:params:oauth:grant-type:jwt-bearer). */
    private function fetchToken(): string
    {
        [$clientEmail, $privateKey] = $this->credentials();

        $now    = time();
        $header = $this->b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->b64((string) json_encode([
            'iss'   => $clientEmail,
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ], JSON_THROW_ON_ERROR));

        $signingInput = $header . '.' . $claims;
        $signature    = '';
        if (openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256) !== true) {
            throw new RuntimeException('GSC: не удалось подписать JWT приватным ключом service-account.');
        }
        $assertion = $signingInput . '.' . $this->b64($signature);

        $resp = $this->http->request('POST', self::TOKEN_URL, [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ],
            'http_errors' => false,
        ]);

        $decoded = json_decode((string) $resp->getBody(), true);
        $token   = is_array($decoded) && isset($decoded['access_token']) && is_string($decoded['access_token'])
            ? $decoded['access_token']
            : '';

        if ($token === '') {
            $err = is_array($decoded) && isset($decoded['error_description']) && is_string($decoded['error_description'])
                ? $decoded['error_description']
                : 'нет access_token в ответе';

            throw new RuntimeException('GSC: обмен JWT на токен не удался — ' . $err);
        }

        return $token;
    }

    /**
     * Читает JSON-ключ service-account'а → [client_email, private_key].
     *
     * @return array{0:string,1:string}
     */
    private function credentials(): array
    {
        $path = $this->config->gscCredentialsPath;
        if (! is_file($path)) {
            throw new RuntimeException('GSC: не найден ключ service-account по пути ' . $path . ' (положи JSON в writable/secrets/).');
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('GSC: не удалось прочитать ключ ' . $path);
        }

        $data        = json_decode($json, true);
        $clientEmail = is_array($data) && isset($data['client_email']) && is_string($data['client_email']) ? $data['client_email'] : '';
        $privateKey  = is_array($data) && isset($data['private_key']) && is_string($data['private_key']) ? $data['private_key'] : '';

        if ($clientEmail === '' || $privateKey === '') {
            throw new RuntimeException('GSC: в ключе нет client_email/private_key — это точно JSON service-account?');
        }

        return [$clientEmail, $privateKey];
    }

    private function floatOf(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    /** base64url без паддинга. */
    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

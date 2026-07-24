<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Единственный канонический вид URL: без завершающего слеша.
 *
 * SEO-аудит 2026-07-24: `/about` и `/about/` (как и десятки статей) отдавали **оба HTTP 200**.
 * `rel=canonical` при этом уже стоял и указывал верно — то есть склейка настроена, но обе
 * версии остаются краулябельными. Выгрузка Search Console за 3 месяца показывает обе в
 * индексе с ненулевыми показами: `/wild-world-masshtabnaya-…` со слешем 106 показов и без
 * слеша ещё 29, `/tekstovye-igry-proshloe-…` — 143 и 52.
 *
 * Canonical — это рекомендация поисковику, редирект — правило. Разница существенна при нашем
 * краул-бюджете: `YandexBot` обходит весь сайт ~89 раз за 7 дней, и половину этого бюджета
 * не должны съедать дубли.
 *
 * Осознанно НЕ трогаем:
 *  - корень `/` (там слеш и есть канонический вид);
 *  - не-GET запросы (301 на POST потерял бы тело);
 *  - `/telegram/*` (вебхук Telegram — ему редиректы не нужны и вредны).
 */
class TrailingSlashRedirectFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return null;
        }

        // Берём СЫРОЙ путь: CI4 нормализует URI и слеш до фильтра уже не виден.
        $rawUri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '';
        if ($rawUri === '') {
            return null;
        }

        $parsedPath = parse_url($rawUri, PHP_URL_PATH);
        $path       = is_string($parsedPath) ? $parsedPath : '';

        // Корень и пути без слеша на конце — уже канонические.
        if ($path === '' || $path === '/' || ! str_ends_with($path, '/')) {
            return null;
        }

        if (str_starts_with($path, '/telegram/')) {
            return null;
        }

        $query  = parse_url($rawUri, PHP_URL_QUERY);
        $target = rtrim($path, '/') . (is_string($query) && $query !== '' ? '?' . $query : '');

        // rtrim мог съесть путь целиком («///») — тогда цель это корень, редирект бессмыслен.
        if ($target === '' || $target === '?') {
            return null;
        }

        return redirect()->to($target, 301);
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        return null;
    }
}

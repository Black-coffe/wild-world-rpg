<?php

namespace App\Controllers;

use App\Models\SiteRedirectModel;
use CodeIgniter\HTTP\ResponseInterface;

class Errors extends BaseController
{
    public function show404()
    {
        // Установка кода ответа
        $this->response->setHeader('HTTP/1.1 404 Not Found');

        // Загрузка представления 404
        return view('errors/404');
    }

    /**
     * ADR-052 — обработчик нераспознанных маршрутов (set404Override).
     * Сначала ищем 301/302-редирект в site_redirects (SEO-преемственность при
     * переезде с WordPress), иначе отдаём 404.
     */
    /**
     * Хардкод старых кириллических WP-URL → канон (не зависит от засева site_redirects;
     * percent-encoded кириллица проблемна в CI4-URI и таблице — гарантируем явно).
     */
    private const HARD_REDIRECTS = [
        '/главная'  => '/',
        '/блог'     => '/devblog',
        '/контакты' => '/contacts',
    ];

    public function notFound(?string $message = null): ResponseInterface
    {
        // Сырой REQUEST_URI (не CI4-getPath, который теряет percent-encoded кириллицу на nginx).
        $rawUri   = is_string($_SERVER['REQUEST_URI'] ?? null) ? (string) $_SERVER['REQUEST_URI'] : (string) $this->request->getUri()->getPath();
        $pathOnly = parse_url($rawUri, PHP_URL_PATH);
        $path     = $this->normalizePath(is_string($pathOnly) ? $pathOnly : '');

        // 1) Хардкод-редиректы кириллических URL.
        if (isset(self::HARD_REDIRECTS[$path])) {
            return redirect()->to(self::HARD_REDIRECTS[$path], 301);
        }

        // 2) Таблица site_redirects (остальные перемещённые URL).
        $model    = new SiteRedirectModel();
        $redirect = $model->matchPath($path);
        if ($redirect !== null) {
            $model->recordHit($redirect['id']);
            $code = $redirect['code'] === 302 ? 302 : 301;

            return redirect()->to($redirect['to_path'], $code);
        }

        return $this->response
            ->setStatusCode(404)
            ->setBody(view('site/404', [
                'meta' => [
                    'title'     => 'Страница не найдена — Wild World',
                    'robots'    => 'noindex,follow',
                    'canonical' => rtrim(base_url(), '/'),
                ],
            ]));
    }

    /**
     * Нормализация пути для ключа site_redirects: ведущий «/», без хвостового «/»,
     * URL-декодирован (uriProtocol=REQUEST_URI не декодирует кириллицу).
     */
    private function normalizePath(string $raw): string
    {
        $decoded = rawurldecode($raw);
        $trimmed = trim($decoded, '/');

        return $trimmed === '' ? '/' : '/' . $trimmed;
    }
}

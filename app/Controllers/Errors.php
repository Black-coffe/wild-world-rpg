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
     * ADR-050 — обработчик нераспознанных маршрутов (set404Override).
     * Сначала ищем 301/302-редирект в site_redirects (SEO-преемственность при
     * переезде с WordPress), иначе отдаём 404.
     */
    public function notFound(?string $message = null): ResponseInterface
    {
        $path = $this->normalizePath((string) $this->request->getUri()->getPath());

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

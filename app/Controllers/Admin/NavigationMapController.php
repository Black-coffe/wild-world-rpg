<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Navigation\NavigationMapService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Визуализация дерева навигации игры (кнопки / экраны / переходы) для админки.
 * Index — HTML-страница, data — JSON-граф для клиентского рендера + live-refresh.
 *
 * Граф строится детерминированно из кода {@see NavigationMapService} (read-only):
 * всегда актуален, не дрейфит. Зеркалит паттерн {@see CraftTreeController}.
 */
final class NavigationMapController extends BaseAdminController
{
    public function index(): string
    {
        return view('admin/navigation_map');
    }

    public function data(): ResponseInterface
    {
        $payload = (new NavigationMapService())->buildGraph();

        return $this->response
            ->setHeader('Cache-Control', 'no-store, max-age=0')
            ->setJSON($payload);
    }
}

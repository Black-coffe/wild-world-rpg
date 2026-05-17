<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\CraftTree\CraftTreeService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Визуализация дерева крафта и строительства для админки.
 * Index — HTML-страница, data — JSON для клиентского рендера + auto-refresh.
 * Никаких write-операций (read-only viewer).
 */
final class CraftTreeController extends BaseAdminController
{
    public function index(): string
    {
        return view('admin/craft_tree');
    }

    public function data(): ResponseInterface
    {
        $service = new CraftTreeService();
        $payload = $service->buildTree();

        return $this->response
            ->setHeader('Cache-Control', 'no-store, max-age=0')
            ->setJSON($payload);
    }
}

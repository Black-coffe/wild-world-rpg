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

    /**
     * S30 — CSV-экспорт craft-tree (рецепты + постройки) для анализа экономики
     * в таблице. UTF-8 BOM → корректная кириллица в Excel.
     */
    public function export(): ResponseInterface
    {
        $service = new CraftTreeService();

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return $this->response->setStatusCode(500)->setBody('CSV stream error');
        }
        fputcsv($fh, $service->csvHeader());
        foreach ($service->buildCsvRows() as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $filename = 'craft-tree-' . date('Y-m-d') . '.csv';

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Cache-Control', 'no-store, max-age=0')
            ->setBody("\xEF\xBB\xBF" . ($csv === false ? '' : $csv));
    }
}

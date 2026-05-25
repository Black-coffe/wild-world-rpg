<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Economy\CraftingEconomyService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * V21 (ADR-053) — admin-дашборд экономики крафта `/admin/crafting-economy`.
 * index — HTML, data — JSON для ApexCharts + auto-refresh, export — CSV (S30).
 * Read-only (никаких write-операций; баланс правится через GameSettings).
 */
final class CraftingEconomyController extends BaseAdminController
{
    public function index(): string
    {
        return view('admin/crafting_economy');
    }

    public function data(): ResponseInterface
    {
        $payload = (new CraftingEconomyService())->dashboard();

        return $this->response
            ->setHeader('Cache-Control', 'no-store, max-age=0')
            ->setJSON($payload);
    }

    /**
     * CSV-экспорт ключевых метрик экономики (S30-паттерн). UTF-8 BOM → кириллица в Excel.
     */
    public function export(): ResponseInterface
    {
        $service = new CraftingEconomyService();

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

        $filename = 'crafting-economy-' . date('Y-m-d') . '.csv';

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Cache-Control', 'no-store, max-age=0')
            ->setBody("\xEF\xBB\xBF" . ($csv === false ? '' : $csv));
    }
}

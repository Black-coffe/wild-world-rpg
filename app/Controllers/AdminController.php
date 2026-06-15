<?php

namespace App\Controllers;

use App\Services\Admin\DashboardAnalyticsService;
use App\Services\Admin\DashboardCsvExporter;
use CodeIgniter\HTTP\ResponseInterface;

class AdminController extends BaseController
{

    public function index()
    {
        return view(
            'admin/dashboard',
            [
                'title' => 'Панель управления',
                'd'     => (new DashboardAnalyticsService())->dashboard($this->trendDays()),
            ]
        );
    }

    /**
     * CSV-экспорт всех данных дашборда (учитывает выбранный период ?period).
     */
    public function exportCsv(): ResponseInterface
    {
        $data = (new DashboardAnalyticsService())->dashboard($this->trendDays());
        $csv  = (new DashboardCsvExporter())->export($data);

        $filename = 'dashboard-' . date('Y-m-d-His') . '.csv';

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($csv);
    }

    /** Окно тренда из ?period (валидируется в сервисе); дефолт 30. */
    private function trendDays(): int
    {
        $period = $this->request->getGet('period');

        return is_numeric($period) ? (int) $period : 30;
    }


}

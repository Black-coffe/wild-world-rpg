<?php

namespace App\Controllers;

use App\Services\Admin\DashboardAnalyticsService;
use App\Services\Admin\DashboardCsvExporter;
use App\Services\Admin\TrendRange;
use CodeIgniter\HTTP\ResponseInterface;

class AdminController extends BaseController
{

    public function index()
    {
        return view(
            'admin/dashboard',
            [
                'title' => 'Панель управления',
                'd'     => (new DashboardAnalyticsService())->dashboard($this->range()),
            ]
        );
    }

    /**
     * CSV-экспорт всех данных дашборда (учитывает выбранное окно: пресет ?period
     * или произвольный диапазон ?from&?to).
     */
    public function exportCsv(): ResponseInterface
    {
        $data = (new DashboardAnalyticsService())->dashboard($this->range());
        $csv  = (new DashboardCsvExporter())->export($data);

        $filename = 'dashboard-' . date('Y-m-d-His') . '.csv';

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($csv);
    }

    /**
     * Окно тренда из запроса: произвольный диапазон ?from&?to (приоритет) ИЛИ
     * пресет ?period (дней). Невалидное → пресет 30. Валидация в TrendRange.
     */
    private function range(): TrendRange
    {
        $from = $this->request->getGet('from');
        $to   = $this->request->getGet('to');
        $custom = TrendRange::fromDates(
            is_string($from) ? $from : null,
            is_string($to) ? $to : null
        );
        if ($custom !== null) {
            return $custom;
        }

        $period = $this->request->getGet('period');

        return TrendRange::preset(is_numeric($period) ? (int) $period : 30);
    }


}

<?php

namespace App\Controllers;

use App\Services\Admin\DashboardAnalyticsService;

class AdminController extends BaseController
{

    public function index()
    {
        $period = $this->request->getGet('period');
        $days   = is_numeric($period) ? (int) $period : 30;

        return view(
            'admin/dashboard',
            [
                'title' => 'Панель управления',
                'd'     => (new DashboardAnalyticsService())->dashboard($days),
            ]
        );
    }


}

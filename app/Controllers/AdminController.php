<?php

namespace App\Controllers;

use App\Services\Admin\DashboardAnalyticsService;

class AdminController extends BaseController
{

    public function index()
    {
        return view(
            'admin/dashboard',
            [
                'title' => 'Панель управления',
                'd'     => (new DashboardAnalyticsService())->dashboard(),
            ]
        );
    }


}

<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Admin\FunnelAnalyticsService;

/**
 * E1 (ROADMAP-100-SESSIONS) — KPI-дашборд воронки новичка `/admin/funnel`.
 * Read-only, server-rendered (без JS-зависимостей): воронка, когорты,
 * активность по движению, аномалии, срез квестов ADR-088.
 */
final class FunnelController extends BaseAdminController
{
    public function index(): string
    {
        return view('admin/funnel', ['d' => (new FunnelAnalyticsService())->dashboard()]);
    }
}

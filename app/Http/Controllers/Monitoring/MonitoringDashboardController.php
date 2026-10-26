<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\MonitoringRangeRequest;
use App\Services\MonitoringReadService;
use Inertia\Inertia;
use Inertia\Response;

class MonitoringDashboardController extends Controller
{
    public function admin(MonitoringRangeRequest $request, MonitoringReadService $service): Response
    {
        return Inertia::render('Admin/Monitoring/Dashboard', $service->dashboard($request));
    }

    public function caregiver(MonitoringRangeRequest $request, MonitoringReadService $service): Response
    {
        return Inertia::render('Caregiver/Monitoring/Dashboard', $service->dashboard($request));
    }
}

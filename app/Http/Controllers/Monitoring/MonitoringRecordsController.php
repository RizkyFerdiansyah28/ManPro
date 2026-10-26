<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\MonitoringRecordIndexRequest;
use App\Services\MonitoringReadService;
use Inertia\Inertia;
use Inertia\Response;

class MonitoringRecordsController extends Controller
{
    public function __invoke(MonitoringRecordIndexRequest $request, MonitoringReadService $service): Response
    {
        return Inertia::render('Monitoring/Records', $service->records($request));
    }
}

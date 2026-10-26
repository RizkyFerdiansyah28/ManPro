<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\OpenIncidentIndexRequest;
use App\Services\MonitoringReadService;
use Inertia\Inertia;
use Inertia\Response;

class OpenIncidentController extends Controller
{
    public function __invoke(OpenIncidentIndexRequest $request, MonitoringReadService $service): Response
    {
        return Inertia::render('Monitoring/OpenIncidents', $service->openIncidents($request));
    }
}

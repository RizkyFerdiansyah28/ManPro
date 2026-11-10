<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\IncidentPageRequest;
use App\Services\IncidentReadService;
use Inertia\Inertia;

class IncidentController extends Controller
{
    public function index(IncidentPageRequest $request, IncidentReadService $service)
    {
        return Inertia::render('Monitoring/Incidents/Index', $service->index($request));
    }

    public function show(IncidentPageRequest $request, string $incident, IncidentReadService $service)
    {
        return Inertia::render('Monitoring/Incidents/Show', $service->show($request, $incident));
    }

    public function residentOptions(IncidentPageRequest $request, string $incident, IncidentReadService $service)
    {
        return response()->json($service->residentOptions($request, $incident))->header('Cache-Control', 'no-store, private');
    }
}

<?php

namespace App\Http\Controllers\Family;

use App\Http\Controllers\Controller;
use App\Http\Requests\Family\FamilyPortalRequest;
use App\Services\FamilyPortalReadService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(FamilyPortalRequest $request, FamilyPortalReadService $service): Response
    {
        return Inertia::render('Family/Dashboard', $service->dashboard($request));
    }
}

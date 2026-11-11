<?php

namespace App\Http\Controllers\Family;

use App\Http\Controllers\Controller;
use App\Http\Requests\Family\FamilyPortalRequest;
use App\Services\FamilyPortalReadService;
use Inertia\Inertia;
use Inertia\Response;

class ResidentController extends Controller
{
    public function index(FamilyPortalRequest $request, FamilyPortalReadService $service): Response
    {
        return Inertia::render('Family/Residents/Index', $service->index($request));
    }

    public function show(FamilyPortalRequest $request, string $elderly, FamilyPortalReadService $service): Response
    {
        return Inertia::render('Family/Residents/Show', $service->show($request, $elderly));
    }
}

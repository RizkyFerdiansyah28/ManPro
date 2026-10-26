<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FallDetectionRequest;
use App\Http\Requests\Api\V1\GaitObservationRequest;
use App\Services\AiIngestionService;
use App\Support\AiGateway\EventPayload;
use Illuminate\Http\JsonResponse;

class AiIngestionController extends Controller
{
    public function fall(FallDetectionRequest $request, AiIngestionService $service): JsonResponse
    {
        $result = $service->ingest(new EventPayload($request->validated()), $request->attributes->get('ai_source'));

        return response()->json($result, $result['duplicate'] ? 200 : 201);
    }

    public function gait(GaitObservationRequest $request, AiIngestionService $service): JsonResponse
    {
        $result = $service->ingest(new EventPayload($request->validated()), $request->attributes->get('ai_source'));

        return response()->json($result, $result['duplicate'] ? 200 : 201);
    }
}

<?php

namespace App\Http\Controllers\Monitoring;

use App\Exceptions\IncidentConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\IncidentActionRequest;
use App\Services\IncidentService;
use Illuminate\Validation\ValidationException;

class IncidentActionController extends Controller
{
    public function __invoke(IncidentActionRequest $request, string $incident, string $action, IncidentService $service)
    {
        try {
            $result = $service->act($request->user(), $incident, $action, $request->validated());
        } catch (IncidentConflictException $exception) {
            return $request->expectsJson()
                ? response()->json(['message' => $exception->getMessage()], 409)->header('Cache-Control', 'no-store, private')
                : to_route('monitoring.incidents.show', $incident, 303)->withErrors(['incident' => $exception->getMessage()]);
        } catch (ValidationException $exception) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Data tindakan tidak valid.', 'errors' => $exception->errors()], 422)
                : to_route('monitoring.incidents.show', $incident, 303)->withErrors($exception->errors());
        }

        return $request->expectsJson()
            ? response()->json(['duplicate' => $result['duplicate'], 'detail_url' => route('monitoring.incidents.show', $incident, absolute: false)])
                ->header('Cache-Control', 'no-store, private')
            : to_route('monitoring.incidents.show', $incident, 303)
                ->with('status', $result['duplicate'] ? 'Tindakan ini sudah tercatat. Detail terkini ditampilkan.' : 'Tindakan insiden berhasil dicatat.');
    }
}

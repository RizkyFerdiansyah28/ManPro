<?php

namespace App\Http\Controllers;

use App\Http\Requests\Notifications\NotificationRequest;
use App\Services\NotificationReadService;
use App\Services\NotificationService;
use Inertia\Inertia;

class NotificationController extends Controller
{
    public function index(NotificationRequest $request, NotificationReadService $service)
    {
        return Inertia::render('Notifications/Index', $service->index($request));
    }

    public function summary(NotificationRequest $request, NotificationReadService $service)
    {
        return response()->json($service->summary($request->user()))->header('Cache-Control', 'no-store, private');
    }

    public function read(NotificationRequest $request, string $notification, NotificationService $service)
    {
        return $this->result($request, $service->read($request->user(), $notification));
    }

    public function readAll(NotificationRequest $request, NotificationService $service)
    {
        return $this->result($request, $service->read($request->user()));
    }

    private function result(NotificationRequest $request, int $changed)
    {
        return $request->expectsJson()
            ? response()->json(['changed' => $changed])->header('Cache-Control', 'no-store, private')
            : to_route('notifications.index', array_intersect_key($request->validated(), array_flip(['state', 'per_page', 'page'])), 303)
                ->with('status', 'Status baca notifikasi telah disimpan.');
    }
}

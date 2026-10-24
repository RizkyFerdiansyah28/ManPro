<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cameras\CameraIndexRequest;
use App\Http\Requests\Cameras\CameraStatusRequest;
use App\Http\Requests\Cameras\StoreCameraRequest;
use App\Http\Requests\Cameras\UpdateCameraRequest;
use App\Http\Resources\CameraResource;
use App\Models\Camera;
use App\Models\Room;
use App\Services\CameraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CameraController extends Controller
{
    public function index(CameraIndexRequest $request): Response
    {
        $filters = $request->safe()->only(['search', 'status', 'room_id']);
        $query = Camera::with('room.serviceClass:id,code,name,is_active')->withCount('aiDetections');
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn ($query) => $query->where('name', 'ilike', $pattern)
                ->orWhereHas('room', fn ($room) => $room->where('code', 'ilike', $pattern)->orWhere('name', 'ilike', $pattern)));
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('is_active', $status === 'active');
        }
        if ($roomId = $filters['room_id'] ?? null) {
            $query->where('room_id', $roomId);
        }
        $cameras = $query->orderBy('name')->orderBy('id')->paginate(15)->withQueryString()
            ->through(fn ($camera) => (new CameraResource($camera))->resolve($request));

        return Inertia::render('Admin/Cameras/Index', compact('cameras', 'filters'));
    }

    public function create(): Response
    {
        Gate::authorize('create', Camera::class);

        return Inertia::render('Admin/Cameras/Create');
    }

    public function store(StoreCameraRequest $request, CameraService $service): RedirectResponse
    {
        $camera = $service->create($request->user(), $request->validated());

        return redirect()->route('admin.cameras.show', $camera)->with('status', 'Kamera didaftarkan dalam status nonaktif. Aktivasi dilakukan terpisah; koneksi belum diuji.');
    }

    public function show(Request $request, Camera $camera): Response
    {
        Gate::authorize('view', $camera);
        $camera->load('room.serviceClass:id,code,name,is_active')->loadCount('aiDetections');

        return Inertia::render('Admin/Cameras/Show', ['camera' => (new CameraResource($camera))->resolve($request)]);
    }

    public function edit(Request $request, Camera $camera): Response
    {
        Gate::authorize('update', $camera);
        $camera->load('room.serviceClass:id,code,name,is_active')->loadCount('aiDetections');

        return Inertia::render('Admin/Cameras/Edit', ['camera' => (new CameraResource($camera))->resolve($request)]);
    }

    public function update(UpdateCameraRequest $request, Camera $camera, CameraService $service): RedirectResponse
    {
        $service->update($request->user(), $camera, $request->validated());

        return redirect()->route('admin.cameras.show', $camera)->with('status', 'Konfigurasi kamera diperbarui. Riwayat tetap tersimpan; koneksi tidak diuji.');
    }

    public function activate(CameraStatusRequest $request, Camera $camera, CameraService $service): RedirectResponse
    {
        $changed = $service->setActive($request->user(), $camera, $request->validated('version'), true);

        return redirect()->route('admin.cameras.show', $camera)->with('status', $changed ? 'Status administrasi kamera diaktifkan. Ini bukan hasil uji konektivitas.' : 'Kamera sudah aktif; tidak ada perubahan.');
    }

    public function deactivate(CameraStatusRequest $request, Camera $camera, CameraService $service): RedirectResponse
    {
        $changed = $service->setActive($request->user(), $camera, $request->validated('version'), false);

        return redirect()->route('admin.cameras.show', $camera)->with('status', $changed ? 'Status administrasi kamera dinonaktifkan. Asosiasi kamar dan riwayat dipertahankan.' : 'Kamera sudah nonaktif; tidak ada perubahan.');
    }

    public function roomOptions(CameraIndexRequest $request): JsonResponse
    {
        // Selector terpisah, paginated; tidak mengirim sumber kamera atau data penghuni.
        $query = Room::where('is_active', true)->whereDoesntHave('camera')->whereHas('serviceClass', fn ($query) => $query->where('is_active', true))
            ->with('serviceClass:id,code,name');
        $search = trim($request->validated('search') ?? '');
        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn ($query) => $query->where('code', 'ilike', $pattern)->orWhere('name', 'ilike', $pattern));
        }
        $rooms = $query->orderBy('code')->orderBy('id')->paginate(15)->withQueryString()->through(fn ($room) => [
            ...$room->only(['id', 'code', 'name']), 'class_name' => $room->serviceClass->name]);

        return response()->json($rooms);
    }
}

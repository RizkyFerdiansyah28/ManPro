<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Facilities\FacilityStatusRequest;
use App\Http\Requests\Facilities\RoomIndexRequest;
use App\Http\Requests\Facilities\StoreRoomRequest;
use App\Http\Requests\Facilities\UpdateRoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Models\ServiceClass;
use App\Services\RoomService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
    public function index(RoomIndexRequest $request): Response
    {
        $filters = $request->safe()->only(['search', 'status', 'service_class_id']);
        $query = Room::query()->with('serviceClass:id,code,name,room_type,is_active')->withCount(['activeStays', 'stays', 'camera']);
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn ($query) => $query->where('code', 'ilike', $pattern)->orWhere('name', 'ilike', $pattern));
        }
        if ($classId = $filters['service_class_id'] ?? null) {
            $query->where('service_class_id', $classId);
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('is_active', $status === 'active');
        }
        $rooms = $query->orderBy('code')->orderBy('id')->paginate(15)->withQueryString()
            ->through(fn (Room $room) => (new RoomResource($room))->resolve($request));

        return Inertia::render('Admin/Rooms/Index', ['rooms' => $rooms, 'filters' => $filters, 'classes' => $this->options()]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Room::class);

        return Inertia::render('Admin/Rooms/Create', ['classes' => $this->options(true)]);
    }

    public function store(StoreRoomRequest $request, RoomService $service): RedirectResponse
    {
        $room = $service->create($request->user(), $request->validated());

        return redirect()->route('admin.rooms.show', $room)->with('status', 'Kamar berhasil dibuat dalam status aktif.');
    }

    public function show(Request $request, Room $room): Response
    {
        Gate::authorize('view', $room);
        $room->load('serviceClass:id,code,name,room_type,is_active')->loadCount(['activeStays', 'stays', 'camera']);

        return Inertia::render('Admin/Rooms/Show', ['room' => (new RoomResource($room))->resolve($request)]);
    }

    public function edit(Request $request, Room $room): Response
    {
        Gate::authorize('update', $room);
        $room->load('serviceClass:id,code,name,room_type,is_active')->loadCount(['activeStays', 'stays', 'camera']);

        return Inertia::render('Admin/Rooms/Edit', ['room' => (new RoomResource($room))->resolve($request), 'classes' => $this->options()]);
    }

    public function activate(FacilityStatusRequest $request, Room $room, RoomService $service): RedirectResponse
    {
        $service->setActive($request->user(), $room, true);

        return redirect()->route('admin.rooms.show', $room)->with('status', 'Kamar diaktifkan.');
    }

    public function update(UpdateRoomRequest $request, Room $room, RoomService $service): RedirectResponse
    {
        $service->update($request->user(), $room, $request->validated());

        return redirect()->route('admin.rooms.show', $room)->with('status', 'Informasi kamar diperbarui. Riwayat dan konfigurasi kamera tidak diubah.');
    }

    public function deactivate(FacilityStatusRequest $request, Room $room, RoomService $service): RedirectResponse
    {
        $service->setActive($request->user(), $room, false);

        return redirect()->route('admin.rooms.show', $room)->with('status', 'Kamar dinonaktifkan. Riwayat tetap tersimpan.');
    }

    private function options(bool $activeOnly = false): array
    {
        return ServiceClass::query()->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('code')->get(['id', 'code', 'name', 'room_type', 'is_active'])->toArray();
    }
}

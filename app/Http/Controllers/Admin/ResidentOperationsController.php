<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\CloseOperationRequest;
use App\Http\Requests\Operations\CreateEnrollmentRequest;
use App\Http\Requests\Operations\OperationIndexRequest;
use App\Http\Requests\Operations\StartStayRequest;
use App\Http\Resources\EnrollmentResource;
use App\Http\Resources\StayResource;
use App\Models\Elderly;
use App\Models\ElderlyStay;
use App\Models\Room;
use App\Models\ServiceClass;
use App\Models\ServiceEnrollment;
use App\Services\ResidentOperationsReadService;
use App\Services\ResidentOperationsService;
use App\Support\CanonicalServiceClasses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ResidentOperationsController extends Controller
{
    public function index(OperationIndexRequest $request, Elderly $elderly, ResidentOperationsReadService $read): Response
    {
        $stays = $elderly->stays()->with('room.serviceClass:id,code,name')->orderByDesc('started_at')->orderByDesc('id')
            ->paginate(15, ['*'], 'stays_page')->withQueryString()->through(fn ($stay) => (new StayResource($stay))->resolve($request));
        $enrollments = $elderly->serviceEnrollments()->with('serviceClass:id,code,name,is_active')->orderByDesc('started_at')->orderByDesc('id')
            ->paginate(15, ['*'], 'enrollments_page')->withQueryString()->through(fn ($item) => (new EnrollmentResource($item))->resolve($request));

        return Inertia::render('Admin/Operations/Index', ['resident' => $elderly->only(['id', 'name', 'is_active']),
            'operations' => $read->summary($elderly, $request), 'stays' => $stays, 'enrollments' => $enrollments]);
    }

    public function enrollmentForm(OperationIndexRequest $request, Elderly $elderly, ResidentOperationsReadService $read): Response
    {
        Gate::authorize('create', [ServiceEnrollment::class, $elderly]);
        $classes = ServiceClass::orderBy('code')->get()->map(fn ($class) => [...$class->only(['id', 'code', 'name', 'monthly_price', 'is_active']),
            'is_canonical' => CanonicalServiceClasses::matches($class)])->all();

        return Inertia::render('Admin/Operations/CreateEnrollment', ['resident' => $elderly->only(['id', 'name', 'is_active']),
            'operations' => $read->summary($elderly, $request), 'classes' => $classes]);
    }

    public function stayForm(OperationIndexRequest $request, Elderly $elderly, ResidentOperationsReadService $read): Response
    {
        Gate::authorize('create', [ElderlyStay::class, $elderly]);
        $operations = $read->summary($elderly, $request);
        $classId = $elderly->activeServiceEnrollment?->service_class_id;
        $class = $classId ? ServiceClass::find($classId) : null;
        $query = Room::withCount('activeStays')->where('is_active', true)->where('service_class_id', $classId);
        if (! $class || ! $class->is_active || ! CanonicalServiceClasses::matches($class)) {
            $query->whereRaw('1 = 0');
        }
        $search = trim($request->validated('search') ?? '');
        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn ($query) => $query->where('code', 'ilike', $pattern)->orWhere('name', 'ilike', $pattern));
        }
        $rooms = $query->orderBy('code')->orderBy('id')->paginate(15)->withQueryString()->through(fn ($room) => [
            ...$room->only(['id', 'code', 'name', 'capacity']), 'occupancy' => (int) $room->active_stays_count,
            'available' => max(0, $room->capacity - $room->active_stays_count),
            'eligible_capacity' => $room->capacity > 0 && ($class->room_type !== 'private' || $room->capacity === 1)]);

        return Inertia::render('Admin/Operations/StartStay', ['resident' => $elderly->only(['id', 'name', 'is_active']),
            'operations' => $operations, 'rooms' => $rooms, 'filters' => ['search' => $search]]);
    }

    public function enroll(CreateEnrollmentRequest $request, Elderly $elderly, ResidentOperationsService $service): RedirectResponse
    {
        $service->enroll($request->user(), $elderly, $request->validated('service_class_id'));

        return redirect()->route('admin.residents.operations', $elderly)->with('status', 'Enrollment dibuat. Tarif tersimpan sebagai snapshot; invoice tidak dibuat.');
    }

    public function start(StartStayRequest $request, Elderly $elderly, ResidentOperationsService $service): RedirectResponse
    {
        $service->startStay($request->user(), $elderly, $request->validated('room_id'), $request->validated('enrollment_id'));

        return redirect()->route('admin.residents.operations', $elderly)->with('status', 'Masa tinggal dimulai.');
    }

    public function closeStay(CloseOperationRequest $request, Elderly $elderly, ElderlyStay $stay, ResidentOperationsService $service): RedirectResponse
    {
        $service->endStay($request->user(), $elderly, $stay);

        return redirect()->route('admin.residents.operations', $elderly)->with('status', 'Masa tinggal ditutup. Enrollment tetap aktif.');
    }

    public function closeEnrollment(CloseOperationRequest $request, Elderly $elderly, ServiceEnrollment $enrollment, ResidentOperationsService $service): RedirectResponse
    {
        $service->endEnrollment($request->user(), $elderly, $enrollment);

        return redirect()->route('admin.residents.operations', $elderly)->with('status', 'Enrollment ditutup. Snapshot tarif dan seluruh riwayat tetap tersimpan.');
    }
}

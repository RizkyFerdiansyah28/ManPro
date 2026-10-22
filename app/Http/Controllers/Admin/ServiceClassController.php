<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Facilities\FacilityStatusRequest;
use App\Http\Requests\Facilities\UpdateServiceClassRequest;
use App\Http\Resources\ServiceClassResource;
use App\Models\ServiceClass;
use App\Services\ServiceClassService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ServiceClassController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ServiceClass::class);
        $classes = ServiceClass::query()->withCount($this->counts())->orderBy('code')->get()
            ->map(fn (ServiceClass $class) => (new ServiceClassResource($class))->resolve($request));

        return Inertia::render('Admin/ServiceClasses/Index', compact('classes'));
    }

    public function show(Request $request, ServiceClass $serviceClass): Response
    {
        Gate::authorize('view', $serviceClass);
        $serviceClass->loadCount($this->counts());

        return Inertia::render('Admin/ServiceClasses/Show', ['serviceClass' => (new ServiceClassResource($serviceClass))->resolve($request)]);
    }

    public function edit(Request $request, ServiceClass $serviceClass): Response
    {
        Gate::authorize('update', $serviceClass);
        $serviceClass->loadCount($this->counts());

        return Inertia::render('Admin/ServiceClasses/Edit', ['serviceClass' => (new ServiceClassResource($serviceClass))->resolve($request)]);
    }

    public function update(UpdateServiceClassRequest $request, ServiceClass $serviceClass, ServiceClassService $service): RedirectResponse
    {
        $service->update($request->user(), $serviceClass, $request->validated());

        return redirect()->route('admin.service-classes.show', $serviceClass)->with('status', 'Informasi kelas diperbarui. Tarif pada riwayat enrollment dan invoice tidak diubah.');
    }

    public function activate(FacilityStatusRequest $request, ServiceClass $serviceClass, ServiceClassService $service): RedirectResponse
    {
        $service->setActive($request->user(), $serviceClass, true);

        return redirect()->route('admin.service-classes.show', $serviceClass)->with('status', 'Kelas layanan diaktifkan.');
    }

    public function deactivate(FacilityStatusRequest $request, ServiceClass $serviceClass, ServiceClassService $service): RedirectResponse
    {
        $service->setActive($request->user(), $serviceClass, false);

        return redirect()->route('admin.service-classes.show', $serviceClass)->with('status', 'Kelas layanan dinonaktifkan. Seluruh dependensi dan riwayat tetap tersimpan.');
    }

    private function counts(): array
    {
        return ['rooms', 'rooms as active_rooms_count' => fn ($query) => $query->where('is_active', true),
            'stays as active_stays_count' => fn ($query) => $query->whereNull('ended_at'),
            'serviceEnrollments as active_enrollments_count' => fn ($query) => $query->whereNull('ended_at'), 'cameras'];
    }
}

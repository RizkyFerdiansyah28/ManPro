<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Residents\FamilyOptionsRequest;
use App\Http\Requests\Residents\ReassignFamilyRequest;
use App\Http\Requests\Residents\ResidentIndexRequest;
use App\Http\Requests\Residents\ResidentStatusRequest;
use App\Http\Requests\Residents\StoreResidentRequest;
use App\Http\Requests\Residents\UpdateResidentRequest;
use App\Http\Resources\ResidentResource;
use App\Models\Elderly;
use App\Models\User;
use App\Services\ResidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ResidentController extends Controller
{
    public function index(ResidentIndexRequest $request): Response
    {
        $filters = $request->safe()->only(['search', 'status']);
        $query = Elderly::query()->with('family:id,name,email,is_active');
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $query->where('name', 'ilike', '%'.addcslashes($search, '\\%_').'%');
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('is_active', $status === 'active');
        }
        $residents = $query->orderBy('name')->orderBy('id')->paginate(15)->withQueryString()
            ->through(fn (Elderly $elderly) => (new ResidentResource($elderly))->resolve($request));

        return Inertia::render('Admin/Residents/Index', compact('residents', 'filters'));
    }

    public function create(): Response
    {
        Gate::authorize('create', Elderly::class);

        return Inertia::render('Admin/Residents/Create');
    }

    public function store(StoreResidentRequest $request, ResidentService $service): RedirectResponse
    {
        $elderly = $service->create($request->user(), $request->validated());

        return redirect()->route('admin.residents.show', $elderly)->with('status', 'Data lansia berhasil dibuat.');
    }

    public function show(Request $request, Elderly $elderly): Response
    {
        Gate::authorize('view', $elderly);
        $elderly->load('family:id,name,email,is_active');

        return Inertia::render('Admin/Residents/Show', ['resident' => (new ResidentResource($elderly))->resolve($request)]);
    }

    public function edit(Request $request, Elderly $elderly): Response
    {
        Gate::authorize('update', $elderly);
        $elderly->load('family:id,name,email,is_active');

        return Inertia::render('Admin/Residents/Edit', ['resident' => (new ResidentResource($elderly))->resolve($request)]);
    }

    public function update(UpdateResidentRequest $request, Elderly $elderly, ResidentService $service): RedirectResponse
    {
        $service->update($request->user(), $elderly, $request->validated());

        return redirect()->route('admin.residents.show', $elderly)->with('status', 'Biodata lansia berhasil diperbarui.');
    }

    public function reassignFamily(ReassignFamilyRequest $request, Elderly $elderly, ResidentService $service): RedirectResponse
    {
        $service->reassignFamily($request->user(), $elderly, $request->validated());

        return redirect()->route('admin.residents.show', $elderly)->with('status', 'Penanggung jawab keluarga berhasil diubah.');
    }

    public function familyOptions(FamilyOptionsRequest $request): JsonResponse
    {
        $query = User::query()->select(['id', 'name', 'email'])->where('role', 'keluarga')->where('is_active', true);
        $search = trim($request->validated('search') ?? '');
        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn ($query) => $query->where('name', 'ilike', $pattern)->orWhere('email', 'ilike', $pattern));
        }

        return response()->json($query->orderBy('name')->orderBy('id')->paginate(15)->withQueryString());
    }

    public function activate(ResidentStatusRequest $request, Elderly $elderly, ResidentService $service): RedirectResponse
    {
        $service->setActive($request->user(), $elderly, true);

        return redirect()->route('admin.residents.show', $elderly)->with('status', 'Lansia berhasil diaktifkan.');
    }

    public function deactivate(ResidentStatusRequest $request, Elderly $elderly, ResidentService $service): RedirectResponse
    {
        $service->setActive($request->user(), $elderly, false);

        return redirect()->route('admin.residents.show', $elderly)->with('status', 'Lansia dinonaktifkan. Data dan riwayat tetap tersimpan.');
    }
}

<?php

namespace App\Http\Controllers\Family;

use App\Http\Controllers\Controller;
use App\Http\Requests\Residents\ResidentIndexRequest;
use App\Http\Resources\ResidentResource;
use App\Models\Elderly;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ResidentController extends Controller
{
    public function index(ResidentIndexRequest $request): Response
    {
        $filters = $request->safe()->only(['search', 'status']);
        $query = Elderly::query()->forFamily($request->user());
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $query->where('name', 'ilike', '%'.addcslashes($search, '\\%_').'%');
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('is_active', $status === 'active');
        }
        $residents = $query->orderBy('name')->orderBy('id')->paginate(15)->withQueryString()
            ->through(fn (Elderly $elderly) => (new ResidentResource($elderly))->resolve($request));

        return Inertia::render('Family/Residents/Index', compact('residents', 'filters'));
    }

    public function show(Request $request, string $elderly): Response
    {
        // Hindari binding global: ID lintas keluarga tidak pernah dimuat menjadi record.
        abort_unless(Str::isUuid($elderly), 404);
        $resident = Elderly::query()->forFamily($request->user())->findOrFail($elderly);
        Gate::authorize('view', $resident);

        return Inertia::render('Family/Residents/Show', ['resident' => (new ResidentResource($resident))->resolve($request)]);
    }
}

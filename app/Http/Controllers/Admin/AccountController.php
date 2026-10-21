<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccountIndexRequest;
use App\Http\Requests\Admin\AccountStatusRequest;
use App\Http\Requests\Admin\ResetAccountPasswordRequest;
use App\Http\Requests\Admin\StoreAccountRequest;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(AccountIndexRequest $request): Response
    {
        $filters = $request->safe()->only(['search', 'role', 'status']);
        $query = User::query()->select(['id', 'name', 'email', 'role', 'is_active', 'created_at']);
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn ($query) => $query->where('name', 'ilike', $pattern)->orWhere('email', 'ilike', $pattern));
        }
        if ($role = $filters['role'] ?? null) {
            $query->where('role', $role);
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('is_active', $status === 'active');
        }

        $accounts = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(15)->withQueryString()
            ->through(fn (User $account) => [
                ...$account->only(['id', 'name', 'email', 'role', 'is_active']),
                'can' => ['updateStatus' => Gate::allows('updateStatus', $account), 'resetPassword' => Gate::allows('resetPassword', $account)],
            ]);

        return Inertia::render('Admin/Accounts/Index', compact('accounts', 'filters'));
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('Admin/Accounts/Create');
    }

    public function storeCaregiver(StoreAccountRequest $request, AccountService $service): RedirectResponse
    {
        $service->create($request->user(), $request->validated(), 'perawat');

        return redirect()->route('admin.accounts.index')->with('status', 'Akun perawat dibuat dalam status nonaktif.');
    }

    public function storeFamily(StoreAccountRequest $request, AccountService $service): RedirectResponse
    {
        $service->create($request->user(), $request->validated(), 'keluarga');

        return redirect()->route('admin.accounts.index')->with('status', 'Akun keluarga dibuat dalam status nonaktif.');
    }

    public function activate(AccountStatusRequest $request, User $user, AccountService $service): RedirectResponse
    {
        $service->setActive($request->user(), $user, true);

        return redirect()->route('admin.accounts.index')->with('status', 'Akun berhasil diaktifkan.');
    }

    public function deactivate(AccountStatusRequest $request, User $user, AccountService $service): RedirectResponse
    {
        $service->setActive($request->user(), $user, false);

        return redirect()->route('admin.accounts.index')->with('status', 'Akun dinonaktifkan dan akses sesinya dicabut.');
    }

    public function editPassword(User $user): Response
    {
        Gate::authorize('resetPassword', $user);

        return Inertia::render('Admin/Accounts/ResetPassword', ['account' => $user->only(['id', 'name', 'email', 'role'])]);
    }

    public function resetPassword(ResetAccountPasswordRequest $request, User $user, AccountService $service): RedirectResponse
    {
        $service->resetPassword($request->user(), $user, $request->validated('password'));

        return redirect()->route('admin.accounts.index')->with('status', 'Kata sandi diperbarui dan sesi akun dicabut.');
    }
}

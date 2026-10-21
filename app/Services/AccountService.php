<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountService
{
    public function create(User $actor, array $data, string $role): User
    {
        abort_unless(in_array($role, User::MANAGED_ROLES, true), 403);
        try {
            return DB::transaction(function () use ($actor, $data, $role) {
                $admin = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($admin)->authorize('create', User::class);
                $account = new User;
                $account->name = $data['name'];
                $account->email = $data['email'];
                $account->password = $data['password'];
                $account->role = $role;
                $account->is_active = false;
                $account->save();

                return $account;
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505') {
                throw ValidationException::withMessages(['email' => 'Alamat email sudah digunakan.']);
            }
            throw $exception;
        }
    }

    public function setActive(User $actor, User $target, bool $active): void
    {
        DB::transaction(function () use ($actor, $target, $active) {
            [$admin, $account] = $this->lockAccounts($actor, $target);
            Gate::forUser($admin)->authorize('updateStatus', $account);
            $account->is_active = $active;
            if (! $active) {
                $account->remember_token = Str::random(60);
                $this->revokeDatabaseSessions($account);
            }
            $account->save();
        });
    }

    public function resetPassword(User $actor, User $target, #[\SensitiveParameter] string $password): void
    {
        DB::transaction(function () use ($actor, $target, $password) {
            [$admin, $account] = $this->lockAccounts($actor, $target);
            Gate::forUser($admin)->authorize('resetPassword', $account);
            $account->password = $password;
            $account->remember_token = Str::random(60);
            $account->save();
            $this->revokeDatabaseSessions($account);
            DB::table(config('auth.passwords.users.table'))->where('email', $account->email)->delete();
        });
    }

    private function lockAccounts(User $actor, User $target): array
    {
        $accounts = User::query()->whereIn('id', [$actor->id, $target->id])
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return [$accounts->get($actor->id) ?? abort(403), $accounts->get($target->id) ?? abort(404)];
    }

    private function revokeDatabaseSessions(User $account): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table'))
                ->where('user_id', $account->id)->delete();
        }
    }
}

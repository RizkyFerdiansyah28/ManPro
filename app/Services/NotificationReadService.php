<?php

namespace App\Services;

use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\User;
use App\Support\StaffNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NotificationReadService
{
    public function index(Request $request): array
    {
        $user = $this->viewer($request->user());
        $filters = $request->validated();
        $state = $filters['state'] ?? 'all';
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 15);
        try {
            $query = StaffNotification::inbox($user)
                ->leftJoin('fall_incidents as f', 'f.id', '=', 'n.fall_incident_id')
                ->leftJoin('ai_detections as d', 'd.id', '=', 'f.ai_detection_id')
                ->select(['n.id', 'n.created_at', 'n.read_at', 'n.data as payload', 'n.fall_incident_id', 'f.status'])
                ->selectRaw("(d.event_type='fall' AND f.id IS NOT NULL AND NOT EXISTS
                    (SELECT 1 FROM gait_observations g WHERE g.ai_detection_id=d.id)) AS valid_incident")
                ->selectRaw('(f.verified_at IS NOT NULL AND f.verified_by IS NOT NULL) AS is_verified');
            if ($state === 'unread') {
                $query->whereNull('n.read_at');
            } elseif ($state === 'read') {
                $query->whereNotNull('n.read_at');
            }
            $sql = $query->toSql();
            $result = DB::selectOne("WITH inbox AS MATERIALIZED ($sql)
                SELECT statement_timestamp() AS as_of,(SELECT count(*)::int FROM inbox) AS total,
                COALESCE((SELECT jsonb_agg(to_jsonb(page)) FROM (
                    SELECT * FROM inbox ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?
                ) page),'[]'::jsonb)::text AS records", [...$query->getBindings(), $perPage, ($page - 1) * $perPage]);
        } catch (QueryException) {
            throw new HttpException(503, 'Daftar notifikasi belum dapat dimuat.');
        }
        $data = array_map(fn ($row) => (new NotificationResource($row))->resolve($request), json_decode($result->records));
        $url = fn ($number) => '/notifications?'.http_build_query(['state' => $state, 'per_page' => $perPage, 'page' => $number]);
        $last = max(1, (int) ceil($result->total / $perPage));

        return ['records' => ['data' => $data, 'total' => $result->total, 'current_page' => $page, 'last_page' => $last,
            'prev_page_url' => $page > 1 ? $url($page - 1) : null, 'next_page_url' => $page < $last ? $url($page + 1) : null],
            'filters' => ['state' => $state, 'per_page' => $perPage], 'as_of' => $this->instant($result->as_of)];
    }

    public function summary(User $actor): array
    {
        $user = $this->viewer($actor);
        try {
            $unread = StaffNotification::inbox($user)->whereNull('n.read_at')->selectRaw('count(*)::int');
            $pending = StaffNotification::pending()->selectRaw('count(*)::int');
            $result = DB::selectOne('SELECT statement_timestamp() AS as_of,('.$unread->toSql().') AS unread_count,('
                .$pending->toSql().') AS pending_count', [...$unread->getBindings(), ...$pending->getBindings()]);
        } catch (QueryException) {
            throw new HttpException(503, 'Ringkasan notifikasi belum dapat dimuat.');
        }

        return ['unread_count' => $result->unread_count, 'pending_count' => $result->pending_count, 'as_of' => $this->instant($result->as_of)];
    }

    private function viewer(User $actor): User
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('viewAny', Notification::class);

        return $user;
    }

    private function instant(string $value): string
    {
        return CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}

<?php

namespace App\Services;

use App\Http\Requests\Monitoring\MonitoringRequest;
use App\Http\Resources\MonitoringRecordResource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class MonitoringReadService
{
    public function dashboard(MonitoringRequest $request): array
    {
        $this->authorize($request);
        $filters = $this->filters($request, true);
        $filtered = $this->filtered($filters, true);
        $sql = $this->context().', filtered AS NOT MATERIALIZED ('.$filtered->toSql()."),
            stats AS (
                SELECT count(*) FILTER (WHERE event_type='fall')::int AS fall_detections,
                count(*) FILTER (WHERE has_gait)::int AS gait_observations,
                count(*) FILTER (WHERE fall_status='pending')::int AS pending,
                count(*) FILTER (WHERE fall_status='acknowledged')::int AS acknowledged,
                count(*) FILTER (WHERE fall_status='resolved')::int AS resolved,
                count(*) FILTER (WHERE fall_status='false_alarm')::int AS false_alarm,
                count(*) FILTER (WHERE is_verified)::int AS verified
                FROM filtered
            ), camera_counts AS (
                SELECT count(*)::int AS registered, count(*) FILTER (WHERE is_active)::int AS active,
                    count(*) FILTER (WHERE NOT is_active)::int AS inactive FROM cameras
            ), open_counts AS (
                SELECT count(*) FILTER (WHERE status='pending')::int AS pending,
                    count(*) FILTER (WHERE status='acknowledged')::int AS acknowledged
                FROM fall_incidents f JOIN ai_detections d ON d.id=f.ai_detection_id AND d.event_type='fall'
            ), recent AS (SELECT * FROM filtered ORDER BY occurred_at DESC,id DESC LIMIT 10),
            pending_preview AS (SELECT * FROM context WHERE fall_status='pending' ORDER BY occurred_at,id LIMIT 5)
            SELECT (SELECT as_of FROM clock) AS as_of,
                (SELECT row_to_json(stats) FROM stats) AS stats,
                (SELECT row_to_json(camera_counts) FROM camera_counts) AS cameras,
                (SELECT row_to_json(open_counts) FROM open_counts) AS open_incidents,
                (SELECT COALESCE(jsonb_agg(to_jsonb(recent) ORDER BY occurred_at DESC,id DESC),'[]'::jsonb) FROM recent) AS recent,
                (SELECT COALESCE(jsonb_agg(to_jsonb(pending_preview) ORDER BY occurred_at,id),'[]'::jsonb) FROM pending_preview) AS pending_preview";
        $result = $this->select($sql, $filtered->getBindings());

        return ['filters' => $filters, 'as_of' => $this->instant($result->as_of),
            'stats' => json_decode($result->stats, true, flags: JSON_THROW_ON_ERROR),
            'cameras' => json_decode($result->cameras, true, flags: JSON_THROW_ON_ERROR),
            'open_incidents' => json_decode($result->open_incidents, true, flags: JSON_THROW_ON_ERROR),
            'recent' => $this->resources($result->recent, $request),
            'pending_preview' => $this->resources($result->pending_preview, $request)];
    }

    public function records(MonitoringRequest $request): array
    {
        return $this->listing($request, false);
    }

    public function openIncidents(MonitoringRequest $request): array
    {
        return $this->listing($request, true);
    }

    private function listing(MonitoringRequest $request, bool $open): array
    {
        $this->authorize($request);
        $filters = $this->filters($request, ! $open);
        $filtered = $this->filtered($filters, ! $open);
        if ($open) {
            $filtered->whereIn('m.fall_status', ['pending', 'acknowledged']);
            if (isset($filters['status'])) {
                $filtered->where('m.fall_status', $filters['status']);
            }
        }
        $perPage = (int) ($filters['per_page'] ?? 15);
        $page = (int) ($filters['page'] ?? 1);
        $direction = $open ? 'ASC' : 'DESC';
        $sql = $this->context().', filtered AS NOT MATERIALIZED ('.$filtered->toSql()."),
            page_rows AS (SELECT * FROM filtered ORDER BY occurred_at $direction,id $direction LIMIT ? OFFSET ?)
            SELECT (SELECT as_of FROM clock) AS as_of, (SELECT count(*) FROM filtered) AS total,
                (SELECT COALESCE(jsonb_agg(to_jsonb(page_rows) ORDER BY occurred_at $direction,id $direction),'[]'::jsonb) FROM page_rows) AS records,
                (SELECT COALESCE(jsonb_agg(jsonb_build_object('code',code,'name',name) ORDER BY code),'[]'::jsonb) FROM service_classes) AS classes";
        $result = $this->select($sql, [...$filtered->getBindings(), $perPage, ($page - 1) * $perPage]);
        $paginator = new LengthAwarePaginator($this->resources($result->records, $request), (int) $result->total, $perPage, $page,
            ['path' => $request->url()]);
        $paginator->appends(array_diff_key($filters, ['page' => true]));

        return ['filters' => $filters, 'as_of' => $this->instant($result->as_of),
            'records' => $paginator->toArray(), 'classes' => json_decode($result->classes, true, flags: JSON_THROW_ON_ERROR)];
    }

    private function authorize(MonitoringRequest $request): void
    {
        Gate::forUser($request->user())->authorize('viewMonitoring');
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new ServiceUnavailableHttpException(null, 'Monitoring memerlukan koneksi PostgreSQL.');
        }
    }

    private function select(string $sql, array $bindings): object
    {
        try {
            return DB::selectOne($sql, $bindings);
        } catch (QueryException) {
            // Jangan mengirim atau mencatat SQL/binding pencarian sebagai response error.
            throw new ServiceUnavailableHttpException(null, 'Data monitoring belum dapat dimuat. Silakan coba kembali.');
        }
    }

    private function filters(MonitoringRequest $request, bool $range): array
    {
        $filters = array_filter($request->validated(), fn ($value) => $value !== null && $value !== '');
        if ($range) {
            $filters['range'] ??= '24h';
        }

        return $filters;
    }

    private function filtered(array $filters, bool $range): Builder
    {
        $query = DB::query()->from('context as m')->select('m.*');
        if ($range) {
            $query->whereRaw('m.occurred_at < (SELECT as_of FROM clock)');
            if ($filters['range'] === 'custom') {
                $query->where('m.occurred_at', '>=', CarbonImmutable::parse($filters['date_from'], 'Asia/Jakarta')->startOfDay()->utc()->format('Y-m-d H:i:s.uP'))
                    ->where('m.occurred_at', '<', CarbonImmutable::parse($filters['date_to'], 'Asia/Jakarta')->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s.uP'));
            } else {
                $interval = ['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days'][$filters['range']];
                $query->whereRaw("m.occurred_at >= (SELECT as_of FROM clock) - interval '$interval'");
            }
        }
        foreach (['camera_id', 'room_id', 'class_code', 'event_type', 'fall_status', 'gait_category'] as $key) {
            if (isset($filters[$key])) {
                $query->where('m.'.$key, $filters[$key]);
            }
        }
        if (isset($filters['location'])) {
            $query->where('m.location_state', $filters['location']);
        }
        if (isset($filters['identity'])) {
            $filters['identity'] === 'known' ? $query->whereNotNull('m.identity_name') : $query->whereNull('m.identity_name');
        }
        if (isset($filters['verification'])) {
            $query->where('m.is_verified', $filters['verification'] === 'verified');
        }
        if (isset($filters['search'])) {
            $pattern = '%'.addcslashes(trim($filters['search']), '\\%_').'%';
            $query->where(fn ($q) => $q->where('m.camera_name', 'ilike', $pattern)->orWhere('m.room_code', 'ilike', $pattern)->orWhere('m.room_name', 'ilike', $pattern));
        }

        return $query;
    }

    private function context(): string
    {
        $valid = '(p.id IS NOT NULL AND d.occurred_at>=p.opened_at AND (p.closed_at IS NULL OR d.occurred_at<p.closed_at))';
        $gaitIdentity = "(d.event_type='gait' AND g.identity_basis='vip_room_single_resident' AND g.elderly_id IS NOT NULL
            AND $valid AND p.room_type='private' AND p.room_capacity=1 AND s.code='vip'
            AND (SELECT count(*) FROM elderly_stays st WHERE st.room_id=p.room_id AND st.started_at<=d.occurred_at AND (st.ended_at IS NULL OR st.ended_at>d.occurred_at))=1
            AND EXISTS (SELECT 1 FROM elderly_stays st WHERE st.room_id=p.room_id AND st.elderly_id=g.elderly_id AND st.started_at<=d.occurred_at AND (st.ended_at IS NULL OR st.ended_at>d.occurred_at))
            AND (SELECT count(*) FROM service_enrollments en WHERE en.elderly_id=g.elderly_id AND en.started_at<=d.occurred_at AND (en.ended_at IS NULL OR en.ended_at>d.occurred_at))=1
            AND EXISTS (SELECT 1 FROM service_enrollments en WHERE en.elderly_id=g.elderly_id AND en.service_class_id=p.service_class_id AND en.started_at<=d.occurred_at AND (en.ended_at IS NULL OR en.ended_at>d.occurred_at)))";
        $fallIdentity = "(d.event_type='fall' AND f.status IN ('acknowledged','resolved') AND f.elderly_id IS NOT NULL AND f.verified_at IS NOT NULL AND f.verified_by IS NOT NULL)";

        // Projection whitelist: tidak ada source_reference, metadata, notes, keluarga atau billing.
        // NOT MATERIALIZED memungkinkan planner memangkas projection mahal pada count/summary.
        return "WITH clock AS (SELECT statement_timestamp() AS as_of), context AS NOT MATERIALIZED (
            SELECT d.id,d.event_type,d.occurred_at,d.received_at,d.camera_id,c.name AS camera_name,
                CASE WHEN d.camera_period_id IS NULL THEN 'legacy' WHEN $valid THEN 'tracked' ELSE 'unavailable' END AS location_state,
                CASE WHEN $valid THEN p.room_id END AS room_id,
                CASE WHEN $valid THEN r.code END AS room_code, CASE WHEN $valid THEN r.name END AS room_name,
                CASE WHEN $valid THEN p.service_class_id END AS class_id,
                CASE WHEN $valid THEN s.code END AS class_code, CASE WHEN $valid THEN s.name END AS class_name,
                (g.id IS NOT NULL) AS has_gait,g.category AS gait_category,
                (f.id IS NOT NULL) AS has_incident,f.status AS fall_status,
                (f.verified_at IS NOT NULL AND f.verified_by IS NOT NULL) AS is_verified,
                f.acknowledged_at,f.verified_at,f.resolved_at,
                CASE WHEN $gaitIdentity THEN eg.name WHEN $fallIdentity THEN ef.name END AS identity_name,
                CASE WHEN $gaitIdentity THEN g.elderly_id WHEN $fallIdentity THEN f.elderly_id END AS identity_elderly_id,
                CASE WHEN $gaitIdentity THEN 'vip_room_single_resident' WHEN $fallIdentity THEN 'verified_incident' ELSE 'unknown' END AS identity_basis
            FROM ai_detections d JOIN cameras c ON c.id=d.camera_id
            LEFT JOIN camera_operating_periods p ON p.id=d.camera_period_id AND p.camera_id=d.camera_id
            LEFT JOIN rooms r ON r.id=p.room_id LEFT JOIN service_classes s ON s.id=p.service_class_id
            LEFT JOIN gait_observations g ON g.ai_detection_id=d.id AND d.event_type='gait'
            LEFT JOIN fall_incidents f ON f.ai_detection_id=d.id AND d.event_type='fall'
            LEFT JOIN elderlies eg ON eg.id=g.elderly_id LEFT JOIN elderlies ef ON ef.id=f.elderly_id
        )";
    }

    private function resources(string $json, MonitoringRequest $request): array
    {
        return array_map(fn ($row) => (new MonitoringRecordResource($row))->resolve($request), json_decode($json, flags: JSON_THROW_ON_ERROR));
    }

    private function instant(string $value): string
    {
        return CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}

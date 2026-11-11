<?php

namespace App\Services;

use App\Http\Requests\Family\FamilyPortalRequest;
use App\Http\Resources\FamilyResidentResource;
use App\Support\CanonicalServiceClasses;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FamilyPortalReadService
{
    public function dashboard(FamilyPortalRequest $request): array
    {
        $result = $this->read($request, null, 6, 0);

        return ['summary' => json_decode($result->summary, true, flags: JSON_THROW_ON_ERROR),
            'residents' => $this->resources($result->records, $request), 'as_of' => $this->instant($result->as_of)];
    }

    public function index(FamilyPortalRequest $request): array
    {
        $page = (int) ($request->validated('page') ?? 1);
        $perPage = (int) ($request->validated('per_page') ?? 15);
        $filters = ['search' => $request->validated('search') ?? '', 'status' => $request->validated('status') ?? '', 'per_page' => $perPage];
        $result = $this->read($request, null, $perPage, ($page - 1) * $perPage);
        $last = max(1, (int) ceil($result->total / $perPage));
        $url = fn ($n) => '/keluarga/residents?'.http_build_query([...$filters, 'page' => $n]);

        return ['residents' => ['data' => $this->resources($result->records, $request), 'total' => $result->total,
            'current_page' => $page, 'last_page' => $last, 'prev_page_url' => $page > 1 ? $url($page - 1) : null,
            'next_page_url' => $page < $last ? $url($page + 1) : null], 'filters' => $filters, 'as_of' => $this->instant($result->as_of)];
    }

    public function show(FamilyPortalRequest $request, string $id): array
    {
        Gate::forUser($request->user())->authorize('viewFamilyPortal');
        abort_unless(Str::isUuid($id), 404);
        $result = $this->read($request, $id, 1, 0);
        abort_unless($result->total === 1, 404);

        return ['resident' => $this->resources($result->records, $request)[0], 'as_of' => $this->instant($result->as_of)];
    }

    private function read(FamilyPortalRequest $request, ?string $id, int $limit, int $offset): object
    {
        Gate::forUser($request->user())->authorize('viewFamilyPortal');
        $fields = array_keys(CanonicalServiceClasses::DEFINITIONS['vip']);
        $definitions = [];
        foreach (CanonicalServiceClasses::DEFINITIONS as $code => $definition) {
            $definitions[] = ['code' => $code, ...$definition];
        }
        $shape = implode(',', array_map(fn ($f) => $f.($f === 'room_type' ? ' text' : ' boolean'), $fields));
        $match = implode(' AND ', array_map(fn ($f) => "sc.$f=c.$f", $fields));
        $where = '';
        $bindings = [json_encode($definitions, JSON_THROW_ON_ERROR), $request->user()->id];
        if ($id !== null) {
            $where .= ' AND e.id=?';
            $bindings[] = $id;
        }
        if ($request->routeIs('keluarga.residents.index')) {
            $search = $request->validated('search') ?? '';
            if ($search !== '') {
                $where .= ' AND e.name ILIKE ?';
                $bindings[] = '%'.addcslashes($search, '\\%_').'%';
            }
            if ($status = $request->validated('status')) {
                $where .= ' AND e.is_active=?';
                $bindings[] = $status === 'active';
            }
        }
        // Scope, clock, layanan, total/page dan actor eligibility memakai satu snapshot.
        $sql = "WITH clock AS MATERIALIZED (SELECT statement_timestamp() AS as_of),
            canonical AS (SELECT * FROM jsonb_to_recordset(?::jsonb) AS d(code text,$shape)),
            eligible AS MATERIALIZED (SELECT id FROM users WHERE id=? AND role='keluarga' AND is_active),
            owned AS MATERIALIZED (
                SELECT e.id,e.name,e.birth_date::text,e.is_active FROM elderlies e
                JOIN eligible u ON u.id=e.family_id WHERE true $where
            ), context AS (
                SELECT o.*,clock.as_of,st.effective_count AS stay_count,en.effective_count AS enrollment_count,
                    st.future_count+en.future_count AS future_count,cs.started_at AS stay_started_at,
                    ce.started_at AS enrollment_started_at,ce.monthly_price::text AS monthly_price,
                    r.code AS room_code,r.name AS room_name,r.capacity,r.service_class_id AS room_class_id,
                    sc.id AS class_id,sc.code AS class_code,sc.name AS class_name,sc.room_type,
                    (c.code IS NOT NULL AND $match) AS canonical_match,
                    (SELECT count(*) FROM elderly_stays rs WHERE rs.room_id=r.id
                        AND rs.started_at<=clock.as_of AND (rs.ended_at IS NULL OR rs.ended_at>clock.as_of)) AS room_occupancy
                FROM owned o CROSS JOIN clock
                CROSS JOIN LATERAL (
                    SELECT count(*) FILTER (WHERE started_at<=clock.as_of AND (ended_at IS NULL OR ended_at>clock.as_of)) AS effective_count,
                        count(*) FILTER (WHERE started_at>clock.as_of OR ended_at>clock.as_of) AS future_count
                    FROM elderly_stays WHERE elderly_id=o.id
                ) st
                CROSS JOIN LATERAL (
                    SELECT count(*) FILTER (WHERE started_at<=clock.as_of AND (ended_at IS NULL OR ended_at>clock.as_of)) AS effective_count,
                        count(*) FILTER (WHERE started_at>clock.as_of OR ended_at>clock.as_of) AS future_count
                    FROM service_enrollments WHERE elderly_id=o.id
                ) en
                LEFT JOIN LATERAL (SELECT room_id,started_at FROM elderly_stays WHERE elderly_id=o.id
                    AND ended_at IS NULL AND started_at<=clock.as_of ORDER BY id LIMIT 1) cs ON true
                LEFT JOIN LATERAL (SELECT service_class_id,started_at,monthly_price FROM service_enrollments WHERE elderly_id=o.id
                    AND ended_at IS NULL AND started_at<=clock.as_of ORDER BY id LIMIT 1) ce ON true
                LEFT JOIN rooms r ON r.id=cs.room_id LEFT JOIN service_classes sc ON sc.id=ce.service_class_id
                LEFT JOIN canonical c ON c.code=sc.code
            ), projection AS MATERIALIZED (
                SELECT *,CASE
                    WHEN future_count>0 OR stay_count>1 OR enrollment_count>1
                        OR (enrollment_count=1 AND (enrollment_started_at IS NULL OR NOT COALESCE(canonical_match,false)))
                        OR (stay_count=1 AND (stay_started_at IS NULL OR enrollment_count<>1 OR room_class_id IS DISTINCT FROM class_id
                            OR stay_started_at<enrollment_started_at OR room_occupancy>capacity OR (room_type='private' AND capacity<>1)))
                    THEN 'needs_review'
                    WHEN stay_count=1 THEN 'staying'
                    WHEN enrollment_count=1 THEN 'enrollment_only'
                    ELSE 'none' END AS service_state
                FROM context
            )
            SELECT clock.as_of,EXISTS(SELECT 1 FROM eligible) AS authorized,
                (SELECT count(*)::int FROM projection) AS total,
                (SELECT jsonb_build_object('residents',count(*)::int,'active_residents',count(*) FILTER (WHERE is_active)::int,
                    'staying',count(*) FILTER (WHERE service_state='staying')::int,
                    'enrolled',count(*) FILTER (WHERE service_state IN ('staying','enrollment_only'))::int,
                    'needs_review',count(*) FILTER (WHERE service_state='needs_review')::int,
                    'without_service',count(*) FILTER (WHERE service_state='none')::int)::text FROM projection) AS summary,
                COALESCE((SELECT jsonb_agg(to_jsonb(page)) FROM
                    (SELECT * FROM projection ORDER BY name,id LIMIT ? OFFSET ?) page),'[]'::jsonb)::text AS records FROM clock";
        try {
            $result = DB::selectOne($sql, [...$bindings, $limit, $offset]);
        } catch (QueryException) {
            throw new HttpException(503, 'Informasi keluarga belum dapat dimuat. Silakan coba kembali.');
        }
        abort_unless($result->authorized, 403);

        return $result;
    }

    private function resources(string $json, FamilyPortalRequest $request): array
    {
        return array_map(fn ($r) => (new FamilyResidentResource($r))->resolve($request), json_decode($json, flags: JSON_THROW_ON_ERROR));
    }

    private function instant(string $value): string
    {
        return CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}

<?php

namespace App\Services;

use App\Http\Resources\IncidentResource;
use App\Support\IncidentVersion;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IncidentReadService
{
    public function index(Request $request): array
    {
        $filters = $request->validated();
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 15);
        $query = $this->projection();
        if (! empty($filters['status'])) {
            $query->where('f.status', $filters['status']);
        }
        // Count dan page berasal dari satu snapshot, tanpa join one-to-many jurnal.
        $sql = $query->toSql();
        $result = DB::selectOne("WITH incidents AS MATERIALIZED ($sql)
            SELECT (SELECT count(*) FROM incidents)::int AS total,
                COALESCE((SELECT jsonb_agg(to_jsonb(page)) FROM (
                    SELECT * FROM incidents ORDER BY occurred_at DESC,id DESC LIMIT ? OFFSET ?
                ) page),'[]'::jsonb)::text AS records", [...$query->getBindings(), $perPage, ($page - 1) * $perPage]);
        $data = array_map(fn ($row) => (new IncidentResource($row))->resolve($request), json_decode($result->records));

        return ['records' => $this->page($data, $result->total, $page, $perPage, '/monitoring/incidents', $filters),
            'filters' => ['status' => $filters['status'] ?? '', 'per_page' => $perPage]];
    }

    public function show(Request $request, string $id): array
    {
        $this->validId($id);
        $page = (int) ($request->validated('page') ?? 1);
        $offset = ($page - 1) * 15;
        $query = $this->projection()->where('f.id', $id)->addSelect('f.notes')
            ->selectRaw('(SELECT count(*)::int FROM fall_incident_actions a WHERE a.fall_incident_id=f.id) AS action_total')
            ->selectRaw("COALESCE((SELECT jsonb_agg(to_jsonb(journal)) FROM (
                SELECT a.id,a.sequence,a.action,a.from_status,a.to_status,a.notes,a.acted_at,
                    u.name AS actor_name,e.name AS identity_name
                FROM fall_incident_actions a JOIN users u ON u.id=a.actor_id
                LEFT JOIN elderlies e ON e.id=a.elderly_id
                WHERE a.fall_incident_id=f.id ORDER BY a.sequence LIMIT 15 OFFSET ?
            ) journal),'[]'::jsonb)::text AS journal", [$offset]);
        $row = $query->first();
        abort_unless($row, 404);
        $incident = (new IncidentResource($row, true))->resolve($request);

        return ['incident' => $incident, 'actions' => $this->page(json_decode($row->journal, true), $row->action_total,
            $page, 15, '/monitoring/incidents/'.$id, [])];
    }

    public function context(string $id): ?object
    {
        return DB::table('fall_incidents as f')->join('ai_detections as d', 'd.id', '=', 'f.ai_detection_id')
            ->leftJoin('camera_operating_periods as p', fn ($q) => $q->on('p.id', '=', 'd.camera_period_id')->on('p.camera_id', '=', 'd.camera_id'))
            ->leftJoin('rooms as r', 'r.id', '=', 'p.room_id')
            ->where('f.id', $id)->where('d.event_type', 'fall')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('gait_observations as g')->whereColumn('g.ai_detection_id', 'd.id'))
            ->select(['d.id as detection_id', 'd.camera_period_id', 'p.room_id', 'p.service_class_id', 'r.service_class_id as current_class_id'])
            ->selectRaw($this->validPeriod().' AS valid_period')->first();
    }

    public function candidates(string $id): Builder
    {
        return DB::table('elderlies as e')->select(['e.id', 'e.name'])
            ->whereExists(function ($q) use ($id) {
                $q->selectRaw('1')->from('fall_incidents as f')->join('ai_detections as d', 'd.id', '=', 'f.ai_detection_id')
                    ->join('camera_operating_periods as p', fn ($j) => $j->on('p.id', '=', 'd.camera_period_id')->on('p.camera_id', '=', 'd.camera_id'))
                    ->where('f.id', $id)->where('d.event_type', 'fall')->where('f.status', 'acknowledged')
                    ->whereNull('f.verified_at')->whereNull('f.verified_by')->whereRaw($this->validPeriod())
                    ->whereNotExists(fn ($g) => $g->selectRaw('1')->from('gait_observations as g')->whereColumn('g.ai_detection_id', 'd.id'))
                    ->whereExists(fn ($st) => $st->selectRaw('1')->from('elderly_stays as st')->whereColumn('st.elderly_id', 'e.id')
                        ->whereColumn('st.room_id', 'p.room_id')->whereColumn('st.started_at', '<=', 'd.occurred_at')
                        ->where(fn ($end) => $end->whereNull('st.ended_at')->orWhereColumn('st.ended_at', '>', 'd.occurred_at')))
                    ->whereRaw('(SELECT count(*) FROM elderly_stays st WHERE st.elderly_id=e.id
                        AND st.started_at<=d.occurred_at AND (st.ended_at IS NULL OR st.ended_at>d.occurred_at))=1');
            });
    }

    public function residentOptions(Request $request, string $id): array
    {
        $this->validId($id);
        $state = DB::table('fall_incidents')->where('id', $id)->first(['status', 'verified_at']);
        abort_unless($state, 404);
        abort_unless($state->status === 'acknowledged' && $state->verified_at === null, 409);
        $query = $this->candidates($id);
        if ($search = $request->validated('search')) {
            $query->where('e.name', 'ilike', '%'.addcslashes($search, '\\%_').'%');
        }
        $page = $query->orderBy('e.name')->orderBy('e.id')->simplePaginate(15);

        return ['data' => $page->items(), 'has_more' => $page->hasMorePages(), 'page' => $page->currentPage()];
    }

    private function projection(): Builder
    {
        $valid = $this->validPeriod();

        return DB::table('fall_incidents as f')->join('ai_detections as d', 'd.id', '=', 'f.ai_detection_id')
            ->join('cameras as c', 'c.id', '=', 'd.camera_id')
            ->leftJoin('camera_operating_periods as p', fn ($q) => $q->on('p.id', '=', 'd.camera_period_id')->on('p.camera_id', '=', 'd.camera_id'))
            ->leftJoin('rooms as r', 'r.id', '=', 'p.room_id')->leftJoin('service_classes as s', 's.id', '=', 'p.service_class_id')
            ->leftJoin('elderlies as e', 'e.id', '=', 'f.elderly_id')
            ->leftJoin('users as responder', 'responder.id', '=', 'f.responding_caregiver_id')
            ->leftJoin('users as verifier', 'verifier.id', '=', 'f.verified_by')
            ->where('d.event_type', 'fall')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('gait_observations as g')->whereColumn('g.ai_detection_id', 'd.id'))
            ->select(array_map(fn ($field) => 'f.'.$field, array_diff(IncidentVersion::FIELDS, ['notes'])))
            ->addSelect(['d.occurred_at', 'd.received_at', 'c.name as camera_name', 'responder.name as responder_name', 'verifier.name as verifier_name'])
            ->selectRaw("CASE WHEN d.camera_period_id IS NULL THEN 'legacy' WHEN $valid THEN 'tracked' ELSE 'unavailable' END AS location_state,
                CASE WHEN $valid THEN r.code END AS room_code, CASE WHEN $valid THEN r.name END AS room_name,
                CASE WHEN $valid THEN s.name END AS class_name,
                CASE WHEN f.status IN ('acknowledged','resolved') AND f.verified_at IS NOT NULL AND f.verified_by IS NOT NULL THEN e.name END AS identity_name");
    }

    private function validPeriod(): string
    {
        return '(p.id IS NOT NULL AND d.occurred_at<=statement_timestamp() AND d.occurred_at>=p.opened_at
            AND (p.closed_at IS NULL OR d.occurred_at<p.closed_at))';
    }

    private function validId(string $id): void
    {
        abort_unless(Str::isUuid($id), 404);
    }

    private function page(array $data, int $total, int $page, int $perPage, string $path, array $filters): array
    {
        $url = fn ($number) => $path.'?'.http_build_query([...$filters, 'page' => $number]);
        $last = max(1, (int) ceil($total / $perPage));

        return ['data' => $data, 'total' => $total, 'current_page' => $page, 'last_page' => $last,
            'from' => $data ? ($page - 1) * $perPage + 1 : null, 'to' => $data ? ($page - 1) * $perPage + count($data) : null,
            'prev_page_url' => $page > 1 ? $url($page - 1) : null, 'next_page_url' => $page < $last ? $url($page + 1) : null];
    }
}

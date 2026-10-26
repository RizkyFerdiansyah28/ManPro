<?php

namespace Tests\Feature\Monitoring;

use App\Services\CameraOperatingPeriodService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MonitoringFeatureTestCase;

class MonitoringRecordsTest extends MonitoringFeatureTestCase
{
    public static function invalidFilters(): array
    {
        return [
            [['range' => 'all']], [['range' => ['24h']]], [['range' => 'custom']], [['range' => 'custom', 'date_from' => '2026-02-30', 'date_to' => '2026-03-01']],
            [['range' => 'custom', 'date_from' => '2026-03-02', 'date_to' => '2026-03-01']],
            [['range' => 'custom', 'date_from' => '2026-01-01', 'date_to' => '2026-06-01']],
            [['range' => 'custom', 'date_from' => '2099-01-01', 'date_to' => '2099-01-02']],
            [['date_from' => '2026-01-01']], [['event_type' => 'unknown']], [['fall_status' => 'pending']],
            [['event_type' => 'gait', 'fall_status' => 'pending']], [['event_type' => 'fall', 'gait_category' => 'walking_normal']],
            [['verification' => 'verified']], [['event_type' => 'fall', 'verification' => 'yes']],
            [['identity' => 'anyone']], [['location' => 'current']], [['class_code' => 'class_4']],
            [['camera_id' => 'invalid']], [['room_id' => 'invalid']], [['camera_id' => '00000000-0000-4000-8000-000000000099']],
            [['page' => 0]], [['page' => 10001]], [['page' => ['1']]], [['per_page' => 100]],
            [['per_page' => '1.5']], [['search' => ['secret']]], [['search' => str_repeat('a', 101)]],
            [['sort' => 'metadata']], [['metadata' => 'PRIVATE-INJECTED-FILTER']],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected_without_reflecting_private_input(array $filters): void
    {
        $this->fall();
        $before = $this->monitoringSnapshot();
        $response = $this->getJson('/monitoring/records?'.http_build_query($filters))->assertUnprocessable();
        $response->assertDontSee('PRIVATE-INJECTED-FILTER');
        $this->assertSame($before, $this->monitoringSnapshot());
        $this->assertNull(session()->get('_old_input'));
    }

    public function test_html_validation_redirect_has_no_unknown_query_or_old_input(): void
    {
        $this->get('/monitoring/records?source_reference=PRIVATE-URL&secret=PRIVATE-KEY')->assertRedirect('/monitoring/records')->assertSessionHasErrors('filters');
        $this->assertNull(session()->get('_old_input'));
        $this->get('/monitoring/records')->assertOk()->assertDontSee('PRIVATE-URL')->assertDontSee('PRIVATE-KEY');
    }

    public static function pageSizes(): array
    {
        return [[15], [30], [50]];
    }

    #[DataProvider('pageSizes')]
    public function test_pagination_has_correct_total_and_deterministic_order(int $size): void
    {
        $ids = [];
        foreach (range(1, 52) as $i) {
            $ids[] = $this->fall()->id;
        }
        rsort($ids, SORT_STRING);
        $page = $this->listing(['per_page' => $size]);
        $this->assertSame(52, $page['records']['total']);
        $this->assertSame(array_slice($ids, 0, $size), array_column($page['records']['data'], 'id'));
        $next = $this->listing(['per_page' => $size, 'page' => 2]);
        $this->assertSame(array_slice($ids, $size, $size), array_column($next['records']['data'], 'id'));
        $this->assertStringContainsString('per_page='.$size, $page['records']['next_page_url']);
        $this->assertSame([], $this->listing(['page' => 10000])['records']['data']);
        $this->assertSame(52, $this->listing(['page' => 10000])['records']['total']);
    }

    public function test_type_status_category_verification_and_identity_filters_match_rows(): void
    {
        $this->fall();
        $resolved = $this->fall('resolved', resident: $this->resident());
        $this->event('gait', [], ['category' => 'walking_abnormal']);
        $known = $this->knownGait();
        $this->assertSame(2, $this->listing(['event_type' => 'fall'])['records']['total']);
        $this->assertSame([$resolved->id], array_column($this->listing(['event_type' => 'fall', 'fall_status' => 'resolved', 'verification' => 'verified'])['records']['data'], 'id'));
        $this->assertSame(1, $this->listing(['event_type' => 'fall', 'verification' => 'unverified'])['records']['total']);
        $this->assertSame(1, $this->listing(['event_type' => 'gait', 'gait_category' => 'walking_abnormal'])['records']['total']);
        $this->assertSame(2, $this->listing(['identity' => 'known'])['records']['total']);
        $this->assertSame(2, $this->listing(['identity' => 'unknown'])['records']['total']);
        $this->assertSame([$known->id], array_column($this->listing(['camera_id' => $known->camera_id, 'room_id' => $known->cameraPeriod->room_id, 'class_code' => 'vip'])['records']['data'], 'id'));
    }

    public function test_rolling_time_filters_use_occurrence_instead_of_received_time(): void
    {
        $this->fall();
        $this->fall(changes: ['occurred_at' => $this->eventTime->subDays(2)]);
        $this->fall(changes: ['occurred_at' => $this->eventTime->subDays(10)]);
        $this->fall(changes: ['occurred_at' => $this->eventTime->subDays(40)]);
        $this->assertSame(1, $this->listing()['records']['total']);
        $this->assertSame(2, $this->listing(['range' => '7d'])['records']['total']);
        $this->assertSame(3, $this->listing(['range' => '30d'])['records']['total']);
        $row = $this->listing()['records']['data'][0];
        $this->assertNotSame($row['occurred_at'], $row['received_at']);
        $this->assertMatchesRegularExpression('/\.\d{6}Z$/', $row['occurred_at']);
    }

    public function test_custom_calendar_dates_use_jakarta_half_open_microsecond_boundaries(): void
    {
        $start = CarbonImmutable::parse('2026-03-15 00:00:00', 'Asia/Jakarta')->utc();
        $before = $this->fall(changes: ['occurred_at' => $start->subMicrosecond()]);
        $first = $this->fall(changes: ['occurred_at' => $start]);
        $last = $this->fall(changes: ['occurred_at' => $start->addDay()->subMicrosecond()]);
        $after = $this->fall(changes: ['occurred_at' => $start->addDay()]);
        $props = $this->listing(['range' => 'custom', 'date_from' => '2026-03-15', 'date_to' => '2026-03-15']);
        $this->assertSame([$last->id, $first->id], array_column($props['records']['data'], 'id'));
        $this->assertNotContains($before->id, array_column($props['records']['data'], 'id'));
        $this->assertNotContains($after->id, array_column($props['records']['data'], 'id'));
        $this->assertSame('2026-03-14T17:00:00.000000Z', $props['records']['data'][1]['occurred_at']);
        $today = CameraOperatingPeriodService::serverTime()->setTimezone('Asia/Jakarta');
        $this->listing(['range' => 'custom', 'date_from' => $today->subDays(89)->toDateString(), 'date_to' => $today->toDateString()]);
    }

    public static function literalSearches(): array
    {
        return [['%'], ['_'], ['\\'], ["' OR 1=1 --"], ['<script>']];
    }

    #[DataProvider('literalSearches')]
    public function test_search_treats_wildcards_and_injection_strings_literally(string $search): void
    {
        $this->monitorCamera->update(['name' => 'Cam '.$search.' marker']);
        $wanted = $this->fall();
        $other = $this->camera(changes: ['name' => 'Cam ordinary marker']);
        $this->fall(changes: ['camera_id' => $other->id, 'camera_period_id' => null]);
        $this->assertSame([$wanted->id], array_column($this->listing(['search' => $search])['records']['data'], 'id'));
    }

    public function test_open_incident_filters_reject_dates_terminal_status_and_foreign_parameters(): void
    {
        foreach ([['range' => '24h'], ['status' => 'resolved'], ['status' => 'false_alarm'], ['event_type' => 'fall'], ['date_from' => '2026-01-01']] as $filters) {
            $this->getJson('/monitoring/open-incidents?'.http_build_query($filters))->assertUnprocessable();
        }
        $this->fall();
        $this->fall('resolved');
        $this->assertSame(1, $this->listing(['status' => 'pending'], '/monitoring/open-incidents')['records']['total']);
    }
}

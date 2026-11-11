<?php

namespace Tests\Feature\FamilyPortal;

use App\Support\CanonicalServiceClasses;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FamilyPortalFeatureTestCase;

class FamilyCurrentServiceTest extends FamilyPortalFeatureTestCase
{
    public static function states(): array
    {
        return [['valid', 'staying'], ['no_stay', 'enrollment_only'], ['closed', 'none'], ['null_price', 'staying'],
            ['inactive_master', 'staying'], ['future_stay', 'needs_review'], ['future_enrollment', 'needs_review'],
            ['future_closed_stay', 'needs_review'], ['future_closed_enrollment', 'needs_review'],
            ['overlap_stay', 'needs_review'], ['overlap_enrollment', 'needs_review'], ['wrong_class', 'needs_review'],
            ['missing_enrollment', 'needs_review'], ['enrollment_starts_later', 'needs_review'], ['canonical_drift', 'needs_review'],
            ['vip_capacity', 'needs_review'], ['overbooked', 'needs_review']];
    }

    #[DataProvider('states')]
    public function test_current_context_is_unambiguous_and_invalid_context_is_hidden(string $case, string $expected): void
    {
        $e = $this->resident();
        $room = $this->room($case === 'vip_capacity' ? 'vip' : 'class_2');
        $en = $this->enrollment($e, $room);
        $st = $this->stay($e, $room);
        $this->assertSame('staying', $this->detail($e)['resident']['service']['state']);
        switch ($case) {
            case 'no_stay': $st->update(['ended_at' => $this->time->subDay()]);
                break;
            case 'closed': $st->update(['ended_at' => $this->time->subDay()]);
                $en->update(['ended_at' => $this->time->subDay()]);
                break;
            case 'null_price': $en->update(['monthly_price' => null]);
                break;
            case 'inactive_master': $room->update(['is_active' => false]);
                $room->serviceClass->update(['is_active' => false]);
                break;
            case 'future_stay': $st->update(['started_at' => $this->time->addHour()]);
                break;
            case 'future_enrollment': $en->update(['started_at' => $this->time->addHour()]);
                break;
            case 'future_closed_stay': $st->update(['ended_at' => $this->time->addHour()]);
                break;
            case 'future_closed_enrollment': $en->update(['ended_at' => $this->time->addHour()]);
                break;
            case 'overlap_stay': $this->stay($e, $room, ['started_at' => $this->time->subDays(3), 'ended_at' => $this->time->addHour()]);
                break;
            case 'overlap_enrollment': $this->enrollment($e, $room, ['started_at' => $this->time->subDays(4), 'ended_at' => $this->time->addHour()]);
                break;
            case 'wrong_class': $en->update(['service_class_id' => $this->room('class_3')->service_class_id]);
                break;
            case 'missing_enrollment': $en->update(['ended_at' => $this->time->subDay()]);
                break;
            case 'enrollment_starts_later': $en->update(['started_at' => $this->time->subDay()]);
                break;
            case 'canonical_drift': $room->serviceClass->update(['instant_verified_emergency' => false]);
                break;
            case 'vip_capacity': $room->update(['capacity' => 2]);
                break;
            case 'overbooked': $room->update(['capacity' => 1]);
                $this->stay($this->resident($this->account('keluarga')), $room);
                break;
        }
        $before = $this->snapshot();
        $props = $this->detail($e);
        $service = $props['resident']['service'];
        $this->assertSame($expected, $service['state']);
        if (in_array($expected, ['needs_review', 'none'])) {
            $this->assertNull($service['stay']);
            $this->assertNull($service['enrollment']);
            $this->assertNull($service['catalog_benefits']);
        }
        if ($case === 'null_price') {
            $this->assertNull($service['enrollment']['monthly_price']);
        }
        $summary = $this->get('/keluarga')->assertOk()->viewData('page')['props']['summary'];
        $this->assertSame($expected === 'needs_review' ? 1 : 0, $summary['needs_review']);
        $this->assertSame($before, $this->snapshot());
    }

    public static function classes(): array
    {
        return [['vip'], ['class_2'], ['class_3']];
    }

    #[DataProvider('classes')]
    public function test_catalog_is_final_but_does_not_grant_any_runtime_feature(string $code): void
    {
        $e = $this->resident();
        $room = $this->room($code);
        $this->enrollment($e, $room);
        $this->stay($e, $room);
        $props = $this->detail($e);
        $this->assertSame(CanonicalServiceClasses::DEFINITIONS[$code], $props['resident']['service']['catalog_benefits']);
        $this->assertTrue($props['resident']['service']['catalog_benefits']['instant_verified_emergency']);
        foreach (['allowed', 'entitled', 'paid', 'live_url', 'incident_count', 'gait_count'] as $key) {
            $this->assertStringNotContainsString('"'.$key.'"', json_encode($props));
        }
    }

    public function test_boundary_end_is_not_current_and_age_uses_the_same_snapshot_in_wib(): void
    {
        $e = $this->resident(changes: ['birth_date' => $this->time->setTimezone('Asia/Jakarta')->subYears(70)->toDateString()]);
        $room = $this->room();
        $this->enrollment($e, $room, ['ended_at' => $this->time]);
        $this->stay($e, $room, ['ended_at' => $this->time]);
        $props = $this->detail($e);
        $this->assertSame('none', $props['resident']['service']['state']);
        $this->assertSame(70, $props['resident']['age']);
        $this->assertSame($this->time->toDateString(), substr($props['as_of'], 0, 10));
    }

    public function test_database_failure_never_becomes_zero_or_exposes_sql(): void
    {
        $e = $this->resident();
        DB::statement('ALTER TABLE elderly_stays RENAME TO fixture_hidden');
        try {
            foreach (['/keluarga', '/keluarga/residents', '/keluarga/residents/'.$e->id] as $url) {
                DB::beginTransaction();
                try {
                    $this->get($url)->assertStatus(503)->assertDontSee('SQLSTATE')->assertDontSee('fixture_hidden');
                } finally {
                    DB::rollBack(); // Reset aborted SELECT ke savepoint, bukan transaksi fixture utama.
                }
            }
        } finally {
            DB::statement('ALTER TABLE fixture_hidden RENAME TO elderly_stays');
        }
    }
}

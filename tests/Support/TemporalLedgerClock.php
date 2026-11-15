<?php

namespace Tests\Support;

use App\Services\FinancialOwnershipLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Hanya binding worker test setelah ownership guard; tidak mengubah clock PostgreSQL.
final class TemporalLedgerClock extends FinancialOwnershipLedgerService
{
    public function __construct(private readonly string $instant) {}

    protected function serverInstant(?string $after = null): string
    {
        PostgresTestGuard::fromEnvironment()->assertSafe(app(), DB::connection());
        $sample = DB::selectOne("SELECT to_char(CAST(? AS timestamptz) AT TIME ZONE 'UTC',
            'YYYY-MM-DD HH24:MI:SS.US') || '+00' AS instant,
            (CAST(? AS timestamptz) IS NULL OR CAST(? AS timestamptz)>CAST(? AS timestamptz)) AS chronological",
            [$this->instant, $after, $this->instant, $after]);
        if (! $sample->chronological) {
            throw ValidationException::withMessages(['operation' => 'Clock sintetis harus lebih besar dari predecessor.']);
        }

        return $sample->instant;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\AcademicProofScanner;
use App\Services\Billing\AcademicProofScanWorker;
use Illuminate\Console\Command;

final class RunAcademicProofScan extends Command
{
    protected $signature = 'silansia:academic-proof-scan';

    protected $description = AcademicProofScanner::LABEL.' | Jalankan satu putaran scanner akademik pada runtime uji terisolasi.';

    public function handle(AcademicProofScanWorker $worker): int
    {
        $this->line(AcademicProofScanner::LABEL);
        $result = $worker->run();
        $this->line(json_encode(['is_simulation' => true, ...$result], JSON_THROW_ON_ERROR));

        return in_array($result['status'], ['accepted', 'expired', 'idle', 'busy'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }
}

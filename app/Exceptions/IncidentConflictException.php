<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class IncidentConflictException extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('Insiden atau permintaan telah berubah. Muat ulang detail sebelum mengirim tindakan baru.');
    }
}

<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class NotificationDeliveryException extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('Penyimpanan notifikasi belum berhasil. Rekonsiliasi dapat mencoba kembali.');
    }
}

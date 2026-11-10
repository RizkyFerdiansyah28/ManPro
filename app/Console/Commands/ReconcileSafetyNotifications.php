<?php

namespace App\Console\Commands;

use App\Exceptions\NotificationDeliveryException;
use App\Services\FallSafetyNotificationService;
use Illuminate\Console\Command;

class ReconcileSafetyNotifications extends Command
{
    protected $signature = 'silansia:reconcile-notifications {--limit=100 : Maksimum insiden per putaran, 1–500}';

    protected $description = 'Rekonsiliasi inbox staf untuk insiden pending committed.';

    public function handle(FallSafetyNotificationService $service): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($limit === false) {
            $this->error('Limit harus berupa bilangan bulat antara 1 dan 500.');

            return self::FAILURE;
        }
        try {
            $result = $service->reconcile($limit);
        } catch (NotificationDeliveryException) {
            $this->error('Rekonsiliasi gagal; data sumber keselamatan tetap tersimpan.');

            return self::FAILURE;
        }
        $this->line("Diproses: {$result['processed']}; disimpan: {$result['inserted']}; gagal: {$result['failed']}.");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}

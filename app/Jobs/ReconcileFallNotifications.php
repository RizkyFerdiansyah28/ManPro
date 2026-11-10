<?php

namespace App\Jobs;

use App\Services\FallSafetyNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileFallNotifications implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $timeout = 20;

    public function __construct(public readonly string $incidentId)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [5, 15, 60];
    }

    public function handle(FallSafetyNotificationService $service): void
    {
        $service->deliver($this->incidentId);
    }
}

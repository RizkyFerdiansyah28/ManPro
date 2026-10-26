<?php

namespace App\Console\Commands;

use App\Exceptions\AiGatewayException;
use App\Models\CameraOperatingPeriod;
use App\Support\AiGateway\SourceRegistry;
use Illuminate\Console\Command;
use Throwable;

class AiGatewayManifest extends Command
{
    protected $signature = 'silansia:ai-gateway-manifest {--source= : UUID sumber yang dikonfigurasi}';

    protected $description = 'Tampilkan manifest capture read-only tanpa credential, sumber video, atau data penghuni.';

    public function handle(SourceRegistry $registry): int
    {
        try {
            $sourceId = $this->option('source');
            if (! SourceRegistry::uuid($sourceId)) {
                throw AiGatewayException::unavailable();
            }
            $source = $registry->all()[$sourceId] ?? null;
            if (! $source || ! $source['enabled']) {
                throw AiGatewayException::unavailable();
            }
            $periods = CameraOperatingPeriod::whereIn('camera_id', $source['camera_ids'])->whereNull('closed_at')
                ->whereHas('camera', fn ($query) => $query->where('is_active', true))->orderBy('camera_id')->get();
            $this->line(json_encode(['source_id' => $sourceId, 'periods' => $periods->map(fn ($period) => [
                'camera_id' => $period->camera_id, 'camera_period_id' => $period->id, 'opened_at' => $period->opened_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            ])->all()], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Manifest tidak tersedia. Periksa konfigurasi dan migration tanpa menampilkan secret.');

            return self::FAILURE;
        }
    }
}

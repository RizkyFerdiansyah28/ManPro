<?php

namespace App\Http\Requests\Api\V1;

class GaitObservationRequest extends AiEventRequest
{
    protected function eventType(): string
    {
        return 'gait';
    }
}

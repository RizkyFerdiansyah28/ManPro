<?php

namespace App\Http\Requests\Api\V1;

class FallDetectionRequest extends AiEventRequest
{
    protected function eventType(): string
    {
        return 'fall';
    }
}

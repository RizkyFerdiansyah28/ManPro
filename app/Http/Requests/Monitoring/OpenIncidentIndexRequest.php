<?php

namespace App\Http\Requests\Monitoring;

use Illuminate\Validation\Rule;

class OpenIncidentIndexRequest extends MonitoringRequest
{
    public function rules(): array
    {
        return [...$this->commonRules(), 'status' => ['nullable', Rule::in(['pending', 'acknowledged'])]];
    }
}

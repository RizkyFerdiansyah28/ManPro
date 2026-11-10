<?php

namespace App\Http\Requests\Incidents;

use App\Http\Requests\Monitoring\MonitoringRequest;
use App\Models\FallIncident;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class IncidentPageRequest extends MonitoringRequest
{
    public function authorize(): bool
    {
        return Gate::allows($this->routeIs('monitoring.incidents.resident-options') ? 'handle' : 'viewAny', FallIncident::class);
    }

    public function rules(): array
    {
        $rules = ['page' => ['nullable', 'integer', 'between:1,10000']];
        if ($this->routeIs('monitoring.incidents.index')) {
            $rules += ['status' => ['nullable', Rule::in(['pending', 'acknowledged', 'resolved', 'false_alarm'])],
                'per_page' => ['nullable', 'integer', Rule::in([15, 30, 50])]];
        } elseif ($this->routeIs('monitoring.incidents.resident-options')) {
            $rules['search'] = ['nullable', 'string', 'max:100'];
        }

        return $rules;
    }
}

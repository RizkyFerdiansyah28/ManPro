<?php

namespace App\Http\Requests\Monitoring;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

class MonitoringRangeRequest extends MonitoringRequest
{
    public function rules(): array
    {
        return [
            'range' => ['nullable', Rule::in(['24h', '7d', '30d', 'custom'])],
            'date_from' => ['bail', 'required_if:range,custom', 'nullable', 'prohibited_unless:range,custom', 'date_format:Y-m-d'],
            'date_to' => ['bail', 'required_if:range,custom', 'nullable', 'prohibited_unless:range,custom', 'date_format:Y-m-d'],
        ];
    }

    protected function validateCombinations($validator): void
    {
        if ($this->input('range') !== 'custom' || $validator->errors()->hasAny(['date_from', 'date_to'])) {
            return;
        }
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('date_from'), 'Asia/Jakarta');
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('date_to'), 'Asia/Jakarta');
        if ($to->lessThan($from) || $to->greaterThan(CarbonImmutable::today('Asia/Jakarta')) || $from->diffInDays($to) > 89) {
            $validator->errors()->add('date_to', 'Rentang harus berurutan, maksimal 90 hari, dan tidak melewati hari ini WIB.');
        }
    }
}

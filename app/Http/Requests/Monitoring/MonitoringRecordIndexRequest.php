<?php

namespace App\Http\Requests\Monitoring;

use Illuminate\Validation\Rule;

class MonitoringRecordIndexRequest extends MonitoringRangeRequest
{
    public function rules(): array
    {
        return [...parent::rules(), ...$this->commonRules(),
            'event_type' => ['nullable', Rule::in(['fall', 'gait'])],
            'fall_status' => ['nullable', Rule::in(['pending', 'acknowledged', 'resolved', 'false_alarm'])],
            'gait_category' => ['nullable', Rule::in(['walking_normal', 'walking_abnormal', 'no_walking_detected'])],
            'verification' => ['nullable', Rule::in(['verified', 'unverified'])],
            'identity' => ['nullable', Rule::in(['known', 'unknown'])],
            'location' => ['nullable', Rule::in(['tracked', 'legacy', 'unavailable'])],
        ];
    }

    protected function validateCombinations($validator): void
    {
        parent::validateCombinations($validator);
        foreach (['fall_status' => 'fall', 'verification' => 'fall', 'gait_category' => 'gait'] as $key => $type) {
            if ($this->filled($key) && $this->input('event_type') !== $type) {
                $validator->errors()->add($key, 'Filter rincian harus sesuai jenis event yang dipilih.');
            }
        }
    }
}

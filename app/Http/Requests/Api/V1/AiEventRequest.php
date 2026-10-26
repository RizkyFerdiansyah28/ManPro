<?php

namespace App\Http\Requests\Api\V1;

use App\Exceptions\AiGatewayException;
use App\Support\AiGateway\EventPayload;
use App\Support\AiGateway\SourceRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class AiEventRequest extends FormRequest
{
    abstract protected function eventType(): string;

    public function authorize(): bool
    {
        if (! is_array($this->attributes->get('ai_source'))) {
            throw new AiGatewayException(401, 'unauthenticated_source', 'Autentikasi sumber tidak sah.');
        }

        return true;
    }

    public function validationData(): array
    {
        $data = $this->attributes->get('ai_payload', []);
        $allowed = ['contract_version', 'external_event_id', 'event_type', 'camera_id', 'camera_period_id', 'occurred_at', 'confidence'];
        if ($this->eventType() === 'gait') {
            $allowed[] = 'category';
        }
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new AiGatewayException(422, 'invalid_payload', 'Payload memuat kolom yang tidak diizinkan.');
        }
        if (isset($data['confidence']) && is_string($data['confidence']) && trim($data['confidence']) === '') {
            throw new AiGatewayException(422, 'invalid_payload', 'Confidence harus NULL atau desimal dengan format kontrak.');
        }

        return $data; // JSON asli, sebelum TrimStrings/ConvertEmptyStringsToNull.
    }

    public function rules(): array
    {
        $uuid = ['bail', 'required', 'string', 'max:36', function ($attribute, $value, $fail) {
            if (! SourceRegistry::uuid($value)) {
                $fail('Gunakan UUID lowercase yang valid.');
            }
        }];

        return ['contract_version' => ['bail', 'required', 'string', Rule::in(['1'])],
            'external_event_id' => $uuid, 'event_type' => ['bail', 'required', 'string', Rule::in([$this->eventType()])],
            'camera_id' => $uuid, 'camera_period_id' => $uuid,
            'occurred_at' => ['bail', 'required', 'string', 'max:40', function ($attribute, $value, $fail) {
                EventPayload::timestamp($value);
            }],
            'confidence' => ['bail', 'nullable', 'string', 'regex:/\A(?:0(?:\.[0-9]{1,5})?|1(?:\.0{1,5})?)\z/'],
            ...($this->eventType() === 'gait' ? ['category' => ['bail', 'required', 'string', Rule::in(['walking_normal', 'walking_abnormal', 'no_walking_detected'])]] : [])];
    }

    public function messages(): array
    {
        return ['required' => 'Kolom wajib diisi.', 'string' => 'Kolom harus berupa teks dengan format kontrak.',
            'in' => 'Nilai tidak didukung pada kontrak ini.', 'max' => 'Kolom melebihi batas panjang.',
            'regex' => 'Gunakan desimal 0 sampai 1 dengan maksimal lima digit fraksi.'];
    }

    protected function passedValidation(): void
    {
        if (! in_array($this->validated('camera_id'), $this->attributes->get('ai_source')['camera_ids'], true)) {
            throw new AiGatewayException(403, 'source_scope_denied', 'Sumber tidak diizinkan untuk kamera ini.');
        }
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new AiGatewayException(422, 'invalid_payload', 'Payload event tidak valid.', $validator->errors()->toArray());
    }
}

<?php

namespace App\Http\Requests\Incidents;

use App\Models\FallIncident;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class IncidentActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('handle', FallIncident::class);
    }

    public function rules(): array
    {
        $action = $this->route('action');
        $rules = ['request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
            'confirm' => ['required', 'accepted'], 'notes' => [$action === 'acknowledge' ? 'nullable' : 'required', 'string', 'max:2000']];
        if ($action === 'verify') {
            $rules += ['identity_mode' => ['required', Rule::in(['known', 'unknown'])],
                'elderly_id' => ['bail', 'nullable', 'required_if:identity_mode,known', 'prohibited_unless:identity_mode,known', 'uuid']];
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token']) !== []) {
                $validator->errors()->add('incident', 'Ada kolom tindakan yang tidak diizinkan.');
            }
        });
    }

    public function messages(): array
    {
        return ['required' => 'Kolom tindakan wajib diisi.', 'required_if' => 'Pilih lansia yang telah diidentifikasi.',
            'uuid' => 'ID tindakan tidak valid.', 'regex' => 'Versi insiden tidak valid.',
            'accepted' => 'Konfirmasikan tindakan terlebih dahulu.', 'max' => 'Catatan maksimal 2.000 karakter.',
            'string' => 'Catatan harus berupa teks.', 'in' => 'Pilihan identitas tidak valid.',
            'prohibited_unless' => 'Identitas belum diketahui tidak boleh menyertakan ID lansia.'];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException($this->expectsJson()
            ? response()->json(['message' => 'Data tindakan tidak valid.', 'errors' => $validator->errors()], 422)
            : redirect()->route('monitoring.incidents.show', ['incident' => $this->route('incident')], 303)->withErrors($validator));
    }
}

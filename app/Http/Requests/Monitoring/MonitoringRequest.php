<?php

namespace App\Http\Requests\Monitoring;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

abstract class MonitoringRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewMonitoring');
    }

    protected function commonRules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'camera_id' => ['bail', 'nullable', 'uuid', 'exists:cameras,id'],
            'room_id' => ['bail', 'nullable', 'uuid', 'exists:rooms,id'],
            'class_code' => ['nullable', Rule::in(['vip', 'class_2', 'class_3'])],
            'page' => ['nullable', 'integer', 'between:1,10000'],
            'per_page' => ['nullable', 'integer', Rule::in([15, 30, 50])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('filters', 'Ada filter yang tidak diizinkan.');
            }
            $this->validateCombinations($validator);
        });
    }

    protected function validateCombinations($validator): void {}

    public function messages(): array
    {
        return [
            'string' => 'Filter harus berupa teks.', 'max' => 'Pencarian maksimal 100 karakter.',
            'in' => 'Pilihan filter tidak valid.', 'integer' => 'Halaman harus berupa bilangan bulat.',
            'between' => 'Halaman harus antara 1 dan 10000.', 'uuid' => 'ID filter tidak valid.',
            'exists' => 'Data filter tidak tersedia.', 'date_format' => 'Tanggal harus valid dengan format YYYY-MM-DD.',
            'required_if' => 'Tanggal awal dan akhir wajib untuk rentang khusus.',
            'prohibited_unless' => 'Tanggal hanya boleh digunakan pada rentang khusus.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        // Jangan flash payload/parameter asing ke session atau memantulkannya ke URL.
        $response = $this->expectsJson()
            ? response()->json(['message' => 'Filter monitoring tidak valid.', 'errors' => $validator->errors()], 422)
            : redirect($this->url())->withErrors($validator);

        throw new HttpResponseException($response);
    }
}

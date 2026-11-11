<?php

namespace App\Http\Requests\Family;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class FamilyPortalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewFamilyPortal');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('search'))) {
            $this->merge(['search' => trim($this->input('search'))]);
        }
    }

    public function rules(): array
    {
        return ['family_id' => ['missing'], ...($this->routeIs('keluarga.residents.index') ? [
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'page' => ['nullable', 'integer', 'between:1,10000'], 'per_page' => ['nullable', 'integer', Rule::in([15, 30, 50])],
        ] : [])];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('filters', 'Ada filter yang tidak diizinkan.');
            }
        });
    }

    public function messages(): array
    {
        return ['family_id.missing' => 'Filter keluarga tidak diizinkan.', 'string' => 'Pencarian harus berupa teks.',
            'max' => 'Pencarian maksimal 100 karakter.', 'in' => 'Pilihan filter tidak valid.',
            'integer' => 'Halaman harus berupa bilangan bulat.', 'between' => 'Halaman harus antara 1 dan 10000.'];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException($this->expectsJson()
            ? response()->json(['message' => 'Filter portal keluarga tidak valid.', 'errors' => $validator->errors()], 422)
            : redirect($this->url(), 303)->withErrors($validator));
    }
}

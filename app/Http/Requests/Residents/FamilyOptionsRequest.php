<?php

namespace App\Http\Requests\Residents;

use App\Models\Elderly;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class FamilyOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('selectFamily', Elderly::class);
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'],
            'role' => ['missing'], 'is_active' => ['missing']];
    }

    public function messages(): array
    {
        return ['string' => 'Pencarian harus berupa teks.', 'max' => 'Pencarian maksimal 100 karakter.',
            'integer' => 'Halaman harus berupa angka.', 'min' => 'Halaman tidak valid.',
            'missing' => 'Filter :attribute tidak diizinkan.'];
    }
}

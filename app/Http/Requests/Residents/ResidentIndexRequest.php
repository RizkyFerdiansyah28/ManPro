<?php

namespace App\Http\Requests\Residents;

use App\Models\Elderly;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ResidentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Elderly::class);
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'page' => ['nullable', 'integer', 'min:1'], 'family_id' => ['missing']];
    }

    public function messages(): array
    {
        return ['string' => 'Pencarian harus berupa teks.', 'max' => 'Pencarian maksimal 100 karakter.',
            'in' => 'Status tidak valid.', 'integer' => 'Halaman harus berupa angka.', 'min' => 'Halaman tidak valid.',
            'missing' => 'Filter keluarga tidak diizinkan.'];
    }
}

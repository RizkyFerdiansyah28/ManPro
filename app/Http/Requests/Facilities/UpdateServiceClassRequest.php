<?php

namespace App\Http\Requests\Facilities;

use App\Rules\MonthlyPrice;
use App\Support\CanonicalServiceClasses;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateServiceClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('serviceClass'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        $protected = array_keys(CanonicalServiceClasses::DEFINITIONS['vip']);

        return ['name' => ['required', 'string', 'max:255'], 'monthly_price' => ['present', 'nullable', new MonthlyPrice],
            ...array_fill_keys(['id', 'code', 'is_active', 'staff_ai_monitoring', 'approved_benefits', ...$protected], ['missing'])];
    }

    public function messages(): array
    {
        return ['name.required' => 'Nama kelas wajib diisi.', 'name.string' => 'Nama kelas harus berupa teks.',
            'name.max' => 'Nama kelas maksimal 255 karakter.', 'monthly_price.present' => 'Isi tarif atau kosongkan secara eksplisit.',
            'missing' => 'Kolom :attribute tidak boleh diubah melalui form ini.'];
    }
}

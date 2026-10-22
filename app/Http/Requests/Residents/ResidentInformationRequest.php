<?php

namespace App\Http\Requests\Residents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

abstract class ResidentInformationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
        foreach (['family_id', 'current_family_id'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => Str::lower(trim($this->input($field)))]);
            }
        }
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'],
            'birth_date' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:0001-01-01', 'before_or_equal:today'],
            'id' => ['missing'], 'age' => ['missing'], 'is_active' => ['missing']];
    }

    public function messages(): array
    {
        return ['name.required' => 'Nama lansia wajib diisi.', 'name.string' => 'Nama harus berupa teks.',
            'name.max' => 'Nama maksimal 255 karakter.', 'birth_date.required' => 'Tanggal lahir wajib diisi.',
            'birth_date.date_format' => 'Gunakan tanggal lahir yang valid dengan format tahun-bulan-tanggal.',
            'birth_date.after_or_equal' => 'Tanggal lahir harus menggunakan tahun kalender yang valid.',
            'birth_date.before_or_equal' => 'Tanggal lahir tidak boleh di masa depan.',
            'family_id.required' => 'Pilih akun keluarga.', 'family_id.uuid' => 'ID keluarga tidak valid.',
            'current_family_id.required' => 'Referensi keluarga saat ini wajib disertakan.',
            'current_family_id.uuid' => 'Referensi keluarga saat ini tidak valid.',
            'confirm_reassignment.accepted' => 'Konfirmasikan perubahan keluarga terlebih dahulu.',
            'missing' => 'Kolom :attribute tidak diizinkan pada tindakan ini.'];
    }
}

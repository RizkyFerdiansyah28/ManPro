<?php

namespace App\Http\Requests\Operations;

use Illuminate\Foundation\Http\FormRequest;

abstract class OperationRequest extends FormRequest
{
    protected function protectedFields(array $extra = []): array
    {
        return array_fill_keys(['id', 'elderly_id', 'monthly_price', 'started_at', 'ended_at', 'is_active', ...$extra], ['missing']);
    }

    public function messages(): array
    {
        return ['required' => 'Kolom :attribute wajib diisi.', 'uuid' => 'ID :attribute tidak valid.',
            'exists' => 'Pilihan :attribute tidak ditemukan.', 'missing' => 'Kolom :attribute tidak diizinkan pada tindakan ini.',
            'accepted' => 'Konfirmasikan tindakan ini terlebih dahulu.', 'integer' => 'Halaman harus berupa bilangan bulat.',
            'min' => 'Halaman tidak valid.', 'string' => 'Pencarian harus berupa teks.', 'max' => 'Pencarian maksimal 100 karakter.'];
    }
}

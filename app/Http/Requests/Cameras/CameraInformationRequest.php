<?php

namespace App\Http\Requests\Cameras;

use App\Support\CameraSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class CameraInformationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
        foreach (['room_id', 'current_room_id'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtolower(trim($this->input($field)))]);
            }
        }
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'room_id' => ['bail', 'required', 'uuid', 'exists:rooms,id'],
            'source_type' => ['required', Rule::in(CameraSource::TYPES)], 'source_reference' => ['nullable', 'string', 'max:4096'],
            ...array_fill_keys(['id', 'is_active', 'created_at', 'updated_at', 'service_class_id', 'ai_detections', 'room'], ['missing'])];
    }

    public function messages(): array
    {
        return ['required' => 'Kolom :attribute wajib diisi.', 'string' => 'Kolom :attribute harus berupa teks.',
            'name.max' => 'Nama kamera maksimal 255 karakter.', 'source_reference.max' => 'Sumber privat terlalu panjang.',
            'uuid' => 'ID kamar tidak valid.', 'exists' => 'Kamar tidak ditemukan.', 'source_type.in' => 'Jenis sumber tidak didukung.',
            'source_action.in' => 'Tindakan sumber privat tidak valid.', 'accepted' => 'Konfirmasikan tindakan ini terlebih dahulu.',
            'boolean' => 'Konfirmasi harus bernilai benar atau salah.', 'version.regex' => 'Versi konfigurasi tidak valid. Muat ulang halaman.',
            'missing' => 'Kolom :attribute tidak diizinkan pada tindakan ini.'];
    }
}

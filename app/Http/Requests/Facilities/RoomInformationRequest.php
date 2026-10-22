<?php

namespace App\Http\Requests\Facilities;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class RoomInformationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['code', 'name', 'service_class_id', 'current_service_class_id'] as $field) {
            if (is_string($this->input($field))) {
                $text = trim($this->input($field));
                $this->merge([$field => str_ends_with($field, '_id') ? strtolower($text) : $text]);
            }
        }
    }

    public function rules(): array
    {
        return ['code' => ['bail', 'required', 'string', 'max:40', Rule::unique('rooms', 'code')->ignore($this->route('room'))],
            'name' => ['required', 'string', 'max:255'],
            'service_class_id' => ['bail', 'required', 'uuid', Rule::exists('service_classes', 'id')],
            // Batas teknis kolom PostgreSQL integer, bukan batas kapasitas bisnis.
            'capacity' => ['bail', 'required', 'integer', 'min:1', 'max:2147483647'],
            'id' => ['missing'], 'is_active' => ['missing'], 'room_type' => ['missing'], 'active_occupancy' => ['missing']];
    }

    public function messages(): array
    {
        return ['code.required' => 'Kode kamar wajib diisi.', 'code.string' => 'Kode kamar harus berupa teks.',
            'code.max' => 'Kode kamar maksimal 40 karakter.', 'code.unique' => 'Kode kamar sudah digunakan.',
            'name.required' => 'Nama kamar wajib diisi.', 'name.string' => 'Nama kamar harus berupa teks.',
            'name.max' => 'Nama kamar maksimal 255 karakter.', 'service_class_id.required' => 'Pilih kelas layanan.',
            'service_class_id.uuid' => 'ID kelas tidak valid.', 'service_class_id.exists' => 'Kelas layanan tidak ditemukan.',
            'capacity.required' => 'Kapasitas wajib diisi.', 'capacity.integer' => 'Kapasitas harus berupa bilangan bulat.',
            'capacity.min' => 'Kapasitas minimal 1.', 'capacity.max' => 'Kapasitas melewati batas tipe integer database.',
            'current_service_class_id.required' => 'Referensi kelas saat ini wajib disertakan.',
            'current_service_class_id.uuid' => 'Referensi kelas saat ini tidak valid.',
            'confirm_class_change.accepted' => 'Konfirmasikan perubahan kelas kamar.',
            'confirm_class_change.boolean' => 'Konfirmasi perubahan kelas harus bernilai benar atau salah.',
            'missing' => 'Kolom :attribute tidak diizinkan pada tindakan ini.'];
    }
}

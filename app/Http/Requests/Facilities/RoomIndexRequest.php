<?php

namespace App\Http\Requests\Facilities;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RoomIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Room::class);
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'service_class_id' => ['bail', 'nullable', 'uuid', Rule::exists('service_classes', 'id')],
            'page' => ['nullable', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['search.string' => 'Pencarian harus berupa teks.', 'search.max' => 'Pencarian maksimal 100 karakter.',
            'status.in' => 'Status kamar tidak valid.', 'service_class_id.uuid' => 'ID kelas tidak valid.',
            'service_class_id.exists' => 'Kelas layanan tidak ditemukan.', 'page.integer' => 'Halaman harus berupa angka.',
            'page.min' => 'Halaman tidak valid.'];
    }
}

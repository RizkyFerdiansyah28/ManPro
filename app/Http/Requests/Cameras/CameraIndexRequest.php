<?php

namespace App\Http\Requests\Cameras;

use App\Models\Camera;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CameraIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Camera::class);
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'room_id' => ['bail', 'nullable', 'uuid', 'exists:rooms,id'], 'page' => ['nullable', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['search.string' => 'Pencarian harus berupa teks.', 'search.max' => 'Pencarian maksimal 100 karakter.',
            'status.in' => 'Status tidak valid.', 'room_id.uuid' => 'ID kamar tidak valid.', 'room_id.exists' => 'Kamar tidak ditemukan.',
            'page.integer' => 'Halaman harus berupa bilangan bulat.', 'page.min' => 'Halaman tidak valid.'];
    }
}

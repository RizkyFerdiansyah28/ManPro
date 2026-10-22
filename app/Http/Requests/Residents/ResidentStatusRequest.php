<?php

namespace App\Http\Requests\Residents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ResidentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateStatus', $this->route('elderly'));
    }

    public function rules(): array
    {
        return ['confirm_status_change' => ['accepted'], 'is_active' => ['missing'], 'family_id' => ['missing'],
            'id' => ['missing'], 'name' => ['missing'], 'birth_date' => ['missing']];
    }

    public function messages(): array
    {
        return ['confirm_status_change.accepted' => 'Konfirmasikan perubahan status terlebih dahulu.',
            'missing' => 'Kolom :attribute tidak diizinkan pada perubahan status.'];
    }
}

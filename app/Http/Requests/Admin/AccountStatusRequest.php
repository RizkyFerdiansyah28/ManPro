<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class AccountStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateStatus', $this->route('user'));
    }

    public function rules(): array
    {
        return ['role' => ['missing'], 'is_active' => ['missing'], 'id' => ['missing'], 'password' => ['missing']];
    }

    public function messages(): array
    {
        return ['missing' => 'Kolom :attribute tidak diizinkan.'];
    }
}

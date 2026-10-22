<?php

namespace App\Http\Requests\Facilities;

use App\Support\CanonicalServiceClasses;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class FacilityStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateStatus', $this->route('serviceClass') ?? $this->route('room'));
    }

    public function rules(): array
    {
        return ['confirm_status_change' => ['accepted'],
            ...array_fill_keys(['id', 'code', 'name', 'monthly_price', 'service_class_id', 'capacity', 'is_active', 'staff_ai_monitoring', 'approved_benefits',
                ...array_keys(CanonicalServiceClasses::DEFINITIONS['vip'])], ['missing'])];
    }

    public function messages(): array
    {
        return ['confirm_status_change.accepted' => 'Konfirmasikan perubahan status terlebih dahulu.',
            'missing' => 'Kolom :attribute tidak diizinkan pada perubahan status.'];
    }
}

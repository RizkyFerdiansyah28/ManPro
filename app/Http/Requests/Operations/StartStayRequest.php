<?php

namespace App\Http\Requests\Operations;

use App\Models\ElderlyStay;
use Illuminate\Support\Facades\Gate;

class StartStayRequest extends OperationRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', [ElderlyStay::class, $this->route('elderly')]);
    }

    public function rules(): array
    {
        return ['room_id' => ['bail', 'required', 'uuid', 'exists:rooms,id'],
            'enrollment_id' => ['bail', 'required', 'uuid', 'exists:service_enrollments,id'], 'confirm_start' => ['accepted'],
            ...$this->protectedFields(['service_class_id'])];
    }
}

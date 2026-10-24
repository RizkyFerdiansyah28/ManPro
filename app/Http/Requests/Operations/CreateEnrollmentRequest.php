<?php

namespace App\Http\Requests\Operations;

use App\Models\ServiceEnrollment;
use Illuminate\Support\Facades\Gate;

class CreateEnrollmentRequest extends OperationRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', [ServiceEnrollment::class, $this->route('elderly')]);
    }

    public function rules(): array
    {
        return ['service_class_id' => ['bail', 'required', 'uuid', 'exists:service_classes,id'], 'confirm_start' => ['accepted'],
            ...$this->protectedFields(['room_id', 'enrollment_id'])];
    }
}

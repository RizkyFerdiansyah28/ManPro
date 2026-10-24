<?php

namespace App\Http\Requests\Operations;

use Illuminate\Support\Facades\Gate;

class CloseOperationRequest extends OperationRequest
{
    public function authorize(): bool
    {
        return Gate::allows('close', [$this->route('stay') ?? $this->route('enrollment'), $this->route('elderly')]);
    }

    public function rules(): array
    {
        return ['confirm_end' => ['accepted'], ...$this->protectedFields(['room_id', 'service_class_id', 'enrollment_id'])];
    }
}

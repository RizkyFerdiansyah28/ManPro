<?php

namespace App\Http\Requests\Facilities;

use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateRoomRequest extends RoomInformationRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('room'));
    }

    public function rules(): array
    {
        return [...parent::rules(), 'current_service_class_id' => ['bail', 'required', 'uuid'],
            'confirm_class_change' => [Rule::when($this->input('service_class_id') !== $this->route('room')->service_class_id,
                ['accepted'], ['nullable', 'boolean'])]];
    }
}

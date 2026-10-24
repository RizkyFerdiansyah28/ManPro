<?php

namespace App\Http\Requests\Cameras;

use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateCameraRequest extends CameraInformationRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('camera'));
    }

    public function rules(): array
    {
        return [...parent::rules(), 'current_room_id' => ['required', 'uuid'], 'version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'source_action' => ['required', Rule::in(['keep', 'replace', 'clear'])],
            'confirm_reassignment' => [Rule::when($this->input('room_id') !== $this->route('camera')->room_id, ['accepted'], ['nullable', 'boolean'])]];
    }
}

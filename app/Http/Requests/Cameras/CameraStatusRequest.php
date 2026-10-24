<?php

namespace App\Http\Requests\Cameras;

use Illuminate\Support\Facades\Gate;

class CameraStatusRequest extends CameraInformationRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateStatus', $this->route('camera'));
    }

    public function rules(): array
    {
        return ['version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'], 'confirm_status_change' => ['accepted'],
            ...array_fill_keys(['id', 'name', 'room_id', 'current_room_id', 'is_active', 'source_type', 'source_reference', 'source_action', 'created_at', 'updated_at', 'service_class_id', 'ai_detections', 'room'], ['missing'])];
    }
}

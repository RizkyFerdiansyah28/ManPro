<?php

namespace App\Http\Requests\Cameras;

use App\Models\Camera;
use Illuminate\Support\Facades\Gate;

class StoreCameraRequest extends CameraInformationRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Camera::class);
    }

    public function rules(): array
    {
        return [...parent::rules(), 'confirm_create' => ['accepted'], 'source_action' => ['missing'], 'version' => ['missing'], 'current_room_id' => ['missing']];
    }
}

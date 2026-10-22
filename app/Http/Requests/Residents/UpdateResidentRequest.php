<?php

namespace App\Http\Requests\Residents;

use Illuminate\Support\Facades\Gate;

class UpdateResidentRequest extends ResidentInformationRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('elderly'));
    }

    public function rules(): array
    {
        return [...parent::rules(), 'family_id' => ['missing']];
    }
}

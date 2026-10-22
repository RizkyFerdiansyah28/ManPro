<?php

namespace App\Http\Requests\Residents;

use App\Rules\EligibleFamily;
use Illuminate\Support\Facades\Gate;

class ReassignFamilyRequest extends ResidentInformationRequest
{
    public function authorize(): bool
    {
        return Gate::allows('reassignFamily', $this->route('elderly'));
    }

    public function rules(): array
    {
        return ['family_id' => ['bail', 'required', 'uuid', new EligibleFamily],
            'current_family_id' => ['bail', 'required', 'uuid'], 'confirm_reassignment' => ['accepted'],
            'id' => ['missing'], 'name' => ['missing'], 'birth_date' => ['missing'], 'is_active' => ['missing']];
    }
}

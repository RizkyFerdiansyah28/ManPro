<?php

namespace App\Http\Requests\Residents;

use App\Models\Elderly;
use App\Rules\EligibleFamily;
use Illuminate\Support\Facades\Gate;

class StoreResidentRequest extends ResidentInformationRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Elderly::class);
    }

    public function rules(): array
    {
        return [...parent::rules(), 'family_id' => ['bail', 'required', 'uuid', new EligibleFamily]];
    }
}

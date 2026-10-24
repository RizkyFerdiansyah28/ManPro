<?php

namespace App\Http\Requests\Operations;

use App\Models\ElderlyStay;
use Illuminate\Support\Facades\Gate;

class OperationIndexRequest extends OperationRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', [ElderlyStay::class, $this->route('elderly')]);
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'],
            'stays_page' => ['nullable', 'integer', 'min:1'], 'enrollments_page' => ['nullable', 'integer', 'min:1']];
    }
}

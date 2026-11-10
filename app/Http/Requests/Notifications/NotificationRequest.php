<?php

namespace App\Http\Requests\Notifications;

use App\Models\Notification;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class NotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Notification::class);
    }

    public function rules(): array
    {
        if ($this->routeIs('notifications.summary')) {
            return [];
        }
        $filters = ['state' => ['nullable', Rule::in(['all', 'unread', 'read'])],
            'page' => ['nullable', 'integer', 'between:1,10000'], 'per_page' => ['nullable', 'integer', Rule::in([15, 30, 50])]];

        return [...$filters, ...($this->routeIs('notifications.read-all') ? ['confirm' => ['required', 'accepted']] : [])];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token']) !== []) {
                $validator->errors()->add('notification', 'Ada kolom notifikasi yang tidak diizinkan.');
            }
        });
    }

    public function messages(): array
    {
        return ['in' => 'Pilihan filter tidak valid.', 'integer' => 'Halaman harus berupa bilangan bulat.',
            'between' => 'Halaman harus antara 1 dan 10000.', 'required' => 'Konfirmasi tindakan wajib diisi.',
            'accepted' => 'Konfirmasikan penandaan seluruh notifikasi terlebih dahulu.'];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException($this->expectsJson()
            ? response()->json(['message' => 'Permintaan notifikasi tidak valid.', 'errors' => $validator->errors()], 422)
            : to_route('notifications.index', status: 303)->withErrors($validator));
    }
}

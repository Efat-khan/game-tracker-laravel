<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CafeRequest extends FormRequest
{
    public function rules(): array
    {
        if ($this->isMethod('POST')) {
            return [
                'name' => ['required', 'string', 'min:1', 'max:150'],
                'admin_email' => [
                    'required', 'string', 'min:3', 'max:150',
                    Rule::unique('admin_users', 'email'),
                ],
                'admin_password' => ['required', 'string', 'min:6'],
                'contact_email' => ['sometimes', 'nullable', 'string', 'max:150'],
            ];
        }

        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:150'],
            'contact_email' => ['sometimes', 'nullable', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

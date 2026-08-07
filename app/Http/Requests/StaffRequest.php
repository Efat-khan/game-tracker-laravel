<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'email' => [
                $creating ? 'required' : 'sometimes',
                'string', 'min:3', 'max:150',
                // Unique platform-wide, so a sign-in is never ambiguous (§4.5).
                Rule::unique('admin_users', 'email')->ignore($this->route('id')),
            ],
            'password' => [$creating ? 'required' : 'sometimes', 'string', 'min:6'],
            'role' => [$creating ? 'required' : 'sometimes', 'string', 'in:admin,staff'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckinRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:150'],
            'phone_or_id' => ['required', 'string', 'min:1', 'max:100'],
            // The per-station max_controllers ceiling is a 400, not a 422, and
            // is enforced in SessionService where the station is known.
            'controllers' => ['sometimes', 'integer', 'min:1', 'max:8'],
            'planned_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:1440'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StationRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:1', 'max:100'],
            'type' => [$required, 'string', 'min:1', 'max:50'],
            // Strictly greater than zero: a free station is a bug, not a price.
            'hourly_rate' => [$required, 'numeric', 'gt:0'],
            'extra_controller_rate' => ['sometimes', 'numeric', 'min:0'],
            'max_controllers' => ['sometimes', 'integer', 'min:1', 'max:8'],
            'is_active' => ['sometimes', 'boolean'],
            'maintenance' => ['sometimes', 'boolean'],
        ];
    }
}

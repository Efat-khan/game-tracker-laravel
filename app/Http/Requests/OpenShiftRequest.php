<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenShiftRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'opening_float' => ['sometimes', 'numeric', 'min:0'],
            'note' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }
}

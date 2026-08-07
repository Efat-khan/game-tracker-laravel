<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Voids and other actions that must be explained. */
class ReasonRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ];
    }
}

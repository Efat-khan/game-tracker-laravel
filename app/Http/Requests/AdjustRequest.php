<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Manual balance correction. Admin only. Signed: it can take credit away. */
class AdjustRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ];
    }
}

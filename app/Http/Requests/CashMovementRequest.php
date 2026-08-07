<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CashMovementRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', 'in:in,out'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ];
    }
}

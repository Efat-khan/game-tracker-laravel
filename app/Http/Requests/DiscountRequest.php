<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DiscountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // A flat amount OR a percent, plus a reason worth reading.
            'amount' => ['required_without:percent', 'nullable', 'numeric', 'min:0'],
            'percent' => ['required_without:amount', 'nullable', 'numeric', 'min:0', 'max:100'],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ];
    }
}

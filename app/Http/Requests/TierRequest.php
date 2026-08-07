<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TierRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:1', 'max:50'],
            'min_spend' => ['sometimes', 'numeric', 'min:0'],
            'discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

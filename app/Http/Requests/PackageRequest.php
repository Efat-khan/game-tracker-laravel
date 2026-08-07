<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PackageRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:1', 'max:100'],
            // Both strictly positive: a package that costs nothing or grants
            // nothing is not a deal.
            'price' => [$required, 'numeric', 'gt:0'],
            'credit' => [$required, 'numeric', 'gt:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

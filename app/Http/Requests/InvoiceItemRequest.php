<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceItemRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'product_id' => ['sometimes', 'nullable', 'integer'],
            'description' => ['required_without:product_id', 'string', 'min:1', 'max:150'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'unit_price' => ['required_without:product_id', 'numeric', 'min:0'],
            'unit_cost' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}

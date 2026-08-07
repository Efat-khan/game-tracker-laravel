<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TopupRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // A custom amount, or a package whose credit may exceed its price.
            'amount' => ['required_without:package_id', 'nullable', 'numeric', 'gt:0'],
            'package_id' => ['required_without:amount', 'nullable', 'integer'],
            // wallet is never accepted from a client (§6.2).
            'payment_method' => ['sometimes', 'string', 'in:cash,phone_payment'],
            'note' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }
}

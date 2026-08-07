<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'payment_status' => ['sometimes', 'string', 'in:paid,unpaid'],
            // wallet is set by the system on pay-wallet and must never be
            // accepted from a client (§6.2).
            'payment_method' => ['sometimes', 'string', 'in:cash,phone_payment'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'billing_round_minutes' => ['sometimes', 'integer', 'min:1', 'max:240'],
            'round_amount_to' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'open_hour' => ['sometimes', 'integer', 'min:0', 'max:23'],
            // close_hour must also exceed open_hour; a pair that does not is
            // read back as the 10/23 fallback (SettingsService::tradingHours).
            'close_hour' => ['sometimes', 'integer', 'min:1', 'max:24'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookingRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'station_id' => [$required, 'integer'],
            'customer_name' => [$required, 'string', 'min:1', 'max:150'],
            'customer_phone' => [$required, 'string', 'min:1', 'max:100'],
            'starts_at' => [$required, 'date'],
            'ends_at' => [$required, 'date', 'after:starts_at'],
            'controllers' => ['sometimes', 'integer', 'min:1', 'max:8'],
            'note' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }
}

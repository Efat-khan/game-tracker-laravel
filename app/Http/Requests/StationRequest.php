<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StationRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:1', 'max:100'],
            'type' => [$required, 'string', 'min:1', 'max:50'],
            // Strictly greater than zero: a free station is a bug, not a price.
            'hourly_rate' => [$required, 'numeric', 'gt:0'],
            'max_controllers' => ['sometimes', 'integer', 'min:1', 'max:8'],
            /*
             * The price list, as {controllers: rate}. Optional: leave it out
             * and every count is priced at hourly_rate, which is what a station
             * with one controller wants anyway.
             *
             * A count with no rate is refused rather than defaulted — a station
             * that silently prices four players the same as one is a pricing
             * bug nobody notices until the end of the month.
             */
            'rates' => ['sometimes', 'array'],
            'rates.*' => ['numeric', 'gt:0'],
            'is_active' => ['sometimes', 'boolean'],
            'maintenance' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $rates = $this->input('rates');

            if (! is_array($rates) || $rates === []) {
                return;
            }

            $max = (int) $this->input('max_controllers', 4);

            foreach (array_keys($rates) as $key) {
                if (! ctype_digit((string) $key) || (int) $key < 1 || (int) $key > $max) {
                    $validator->errors()->add(
                        'rates',
                        "Rates must be keyed on a controller count between 1 and {$max}.",
                    );

                    return;
                }
            }

            for ($n = 1; $n <= $max; $n++) {
                if (! array_key_exists($n, $rates) && ! array_key_exists((string) $n, $rates)) {
                    $validator->errors()->add('rates', "A rate is missing for {$n} controller(s).");

                    return;
                }
            }
        });
    }
}

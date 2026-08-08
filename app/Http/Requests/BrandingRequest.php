<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BrandingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * jpeg, png and webp only — and note SVG is absent on purpose.
             *
             * An SVG is a document, not a picture: it can carry <script>, and
             * the browser runs it under our own origin when we serve it back.
             * `mimes` checks the sniffed type rather than the filename, so
             * "logo.png" holding a PHP script is rejected here.
             */
            'image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.mimes' => 'The image must be a JPEG, PNG or WebP file.',
            'image.max' => 'The image may not be larger than 5 MB.',
        ];
    }
}

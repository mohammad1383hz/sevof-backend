<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTicketReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message'  => ['nullable', 'required_without:images', 'string', 'max:5000'],
            'images'   => ['nullable', 'array', 'max:5'],
            'images.*' => ['file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'message.required_without' => 'Write a message or attach at least one image.',
            'images.max'                => 'You can attach up to 5 images.',
            'images.*.image'            => 'Each attachment must be an image.',
            'images.*.mimes'            => 'Images must be jpg, jpeg, png or webp.',
            'images.*.max'              => 'Each image must be 4 MB or smaller.',
        ];
    }
}

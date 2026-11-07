<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyNationalIdRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'national_id' => [
                'required', 'digits:10',
                function ($attribute, $value, $fail) {
                    if (! $this->validNationalId($value)) {
                        $fail('Invalid national ID.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'national_id.required' => 'National ID is required.',
            'national_id.digits'   => 'National ID must be 10 digits.',
        ];
    }

    private function validNationalId(string $code): bool
    {
        if (strlen($code) !== 10 || preg_match('/^(\d)\1{9}$/', $code)) return false;

        $sum = 0;
        for ($i = 0; $i < 9; $i++) $sum += (int) $code[$i] * (10 - $i);

        $r = $sum % 11;
        $c = (int) $code[9];
        return ($r < 2 && $c === $r) || ($r >= 2 && $c === 11 - $r);
    }
}

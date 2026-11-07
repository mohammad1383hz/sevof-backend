<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreApplicantInfoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'national_id'         => ['required', 'digits:10', 'exists:applicants,national_id'],
            'first_name'          => ['required', 'string', 'max:100'],
            'last_name'           => ['required', 'string', 'max:100'],
            'father_name'         => ['required', 'string', 'max:100'],
            'gender'              => ['required', 'in:male,female'],
            'birth_date'          => ['required', 'date', 'before:today'],
            'mobile'              => ['required', 'string', 'size:11', 'regex:/^09[0-9]{9}$/'],
            'phone'               => ['nullable', 'string', 'regex:/^0[0-9]{10}$/'],
            'email'               => ['nullable', 'email', 'max:255'],
            'postal_code'         => ['required', 'digits:10'],
            'province'            => ['required', 'string', 'max:100'],
            'city'                => ['required', 'string', 'max:100'],
            'district'            => ['nullable', 'string', 'max:100'],
            'address'             => ['required', 'string', 'max:250'],
            'field_of_study'      => ['required', 'string', 'max:150'],
            'national_card_image' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'personal_photo'      => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'national_id.required'         => 'National ID is required.',
            'national_id.exists'           => 'Please complete the first step first.',
            'first_name.required'          => 'First name is required.',
            'last_name.required'           => 'Last name is required.',
            'father_name.required'         => 'Father\'s name is required.',
            'gender.required'              => 'Gender is required.',
            'gender.in'                    => 'Gender must be male or female.',
            'birth_date.required'          => 'Date of birth is required.',
            'birth_date.before'            => 'Date of birth must be before today.',
            'mobile.required'              => 'Mobile number is required.',
            'mobile.regex'                 => 'Invalid mobile number format.',
            'phone.regex'                  => 'Invalid landline format.',
            'postal_code.required'         => 'Postal code is required.',
            'postal_code.digits'           => 'Postal code must be 10 digits.',
            'province.required'            => 'Province is required.',
            'city.required'                => 'City is required.',
            'address.required'             => 'Address is required.',
            'address.max'                  => 'Address must be at most 250 characters.',
            'field_of_study.required'      => 'Field of study is required.',
            'national_card_image.required' => 'National ID card image is required.',
            'national_card_image.mimes'    => 'ID card must be JPG or PNG.',
            'national_card_image.max'      => 'ID card image must be at most 5 MB.',
            'personal_photo.required'      => 'Photo is required.',
            'personal_photo.mimes'         => 'Photo must be JPG or PNG.',
            'personal_photo.max'           => 'Photo must be at most 5 MB.',
        ];
    }
}

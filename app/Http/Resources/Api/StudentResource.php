<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Morilog\Jalali\Jalalian;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
   public function toArray($request)
    {
        return [
            'student_number'     => $this->student_number,
            'national_id'        => $this->national_id,
            'first_name'         => $this->first_name,
            'last_name'          => $this->last_name,
            'university_name'    => $this->university_name,
            'field_of_study'     => $this->field_of_study,
            'education_level'    => $this->education_level,
            'phone'              => $this->phone,
'birthdate' => $this->birthdate
    ? Jalalian::fromDateTime($this->birthdate)->format('Y/m/d')
    : null,


            
        'photo' => $this->photo ? url('storage/'.$this->photo) : null,

            
            'status'             => $this->status,
            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
        ];
    }
}

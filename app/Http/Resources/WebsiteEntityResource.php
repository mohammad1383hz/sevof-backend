<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebsiteEntityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'primary_entity' => $this->primary_entity,
            'core_entities' => $this->core_entities,
            'updated_at' => $this->updated_at,
        ];
    }
}
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->token,
            'subject'    => $this->subject,
            'category'   => $this->category,
            'priority'   => $this->priority,
            'status'     => $this->status,
            'created_at' => $this->created_at,
            'closed_at'  => $this->closed_at,
            'replies_count' => $this->whenCounted('replies'),
            'replies'    => TicketReplyResource::collection($this->whenLoaded('replies')),
        ];
    }
}

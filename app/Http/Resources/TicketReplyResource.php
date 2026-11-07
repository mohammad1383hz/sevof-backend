<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketReplyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'message'         => $this->message,
            'is_staff_reply'  => $this->is_staff_reply,
            'author_name'     => $this->author_name,
            'attachments'     => TicketAttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at'      => $this->created_at,
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Notification;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * NotificationOut (schemas/notification.py).
 *
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'message' => $this->message,
            'related_id' => $this->related_id,
            'is_read' => $this->is_read,
            'created_at' => Fmt::dateTime($this->created_at),
        ];
    }
}

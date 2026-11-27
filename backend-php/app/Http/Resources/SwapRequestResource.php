<?php

namespace App\Http\Resources;

use App\Models\SwapRequest;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SwapRequestOut (schemas/swap_request.py). A newly created request also
 * carries "warnings": the soft clashes, which don't block (SwapRequestCreated).
 *
 * @mixin SwapRequest
 */
class SwapRequestResource extends JsonResource
{
    /** @var list<array<string, mixed>>|null */
    private ?array $warnings = null;

    public function withWarnings(array $warnings): static
    {
        $this->warnings = $warnings;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'requester_teacher_id' => $this->requester_teacher_id,
            'entry_id' => $this->entry_id,
            'target_entry_id' => $this->target_entry_id,
            'status' => $this->status,
            'reason' => $this->reason,
            'decided_by' => $this->decided_by,
            'decided_at' => Fmt::dateTime($this->decided_at),
            'decision_note' => $this->decision_note,
            'created_at' => Fmt::dateTime($this->created_at),
            ...($this->warnings === null ? [] : ['warnings' => $this->warnings]),
        ];
    }
}

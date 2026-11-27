<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approve / reject body (SwapApprove, SwapReject in schemas/swap_request.py).
 * Approving: the note is optional and so is the body. Rejecting needs a note
 * with at least one non-space character ("required" refuses "   "); it is
 * stored trimmed, as Pydantic's strip_whitespace does.
 */
class DecideSwapRequest extends FormRequest
{
    public function rules(): array
    {
        $rejecting = str_ends_with($this->path(), '/reject');

        return ['note' => [$rejecting ? 'required' : 'nullable', 'string', 'max:5000']];
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return $note === null ? null : trim($note);
    }
}

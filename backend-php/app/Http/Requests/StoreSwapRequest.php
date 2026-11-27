<?php

namespace App\Http\Requests;

use App\Rules\NotBlankInteger;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/swap-requests body (SwapRequestCreate in schemas/swap_request.py). */
class StoreSwapRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'entry_id' => ['required', new NotBlankInteger, 'integer'],
            'target_entry_id' => ['required', new NotBlankInteger, 'integer'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ];
    }
}

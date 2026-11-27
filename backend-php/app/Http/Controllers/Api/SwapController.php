<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\DecideSwapRequest;
use App\Http\Requests\StoreSwapRequest;
use App\Http\Resources\SwapRequestResource;
use App\Models\TimetableEntry;
use App\Services\ConflictChecker;
use App\Services\SwapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Swap requests (backend/app/routers/swaps.py). A teacher asks to trade the time
 * of one of their classes with another class; the HOD of their department (or
 * an admin) approves or rejects. The rules live in SwapService.
 */
class SwapController extends Controller
{
    /** Teachers. A hard clash is refused now (409 with the list), not left for the approver. */
    public function store(StoreSwapRequest $request): JsonResponse
    {
        ['entry_id' => $entryId, 'target_entry_id' => $targetId] = $request->validated();
        if ((int) $entryId === (int) $targetId) {
            abort(400, 'A class cannot be swapped with itself');
        }
        $entry = TimetableEntry::find($entryId);
        $target = TimetableEntry::find($targetId);
        if ($entry === null || $target === null) {
            abort(404, 'Timetable entry not found');
        }
        if (! SwapService::canRequest($request->user(), $entry)) {
            abort(403, 'You can only request swaps for your own classes');
        }

        $conflicts = SwapService::previewConflicts($entry, $target);
        if ($hard = ConflictChecker::hard($conflicts)) {
            throw new ApiException(409, ['message' => SwapService::CONFLICT_MESSAGE, 'conflicts' => $hard]);
        }
        $warnings = array_values(array_filter($conflicts, fn ($c) => $c['severity'] !== 'hard'));

        $swap = SwapService::create((int) $entryId, (int) $targetId, $request->validated('reason'));

        return (new SwapRequestResource($swap))->withWarnings($warnings)->response()->setStatusCode(201);
    }

    /** Teachers see their own; a HOD their department's pending ones; an admin every pending one. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return SwapRequestResource::collection(SwapService::listFor($request->user()));
    }

    public function approve(DecideSwapRequest $request, int $swapId): SwapRequestResource
    {
        return new SwapRequestResource(SwapService::approve($swapId, $request->user(), $request->note()));
    }

    public function reject(DecideSwapRequest $request, int $swapId): SwapRequestResource
    {
        return new SwapRequestResource(SwapService::reject($swapId, $request->user(), $request->note()));
    }

    /** The requester, while it is still pending. */
    public function cancel(Request $request, int $swapId): SwapRequestResource
    {
        return new SwapRequestResource(SwapService::cancel($swapId, $request->user()));
    }
}

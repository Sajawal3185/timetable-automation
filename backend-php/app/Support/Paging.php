<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ?skip= & ?limit= paging plus the X-Total-Count header, as the exam papers
 * and materials lists do in Python (skip >= 0, 1 <= limit <= 200, default 50).
 * Order the query first (->orderByDesc('year')); Paging adds id as the last tiebreaker.
 *
 *   [$papers, $total] = Paging::get($query, $request);
 *   return Paging::response(ExamPaperResource::collection($papers), $total);
 */
final class Paging
{
    /**
     * @return array{0: Collection, 1: int} the page of rows, and how many rows match in total
     */
    public static function get(Builder $query, Request $request, int $defaultLimit = 50, int $maxLimit = 200): array
    {
        $valid = $request->validate([
            'skip' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', "max:$maxLimit"],
        ]);

        // Count before skip/limit; ordering doesn't change the count.
        $total = (clone $query)->toBase()->getCountForPagination();

        // id last, as a tiebreaker: rows with the same sort value (same year,
        // same created_at) would otherwise come back in any order, so a row
        // could show on two pages or on none.
        $query->orderBy($query->getModel()->getQualifiedKeyName());

        $rows = $query->skip((int) ($valid['skip'] ?? 0))->take((int) ($valid['limit'] ?? $defaultLimit))->get();

        return [$rows, $total];
    }

    /** A JSON list with X-Total-Count set (CORS exposes the header, see config/cors.php). */
    public static function response(mixed $data, int $total, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('X-Total-Count', (string) $total);
    }
}

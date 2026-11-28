<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GradeRecordResource;
use App\Models\GradeRecord;
use App\Services\Gpa\CgpaResult;
use App\Services\Gpa\CourseGrade;
use App\Services\GpaCalculator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * GPA / CGPA (backend/app/routers/gpa.py). Every endpoint needs a login (any role);
 * saved grades belong to the logged-in user only.
 */
class GpaController extends Controller
{
    private const DUPLICATE = 'This course is already saved for that term';

    private const SEASON_RANKS = ['winter' => 0, 'spring' => 1, 'summer' => 2, 'fall' => 3];

    public function gradeScale(): JsonResponse
    {
        $excluded = GpaCalculator::EXCLUDED_GRADES;
        sort($excluded, SORT_STRING);

        return response()->json(['grade_points' => GpaCalculator::GRADE_POINTS, 'excluded_grades' => $excluded]);
    }

    /** Stateless calculator: nothing is saved. */
    public function calculate(Request $request): JsonResponse
    {
        // VERIFY the limits against GpaRequest in backend/app/schemas/portal.py.
        $data = $request->validate([
            'terms' => ['required', 'array', 'min:1'],
            'terms.*.term' => ['required', 'string'],
            'terms.*.courses' => ['present', 'array'],
            'terms.*.courses.*.credit_hours' => ['required', 'numeric'],
            'terms.*.courses.*.grade' => ['required', 'string'],
            'terms.*.courses.*.course_code' => ['nullable', 'string'],
            'terms.*.courses.*.course_title' => ['nullable', 'string'],
            'previous_cgpa' => ['nullable', 'numeric'],
            'previous_credit_hours' => ['nullable', 'numeric'],
            'repeat_policy' => ['sometimes', 'in:all,latest,best'],
        ]);

        $terms = [];
        foreach ($data['terms'] as $t) {
            $terms[] = [$t['term'], array_map(fn (array $c) => new CourseGrade(
                (float) $c['credit_hours'], $c['grade'], $c['course_code'] ?? '', $c['course_title'] ?? '',
            ), $t['courses'])];
        }

        try {
            $result = GpaCalculator::calculateCgpa(
                $terms,
                isset($data['previous_cgpa']) ? (float) $data['previous_cgpa'] : null,
                isset($data['previous_credit_hours']) ? (float) $data['previous_credit_hours'] : null,
                $data['repeat_policy'] ?? 'all',
            );
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($this->present($result));
    }

    /** The logged-in user's saved grades, oldest first. */
    public function records(Request $request): AnonymousResourceCollection
    {
        return GradeRecordResource::collection(
            GradeRecord::where('user_id', $request->user()->id)->orderBy('id')->get()
        );
    }

    public function addRecord(Request $request): JsonResponse
    {
        // VERIFY the limits against GradeRecordIn in backend/app/schemas/portal.py.
        $data = $request->validate([
            'term' => ['required', 'string'],
            'course_code' => ['required', 'string'],
            'course_title' => ['nullable', 'string'],
            'credit_hours' => ['required', 'numeric'],
            'grade' => ['required', 'string'],
        ]);

        try {
            $grade = GpaCalculator::normalizeGrade($data['grade']);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $attributes = [
            'user_id' => $request->user()->id,
            'term' => $this->normalizeTerm($data['term']),
            'course_code' => mb_strtoupper(trim($data['course_code'])),
            'course_title' => $data['course_title'] ?? null,
            'credit_hours' => $data['credit_hours'],
            'grade' => $grade,
        ];

        if (GradeRecord::where('user_id', $attributes['user_id'])
            ->where('term', $attributes['term'])
            ->where('course_code', $attributes['course_code'])
            ->exists()) {
            abort(409, self::DUPLICATE);
        }

        try {
            $record = GradeRecord::create($attributes);
        } catch (UniqueConstraintViolationException) {   // the same course sent twice at once
            abort(409, self::DUPLICATE);
        }

        return (new GradeRecordResource($record->fresh()))->response()->setStatusCode(201);
    }

    /** Someone else's record answers 404 too, so ids don't leak. */
    public function deleteRecord(Request $request, int $recordId): Response
    {
        $record = GradeRecord::find($recordId);
        if ($record === null || $record->user_id !== $request->user()->id) {
            abort(404, 'Record not found');
        }
        $record->delete();

        return response()->noContent();
    }

    /** Term GPAs + CGPA from the saved grades, terms in time order. */
    public function summary(Request $request): JsonResponse
    {
        $q = $request->validate(['repeat_policy' => ['sometimes', 'in:all,latest,best']]);

        $rows = GradeRecord::where('user_id', $request->user()->id)->orderBy('id')->get();

        // Group by term in first-seen order (a list, so a term like "2025" can't turn into an int key).
        $labels = [];
        $groups = [];
        foreach ($rows as $r) {
            $i = array_search($r->term, $labels, true);
            if ($i === false) {
                $labels[] = $r->term;
                $groups[] = [$r->term, []];
                $i = count($labels) - 1;
            }
            $groups[$i][1][] = new CourseGrade((float) $r->credit_hours, $r->grade, $r->course_code, $r->course_title ?? '');
        }

        // Chronological: (year, season). usort is stable, like Python's sorted().
        usort($groups, fn (array $a, array $b) => $this->termSortKey($a[0]) <=> $this->termSortKey($b[0]));

        return response()->json($this->present(
            GpaCalculator::calculateCgpa($groups, null, null, $q['repeat_policy'] ?? 'all')
        ));
    }

    /** 'fall 2025' -> 'Fall 2025'. */
    private function normalizeTerm(string $term): string
    {
        $parts = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) === 2) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1)).mb_strtolower(mb_substr($parts[0], 1)).' '.$parts[1];
        }

        return trim($term);
    }

    /** @return array{0:int,1:int} */
    private function termSortKey(string $term): array
    {
        $parts = preg_split('/\s+/u', trim(mb_strtolower($term)), -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) === 2) {
            $year = preg_match('/^[+-]?\d+$/', $parts[1]) ? (int) $parts[1] : 0;

            return [$year, self::SEASON_RANKS[$parts[0]] ?? 99];
        }

        return [0, 0];
    }

    /** GpaResponse: same fields and order as Python. */
    private function present(CgpaResult $r): array
    {
        return [
            'cgpa' => $r->cgpa,
            'total_credit_hours' => $r->totalCreditHours,
            'total_quality_points' => $r->totalQualityPoints,
            'terms' => array_map(fn ($t) => [
                'term' => $t->term, 'gpa' => $t->gpa, 'credit_hours' => $t->creditHours, 'quality_points' => $t->qualityPoints,
            ], $r->terms),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExamPaperRequest;
use App\Http\Resources\ExamPaperResource;
use App\Models\Course;
use App\Models\ExamPaper;
use App\Services\ArchiveAccess;
use App\Services\FileStorage;
use App\Support\InstructorName;
use App\Support\Paging;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Exam repository (backend/app/routers/exams.py):
 *   POST   /api/exam-papers                 upload (teacher / HOD / admin), multipart form
 *   GET    /api/exam-papers                 search + filter (course_id, term, exam_type, year, q), X-Total-Count
 *   GET    /api/exam-papers/{id}
 *   GET    /api/exam-papers/{id}/download   the file, always as an attachment
 *   DELETE /api/exam-papers/{id}            the uploader or an admin/HOD
 */
class ExamPaperController extends Controller
{
    private const FOLDER = 'exam_papers';

    public function store(StoreExamPaperRequest $request): JsonResponse
    {
        $data = $request->validated();
        Course::find($data['course_id']) ?? abort(404, 'Course not found');

        $user = $request->user();
        $instructor = InstructorName::resolve($data['instructor_name'] ?? null, $user);
        $saved = FileStorage::save($request->file('file'), self::FOLDER, FileStorage::PAPER_EXTENSIONS);

        try {
            $paper = ExamPaper::create([
                'course_id' => (int) $data['course_id'],
                'title' => Text::strip($data['title']),
                'term' => Text::strip($data['term']),
                'exam_type' => $data['exam_type'],
                'year' => ($data['year'] ?? '') === '' ? null : (int) $data['year'],
                'instructor_name' => $instructor,
                'file_path' => $saved->relativePath,
                'original_filename' => $saved->originalFilename,
                'content_type' => $saved->contentType,
                'file_size' => $saved->size,
                'uploaded_by' => $user->id,
            ]);
        } catch (Throwable $e) {
            FileStorage::delete($saved->relativePath);   // do not leave an orphan file behind
            throw $e;
        }

        return (new ExamPaperResource($paper->refresh()))->response()->setStatusCode(201);
    }

    /** Newest year first, then newest upload. q searches title, instructor and course code/title. */
    public function index(Request $request): Response
    {
        $q = $request->validate([
            'course_id' => ['nullable', 'integer'],
            'term' => ['nullable', 'string'],
            'exam_type' => ['nullable', Rule::in(StoreExamPaperRequest::TYPES)],
            'year' => ['nullable', 'integer'],
            'q' => ['nullable', 'string'],
        ]);

        $query = ExamPaper::query();
        if (! empty($q['course_id'])) {   // 0 means "no filter", as in Python
            $query->where('exam_papers.course_id', $q['course_id']);
        }
        if (($q['term'] ?? '') !== '') {   // case-insensitive, like ilike
            $query->whereRaw("exam_papers.term LIKE ? ESCAPE '!'", [Text::likeExact(Text::strip($q['term']))]);
        }
        if (! empty($q['exam_type'])) {
            $query->where('exam_papers.exam_type', $q['exam_type']);
        }
        if (! empty($q['year'])) {
            $query->where('exam_papers.year', $q['year']);
        }
        if (($q['q'] ?? '') !== '') {
            $like = Text::likeContains(Text::strip($q['q']));
            $query->where(fn ($w) => $w
                ->whereRaw("exam_papers.title LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("exam_papers.instructor_name LIKE ? ESCAPE '!'", [$like])
                ->orWhereHas('course', fn ($c) => $c
                    ->whereRaw("courses.code LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("courses.title LIKE ? ESCAPE '!'", [$like])));
        }

        [$papers, $total] = Paging::get($query->orderByDesc('exam_papers.year')->orderByDesc('exam_papers.id'), $request);

        return Paging::response(ExamPaperResource::collection($papers), $total);
    }

    public function show(int $paperId): ExamPaperResource
    {
        return new ExamPaperResource($this->find($paperId));
    }

    public function download(int $paperId): StreamedResponse
    {
        $paper = $this->find($paperId);

        return FileStorage::download($paper->file_path, $paper->original_filename);
    }

    public function destroy(Request $request, int $paperId): Response
    {
        $paper = $this->find($paperId);
        if (! ArchiveAccess::canDelete($request->user(), $paper->uploaded_by)) {
            abort(403, 'Only the uploader or an admin/HOD can delete this paper');
        }
        $path = $paper->file_path;
        $paper->delete();
        FileStorage::delete($path);

        return response()->noContent();
    }

    private function find(int $paperId): ExamPaper
    {
        return ExamPaper::find($paperId) ?? abort(404, 'Exam paper not found');
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLectureMaterialRequest;
use App\Http\Resources\LectureMaterialResource;
use App\Models\Course;
use App\Models\LectureMaterial;
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
 * Lecture material archive (backend/app/routers/materials.py):
 *   POST   /api/lecture-materials                       upload (teacher / HOD / admin)
 *   GET    /api/lecture-materials                       flat list, X-Total-Count
 *   GET    /api/lecture-materials/by-course/{courseId}  grouped: course -> instructor -> files
 *   GET    /api/lecture-materials/{id}
 *   GET    /api/lecture-materials/{id}/download
 *   DELETE /api/lecture-materials/{id}                  the uploader or an admin/HOD
 */
class LectureMaterialController extends Controller
{
    private const FOLDER = 'lecture_materials';

    public function store(StoreLectureMaterialRequest $request): JsonResponse
    {
        $data = $request->validated();
        Course::find($data['course_id']) ?? abort(404, 'Course not found');

        $user = $request->user();
        $instructor = InstructorName::resolve($data['instructor_name'] ?? null, $user);
        $saved = FileStorage::save($request->file('file'), self::FOLDER, FileStorage::MATERIAL_EXTENSIONS);

        $term = Text::strip($data['term'] ?? null);
        try {
            $material = LectureMaterial::create([
                'course_id' => (int) $data['course_id'],
                'title' => Text::strip($data['title']),
                'material_type' => $data['material_type'],
                'instructor_name' => $instructor,
                'term' => $term !== '' ? $term : null,
                'description' => $data['description'] ?? null,
                'file_path' => $saved->relativePath,
                'original_filename' => $saved->originalFilename,
                'content_type' => $saved->contentType,
                'file_size' => $saved->size,
                'uploaded_by' => $user->id,
            ]);
        } catch (Throwable $e) {
            FileStorage::delete($saved->relativePath);
            throw $e;
        }

        return (new LectureMaterialResource($material->refresh()))->response()->setStatusCode(201);
    }

    /** Newest first. Filters: course_id, instructor_name (part of it), material_type, term, q (title or description). */
    public function index(Request $request): Response
    {
        $q = $request->validate([
            'course_id' => ['nullable', 'integer'],
            'instructor_name' => ['nullable', 'string'],
            'material_type' => ['nullable', Rule::in(StoreLectureMaterialRequest::TYPES)],
            'term' => ['nullable', 'string'],
            'q' => ['nullable', 'string'],
        ]);

        $query = LectureMaterial::query();
        if (! empty($q['course_id'])) {
            $query->where('course_id', $q['course_id']);
        }
        if (($q['instructor_name'] ?? '') !== '') {
            $query->whereRaw("instructor_name LIKE ? ESCAPE '!'", [Text::likeContains(Text::strip($q['instructor_name']))]);
        }
        if (! empty($q['material_type'])) {
            $query->where('material_type', $q['material_type']);
        }
        if (($q['term'] ?? '') !== '') {
            $query->whereRaw("term LIKE ? ESCAPE '!'", [Text::likeExact(Text::strip($q['term']))]);
        }
        if (($q['q'] ?? '') !== '') {
            $like = Text::likeContains(Text::strip($q['q']));
            $query->where(fn ($w) => $w
                ->whereRaw("title LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("description LIKE ? ESCAPE '!'", [$like]));
        }

        [$materials, $total] = Paging::get($query->orderByDesc('id'), $request);

        return Paging::response(LectureMaterialResource::collection($materials), $total);
    }

    /** One course, its files grouped by instructor. "Dr. Ali" and "dr. ali " land in one group. */
    public function byCourse(int $courseId): JsonResponse
    {
        $course = Course::find($courseId) ?? abort(404, 'Course not found');
        $rows = LectureMaterial::where('course_id', $courseId)->orderBy('instructor_name')->orderBy('id')->get();

        $groups = [];
        $names = [];   // the name on the first row of each group (instructor_name, id order), as Python shows it
        foreach ($rows as $material) {
            $key = mb_convert_case(Text::strip($material->instructor_name), MB_CASE_FOLD);   // Python casefold()
            if (! isset($names[$key])) {
                $names[$key] = $material->instructor_name;
            }
            $groups[$key][] = (new LectureMaterialResource($material))->resolve();
        }

        return response()->json([
            'course_id' => $course->id,
            'course_code' => $course->code,
            'course_title' => $course->title,
            'instructors' => array_map(
                fn ($key) => ['instructor_name' => $names[$key], 'materials' => $groups[$key]],
                array_keys($groups),
            ),
        ]);
    }

    public function show(int $materialId): LectureMaterialResource
    {
        return new LectureMaterialResource($this->find($materialId));
    }

    public function download(int $materialId): StreamedResponse
    {
        $material = $this->find($materialId);

        return FileStorage::download($material->file_path, $material->original_filename);
    }

    public function destroy(Request $request, int $materialId): Response
    {
        $material = $this->find($materialId);
        if (! ArchiveAccess::canDelete($request->user(), $material->uploaded_by)) {
            abort(403, 'Only the uploader or an admin/HOD can delete this material');
        }
        $path = $material->file_path;
        $material->delete();
        FileStorage::delete($path);

        return response()->noContent();
    }

    private function find(int $materialId): LectureMaterial
    {
        return LectureMaterial::find($materialId) ?? abort(404, 'Material not found');
    }
}

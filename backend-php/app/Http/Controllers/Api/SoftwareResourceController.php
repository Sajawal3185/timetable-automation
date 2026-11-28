<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SoftwareItemResource;
use App\Models\Course;
use App\Models\SoftwareResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Course software directory (routers/software.py). */
class SoftwareResourceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $p = $request->validate([
            'course_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string'],
            'q' => ['nullable', 'string'],
            'skip' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:300'],
        ]);

        $query = SoftwareResource::query()->orderBy('name')->orderBy('id');

        if (! empty($p['course_id'])) {
            $query->where('course_id', (int) $p['course_id']);
        }
        if (! empty($p['category'])) {
            $query->where('category', 'like', $p['category']);   // case-insensitive, like ilike
        }
        if (! empty($p['q'])) {
            $like = '%'.$p['q'].'%';
            $query->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)->orWhere('description', 'like', $like);
            });
        }

        $items = $query->offset((int) ($p['skip'] ?? 0))->limit((int) ($p['limit'] ?? 100))->get();

        return response()->json($items->map(fn ($i) => $this->out($i))->all());
    }

    public function show(int $itemId): JsonResponse
    {
        return response()->json($this->out($this->findOrFail($itemId)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $this->checkCourse($data['course_id'] ?? null);

        $item = SoftwareResource::create($data);

        return response()->json($this->out($item->refresh()), 201);
    }

    public function update(Request $request, int $itemId): JsonResponse
    {
        $data = $request->validate($this->rules(true));   // 422 before 404, like FastAPI
        $item = $this->findOrFail($itemId);
        $this->checkCourse($data['course_id'] ?? null);

        $item->update($data);

        return response()->json($this->out($item->refresh()));
    }

    public function destroy(int $itemId): Response
    {
        $this->findOrFail($itemId)->delete();

        return response()->noContent();
    }

    private function findOrFail(int $id): SoftwareResource
    {
        return SoftwareResource::find($id) ?? abort(404, 'Software resource not found');
    }

    private function checkCourse(mixed $courseId): void
    {
        if ($courseId !== null && ! Course::whereKey($courseId)->exists()) {
            abort(404, 'Course not found');
        }
    }

    /** Same limits as SoftwareIn / SoftwareUpdate. For update every field is optional, but required ones cannot be null. */
    private function rules(bool $partial): array
    {
        $req = $partial ? ['sometimes', 'required'] : ['required'];

        $httpUrl = function (string $attr, mixed $value, \Closure $fail) {
            $parts = is_string($value) ? parse_url($value) : false;
            $scheme = strtolower($parts['scheme'] ?? '');
            if (! $parts || ! in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
                $fail("$attr must be an http or https URL");
            }
        };

        return [
            'name' => [...$req, 'string', 'max:255'],
            'category' => [...$req, 'string', 'max:255'],
            'download_url' => [...$req, 'string', 'max:2000', $httpUrl],
            'description' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'],
            'course_id' => ['nullable', 'integer'],
        ];
    }

    private function out(SoftwareResource $item): array
    {
        return (new SoftwareItemResource($item))->resolve();
    }
}

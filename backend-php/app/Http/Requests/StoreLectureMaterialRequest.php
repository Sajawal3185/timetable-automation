<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/lecture-materials (multipart form). Python: the Form(...) parameters of upload_material. */
class StoreLectureMaterialRequest extends FormRequest
{
    /**
     * TODO: copy the values of MaterialType in backend/app/schemas/portal.py. "slides" and "notes" are
     * the ones the Python tests use; add the rest of the enum or those uploads give 422.
     */
    public const TYPES = ['notes', 'slides', 'assignment', 'other'];

    public function rules(): array
    {
        return [
            'course_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:200'],
            'material_type' => ['required', Rule::in(self::TYPES)],
            'instructor_name' => ['nullable', 'string', 'max:100'],
            'term' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:2000'],
            'file' => ['required', 'file'],
        ];
    }
}

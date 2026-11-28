<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/exam-papers (multipart form). Python: the Form(...) parameters of upload_exam_paper. */
class StoreExamPaperRequest extends FormRequest
{
    /** ExamType in backend/app/schemas/portal.py. */
    public const TYPES = ['quiz', 'midterm', 'final', 'sessional', 'other'];

    public function rules(): array
    {
        return [
            'course_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:200'],
            'term' => ['required', 'string', 'max:40'],        // e.g. "Fall 2025"
            'exam_type' => ['required', Rule::in(self::TYPES)],
            'year' => ['nullable', 'integer', 'between:1990,2100'],
            // HOD/admin only; a teacher's upload always uses their own name (InstructorName)
            'instructor_name' => ['nullable', 'string', 'max:100'],
            'file' => ['required', 'file'],
        ];
    }
}

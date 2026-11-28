<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\Department;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Paging;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The admin's users screen ("What We Need for a Fully Working Website", 5-C).
 * Not in FastAPI: new in PHP. Admin only (routes/api.php).
 *
 *   GET /api/admin/users        filter by role / department, or only staff not linked yet
 *   PUT /api/admin/users/{id}   link a teacher record, give a staff role, set the department
 *
 * Self-signup makes staff plain teachers with no teacher record. Linking is the
 * admin's "approval": it is what lets their timetable, swaps and makeup work.
 */
class AdminUserController extends Controller
{
    /** Staff roles an admin may give. Student accounts stay students (their email proves it). */
    private const STAFF_ROLES = [Roles::TEACHER, Roles::HOD, Roles::ADMIN];

    /**
     * Users by name. ?role=, ?department_id=, ?unlinked=true (staff with no teacher
     * record: the ones waiting to be linked), ?skip=&limit= (X-Total-Count).
     */
    public function index(Request $request): JsonResponse
    {
        $q = $request->validate([
            'role' => ['nullable', 'string', 'in:'.implode(',', Roles::ALL)],
            'department_id' => ['nullable', 'integer'],
            'unlinked' => ['sometimes', 'in:true,false,1,0'],
        ]);

        $query = User::query()->with('teacher')->orderBy('name')
            ->when($q['role'] ?? null, fn (Builder $b, $role) => $b->where('role', $role))
            ->when($q['department_id'] ?? null, fn (Builder $b, $id) => $b->where('department_id', $id))
            ->when(in_array($q['unlinked'] ?? null, ['true', '1'], true),
                fn (Builder $b) => $b->whereIn('role', self::STAFF_ROLES)->whereDoesntHave('teacher'));

        [$users, $total] = Paging::get($query, $request);

        return Paging::response(AdminUserResource::collection($users), $total);
    }

    /**
     * Changes only the fields sent:
     *   role           teacher, hod or admin; never your own; never for a student account
     *   department_id  an existing department, or null (not for a HOD)
     *   teacher_id     link this account to a teacher record (one account per record),
     *                  or null to unlink; staff accounts only
     */
    public function update(UpdateUserRequest $request, int $userId): AdminUserResource
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data, $userId, $request) {
            $user = User::whereKey($userId)->lockForUpdate()->first() ?? abort(404, 'User not found');

            if (array_key_exists('role', $data) && $data['role'] !== $user->role) {
                if ($user->id === $request->user()->id) {
                    abort(400, "You can't change your own role");
                }
                if ($user->role === Roles::STUDENT || ! in_array($data['role'], self::STAFF_ROLES, true)) {
                    abort(400, 'Only staff accounts can get a staff role (teacher, hod or admin)');
                }
                $user->role = $data['role'];
            }

            if (array_key_exists('department_id', $data)) {
                if ($data['department_id'] !== null && ! Department::whereKey($data['department_id'])->exists()) {
                    abort(400, 'Department not found');
                }
                $user->department_id = $data['department_id'];
            }
            // A HOD without a department could never decide any swap (BE-07).
            if ($user->role === Roles::HOD && $user->department_id === null) {
                abort(400, 'department_id is required when role is hod');
            }
            $user->save();

            if (array_key_exists('teacher_id', $data)) {
                $this->link($user, $data['teacher_id']);
            }

            return $user;
        });

        return new AdminUserResource($user->fresh('teacher'));
    }

    /** Points the teacher record at this account (and frees the record it had before), or unlinks. */
    private function link(User $user, ?int $teacherId): void
    {
        if ($teacherId === null) {
            Teacher::where('user_id', $user->id)->update(['user_id' => null]);

            return;
        }
        if (! in_array($user->role, self::STAFF_ROLES, true)) {
            abort(400, 'Only staff accounts can be linked to a teacher record');
        }
        $teacher = Teacher::whereKey($teacherId)->lockForUpdate()->first() ?? abort(400, 'Teacher not found');
        if ($teacher->user_id !== null && $teacher->user_id !== $user->id) {
            abort(409, 'That teacher record is already linked to another account');
        }

        Teacher::where('user_id', $user->id)->whereKeyNot($teacher->id)->update(['user_id' => null]);
        $teacher->update(['user_id' => $user->id]);
    }
}

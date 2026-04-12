<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AbsenceResource;
use App\Mail\AbsenceMarkedMail;
use App\Mail\AbsenceThresholdMail;
use App\Models\Absence;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class AbsenceController extends Controller
{
    /** GET /api/absences/student-history/{student} */
    public function studentHistory(Request $request, Student $student)
    {
        $user = $request->user();

        if ($user->role === 'student') {
            if ((int) $user->student->id !== (int) $student->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        } elseif ($user->role === 'teacher') {
            if (!$user->teacher) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $allowedGroupIds = $user->teacher->groups()->pluck('groups.id');
            if (!$allowedGroupIds->contains($student->group_id)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $absences = Absence::with(['student.user', 'teacher.user', 'group'])
            ->where('student_id', $student->id)
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->get();

        return AbsenceResource::collection($absences);
    }

    /** GET /api/absences */
    public function index(Request $request)
    {
        $query = Absence::with(['student.user', 'teacher.user', 'group']);

        $user = $request->user();
        $requestedStudentRaw = $request->input('student_id');
        $hasRequestedStudent = $request->exists('student_id') && $requestedStudentRaw !== '';
        $requestedStudentId = null;

        if ($hasRequestedStudent) {
            $requestedStudentId = (int) $requestedStudentRaw;
            Validator::make(
                ['student_id' => $requestedStudentRaw],
                ['student_id' => 'integer|exists:students,id']
            )->validate();
        }

        if ($user->role === 'student') {
            $query->where('student_id', $user->student->id);
            if ($hasRequestedStudent && (int) $requestedStudentId !== (int) $user->student->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        } elseif ($user->role === 'teacher') {
            if (!$user->teacher) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            if ($hasRequestedStudent) {
                $student = Student::query()->select(['id', 'group_id'])->find($requestedStudentId);
                $allowedGroupIds = $user->teacher->groups()->pluck('groups.id');

                if (!$student || !$allowedGroupIds->contains($student->group_id)) {
                    return response()->json(['message' => 'Forbidden'], 403);
                }

                $query->where('student_id', $requestedStudentId);
            } else {
                $query->where('teacher_id', $user->teacher->id);
            }
        } elseif ($hasRequestedStudent) {
            $query->where('student_id', $requestedStudentId);
        }

        if ($request->filled('group')) {
            $query->whereHas('group', fn ($q) => $q->where('name', $request->group));
        }
        if ($request->filled('status')) {
            $statuses = explode(',', $request->status);
            $query->whereIn('status', $statuses);
        }
        if ($request->filled('date')) {
            $query->whereDate('date', $request->date);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('student.user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        $sort = $request->get('sort', 'latest');
        $query->orderBy('date', $sort === 'latest' ? 'desc' : 'asc');

        if ($request->filled('limit')) {
            $query->limit((int) $request->limit);
        }

        return AbsenceResource::collection($query->get());
    }

    /** POST /api/absences */
    public function store(Request $request)
    {
        $data = $request->validate([
            'student_ids'   => 'required|array|min:1',
            'student_ids.*' => 'exists:students,id',
            'group_id'      => 'required|exists:groups,id',
            'date'          => 'required|date',
            'start_time'    => 'required|date_format:H:i',
            'end_time'      => 'required|date_format:H:i|after:start_time',
            'subject'       => 'required|string|max:100',
            'notes'         => 'nullable|string',
        ]);

        $teacher = $request->user()->teacher;

        $absenceIds = collect($data['student_ids'])->map(function ($studentId) use ($data, $teacher) {
            $absence = Absence::create([
                'student_id' => $studentId,
                'teacher_id' => $teacher->id,
                'group_id'   => $data['group_id'],
                'date'       => $data['date'],
                'start_time' => $data['start_time'],
                'end_time'   => $data['end_time'],
                'subject'    => $data['subject'],
                'notes'      => $data['notes'] ?? null,
            ]);

            return $absence->id;
        });

        $absences = Absence::with(['student.user', 'teacher.user', 'group'])
            ->whereIn('id', $absenceIds)
            ->get();

        $teacherName = $teacher->user->first_name . ' ' . $teacher->user->last_name;

        $adminUsers = User::where('role', 'admin')->get();
        foreach ($absences as $absence) {
            $studentName = $absence->student->user->first_name . ' ' . $absence->student->user->last_name;

            foreach ($adminUsers as $admin) {
                Notification::create([
                    'user_id' => $admin->id,
                    'title' => 'New Absence Recorded',
                    'message' => $teacherName . ' marked ' . $studentName . ' absent for ' . $absence->subject . '.',
                    'type' => 'warning',
                ]);
            }

            Notification::create([
                'user_id' => $absence->student->user_id,
                'title' => 'Absence Recorded',
                'message' => $teacherName . ' marked you absent for ' . $absence->subject . ' on ' . $absence->date . '.',
                'type' => 'error',
            ]);

            try {
                Mail::to($absence->student->user->email)
                    ->send(new AbsenceMarkedMail($absence));
            } catch (\Throwable $e) {
                \Log::error('Absence email failed to ' . $absence->student->user->email . ': ' . $e->getMessage());
            }

            try {
                $student = $absence->student;
                $totalAbsences = Absence::where('student_id', $student->id)->count();

                if ($totalAbsences >= 3 && ($totalAbsences === 3 || $totalAbsences % 5 === 0)) {
                    $totalHours = Absence::where('student_id', $student->id)->sum('hours');
                    $unjustifiedCount = Absence::where('student_id', $student->id)
                        ->where('status', '!=', 'justified')
                        ->count();

                    Mail::to($student->user->email)
                        ->send(new AbsenceThresholdMail(
                            $student,
                            $totalAbsences,
                            $totalHours,
                            $unjustifiedCount,
                            'student',
                            $student->user->first_name . ' ' . $student->user->last_name
                        ));

                    foreach ($adminUsers as $admin) {
                        Mail::to($admin->email)
                            ->send(new AbsenceThresholdMail(
                                $student,
                                $totalAbsences,
                                $totalHours,
                                $unjustifiedCount,
                                'admin',
                                $admin->first_name . ' ' . $admin->last_name
                            ));
                    }
                }
            } catch (\Throwable $e) {
                \Log::warning('Failed to send threshold alert: ' . $e->getMessage());
            }
        }

        return AbsenceResource::collection($absences);
    }
}

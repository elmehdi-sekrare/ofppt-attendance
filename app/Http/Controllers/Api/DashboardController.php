<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Absence;
use App\Models\Justification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /** GET /api/dashboard/admin */
    public function admin()
    {
        $totalStudents = Student::count();
        $totalTeachers = Teacher::count();
        $totalAbsenceHours = Absence::sum('hours');
        $totalPossibleHours = $totalStudents * 40; // approximate
        $absenceRate = $totalPossibleHours > 0
            ? round(($totalAbsenceHours / $totalPossibleHours) * 100, 1)
            : 0;

        return response()->json([
            'data' => [
                'total_students'  => $totalStudents,
                'total_teachers'  => $totalTeachers,
                'absence_rate'    => $absenceRate,
                'pending_reviews' => Justification::where('status', 'pending')->count(),
            ],
        ]);
    }

    /** GET /api/dashboard/teacher */
    public function teacher(Request $request)
    {
        $teacher = $request->user()->teacher;

        return response()->json([
            'data' => [
                'my_groups'       => $teacher->groups()->count(),
                'my_students'     => Student::whereIn('group_id', $teacher->groups()->pluck('groups.id'))->count(),
                'today_absences'  => Absence::where('teacher_id', $teacher->id)
                                        ->whereDate('date', today())->count(),
            ],
        ]);
    }

    /** GET /api/dashboard/student */
    public function student(Request $request)
    {
        $student = $request->user()->student;
        $absences = $student->absences;

        $totalHours = $absences->sum('hours');
        $justifiedHours = $absences->where('status', 'justified')->sum('hours');
        $unjustifiedHours = $absences->where('status', 'unjustified')->sum('hours');
        $pendingHours = $absences->where('status', 'pending')->sum('hours');

        // Approximate attendance rate (assuming 600 total hours per year)
        $totalPossible = 600;
        $attendanceRate = $totalPossible > 0
            ? round((1 - $totalHours / $totalPossible) * 100, 1)
            : 100;

        return response()->json([
            'data' => [
                'absence_hours'         => (float) $totalHours,
                'justified_hours'       => (float) $justifiedHours,
                'unjustified_hours'     => (float) $unjustifiedHours,
                'pending_hours'         => (float) $pendingHours,
                'pending_justifications'=> Justification::whereIn('absence_id', $absences->pluck('id'))
                                              ->where('status', 'pending')->count(),
                'unjustified_count'     => $absences->where('status', 'unjustified')->count(),
                'attendance_rate'       => $attendanceRate,
            ],
        ]);
    }

    /** GET /api/dashboard/chart — monthly attendance/absence data */
    public function chart()
    {
        $driver = DB::getDriverName();
        $today = Carbon::today();
        $start = $today->copy()->subDays(29); // last 30 days
        $totalStudents = max(Student::count(), 1);

        if ($driver === 'pgsql') {
            $rows = Absence::select(
                    DB::raw('date::text as date_str'),
                    DB::raw('COALESCE(SUM(hours), 0) as total_hours'),
                    DB::raw('COUNT(*) as absence_count')
                )
                ->where('date', '>=', $start->toDateString())
                ->where('date', '<=', $today->toDateString())
                ->groupBy('date')
                ->get()
                ->keyBy('date_str');
        } else {
            $rows = Absence::select(
                    DB::raw('DATE(date) as date_str'),
                    DB::raw('COALESCE(SUM(hours), 0) as total_hours'),
                    DB::raw('COUNT(*) as absence_count')
                )
                ->where('date', '>=', $start->toDateString())
                ->where('date', '<=', $today->toDateString())
                ->groupBy(DB::raw('DATE(date)'))
                ->get()
                ->keyBy('date_str');
        }

        $data = [];
        for ($i = 0; $i < 30; $i++) {
            $dt = $start->copy()->addDays($i);
            $key = $dt->toDateString();
            $row = $rows->get($key);
            $absenceHours = $row ? (float) $row->total_hours : 0;
            $absenceCount = $row ? (int) $row->absence_count : 0;
            $data[] = [
                'day'        => $dt->format('D'),
                'date'       => $dt->format('Y-m-d'),
                'attendance' => round(max(0, ($totalStudents * 8) - $absenceHours), 1), // 8h per day
                'absences'   => round($absenceHours, 1),
                'count'      => $absenceCount,
            ];
        }
        return response()->json(['data' => $data]);
    }

    /** GET /api/dashboard/heatmap — weekly absence heatmap (4 weeks × 5 days) */
    public function heatmap()
    {
        $driver = DB::getDriverName();
        $today = Carbon::today();
        // Go back 4 full weeks from today (Mon–Fri)
        $startOfCurrentWeek = $today->copy()->startOfWeek(Carbon::MONDAY);
        $start = $startOfCurrentWeek->copy()->subWeeks(3);

        if ($driver === 'pgsql') {
            $rows = Absence::select(
                    DB::raw('date::text as date_str'),
                    DB::raw('COALESCE(SUM(hours), 0) as total_hours')
                )
                ->where('date', '>=', $start->toDateString())
                ->where('date', '<=', $today->toDateString())
                ->groupBy('date')
                ->get()
                ->keyBy('date_str');
        } else {
            // MySQL / SQLite
            $rows = Absence::select(
                    DB::raw('DATE(date) as date_str'),
                    DB::raw('COALESCE(SUM(hours), 0) as total_hours')
                )
                ->where('date', '>=', $start->toDateString())
                ->where('date', '<=', $today->toDateString())
                ->groupBy(DB::raw('DATE(date)'))
                ->get()
                ->keyBy('date_str');
        }

        $weeks = [];
        for ($w = 0; $w < 4; $w++) {
            $weekStart = $start->copy()->addWeeks($w);
            $weekLabel = 'W' . $weekStart->weekOfYear;
            $days = [];
            for ($d = 0; $d < 5; $d++) { // Mon-Fri
                $dt = $weekStart->copy()->addDays($d);
                $dateStr = $dt->toDateString();
                $row = $rows->get($dateStr);
                $hours = $row ? (float) $row->total_hours : 0;
                $days[] = round($hours, 1);
            }
            $weeks[] = ['week' => $weekLabel, 'days' => $days];
        }

        return response()->json(['data' => $weeks]);
    }
}

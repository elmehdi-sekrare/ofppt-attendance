<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        $groupId = DB::table('groups')->orderBy('id')->value('id');
        if (!$groupId) {
            $groupId = DB::table('groups')->insertGetId([
                'name' => 'DEMO',
                'code' => 'DEMO',
                'level' => 'Demo',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $adminId = $this->upsertUser(
            'admin@ofppt.ma',
            'Demo',
            'Admin',
            'admin',
            $now
        );

        $teacherUserId = $this->upsertUser(
            'teacher@ofppt.ma',
            'Demo',
            'Teacher',
            'teacher',
            $now
        );

        $studentUserId = $this->upsertUser(
            'student@ofppt.ma',
            'Demo',
            'Student',
            'student',
            $now
        );

        $teacherId = DB::table('teachers')->where('user_id', $teacherUserId)->value('id');
        if ($teacherId) {
            DB::table('teachers')
                ->where('id', $teacherId)
                ->update([
                    'subject' => 'Demo Subject',
                    'updated_at' => $now,
                ]);
        } else {
            $teacherId = DB::table('teachers')->insertGetId([
                'user_id' => $teacherUserId,
                'subject' => 'Demo Subject',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('teacher_groups')->insertOrIgnore([
            'teacher_id' => $teacherId,
            'group_id' => $groupId,
        ]);

        $studentId = DB::table('students')->where('user_id', $studentUserId)->value('id');
        $studentCne = sprintf('DEMO%06d', $studentUserId);

        if ($studentId) {
            DB::table('students')
                ->where('id', $studentId)
                ->update([
                    'cne' => $studentCne,
                    'group_id' => $groupId,
                    'phone' => null,
                    'updated_at' => $now,
                ]);
        } else {
            DB::table('students')->insert([
                'user_id' => $studentUserId,
                'cne' => $studentCne,
                'phone' => null,
                'group_id' => $groupId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $emails = ['admin@ofppt.ma', 'teacher@ofppt.ma', 'student@ofppt.ma'];

        $userIds = DB::table('users')->whereIn('email', $emails)->pluck('id');
        $teacherIds = DB::table('teachers')->whereIn('user_id', $userIds)->pluck('id');

        if ($teacherIds->isNotEmpty()) {
            DB::table('teacher_groups')->whereIn('teacher_id', $teacherIds)->delete();
        }

        DB::table('users')->whereIn('email', $emails)->delete();
    }

    private function upsertUser(
        string $email,
        string $firstName,
        string $lastName,
        string $role,
        $now
    ): int {
        DB::table('users')->updateOrInsert(
            ['email' => $email],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
                'must_change_password' => false,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        return (int) DB::table('users')->where('email', $email)->value('id');
    }
};

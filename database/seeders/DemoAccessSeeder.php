<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoAccessSeeder extends Seeder
{
    public function run(): void
    {
        $demoPassword = Hash::make('password');

        User::updateOrCreate(
            ['email' => 'admin@ofppt.ma'],
            [
                'first_name' => 'Demo',
                'last_name' => 'Admin',
                'password' => $demoPassword,
                'role' => 'admin',
                'is_active' => true,
                'must_change_password' => false,
            ]
        );

        $defaultGroup = Group::query()->first();
        if (!$defaultGroup) {
            $defaultGroup = Group::create([
                'name' => 'DEMO',
                'code' => 'DEMO',
                'level' => 'Demo',
            ]);
        }

        $teacherUser = User::updateOrCreate(
            ['email' => 'teacher@ofppt.ma'],
            [
                'first_name' => 'Demo',
                'last_name' => 'Teacher',
                'password' => $demoPassword,
                'role' => 'teacher',
                'is_active' => true,
                'must_change_password' => false,
            ]
        );

        $teacher = Teacher::updateOrCreate(
            ['user_id' => $teacherUser->id],
            ['subject' => 'Demo Subject']
        );
        $teacher->groups()->syncWithoutDetaching([$defaultGroup->id]);

        $studentUser = User::updateOrCreate(
            ['email' => 'student@ofppt.ma'],
            [
                'first_name' => 'Demo',
                'last_name' => 'Student',
                'password' => $demoPassword,
                'role' => 'student',
                'is_active' => true,
                'must_change_password' => false,
            ]
        );

        Student::updateOrCreate(
            ['user_id' => $studentUser->id],
            [
                'cne' => 'DEMO000001',
                'group_id' => $defaultGroup->id,
                'phone' => null,
            ]
        );
    }
}

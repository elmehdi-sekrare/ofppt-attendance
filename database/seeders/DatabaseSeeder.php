<?php

namespace Database\Seeders;

use Database\Seeders\DemoAccessSeeder;
use App\Models\User;
use App\Models\Group;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Notification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Disable foreign key checks
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        } elseif ($driver === 'pgsql') {
            DB::statement('SET session_replication_role = replica');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        // Clear all tables
        DB::table('notifications')->delete();
        DB::table('justifications')->delete();
        DB::table('absences')->delete();
        DB::table('teacher_groups')->delete();
        DB::table('students')->delete();
        DB::table('teachers')->delete();
        DB::table('groups')->delete();
        DB::table('personal_access_tokens')->delete();
        DB::table('users')->delete();

        // Re-enable foreign key checks
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        } elseif ($driver === 'pgsql') {
            DB::statement('SET session_replication_role = DEFAULT');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $defaultPassword = 'password123';

        // Admin
        User::create([
            'first_name' => 'Admin',
            'last_name'  => 'OFPPT',
            'email'      => 'admin@ofppt.com',
            'password'   => Hash::make($defaultPassword),
            'role'       => 'admin',
            'must_change_password' => false,
        ]);

        // Groups
        $g1 = Group::create(['name' => 'DEV201', 'code' => 'DEV201', 'level' => '2ème année']);
        $g2 = Group::create(['name' => 'DEVOFWS', 'code' => 'DEVOFWS', 'level' => '1ère année']);

        // Teachers (4) — fixed emails for easy login, ALL have access to BOTH groups
        $teachers = [
            ['first_name' => 'Salma', 'last_name' => 'Karim', 'email' => 'salma.karim@ofppt.com', 'subject' => 'Développement Web', 'groups' => [$g1->id, $g2->id]],
            ['first_name' => 'Omar', 'last_name' => 'Haddad', 'email' => 'omar.haddad@ofppt.com', 'subject' => 'Base de données', 'groups' => [$g1->id, $g2->id]],
            ['first_name' => 'Nadia', 'last_name' => 'Fassi', 'email' => 'nadia.fassi@ofppt.com', 'subject' => 'Réseaux', 'groups' => [$g1->id, $g2->id]],
            ['first_name' => 'Yassine', 'last_name' => 'Amrani', 'email' => 'yassine.amrani@ofppt.com', 'subject' => 'PHP / Laravel', 'groups' => [$g1->id, $g2->id]],
        ];

        foreach ($teachers as $t) {
            $user = User::create([
                'first_name' => $t['first_name'],
                'last_name'  => $t['last_name'],
                'email'      => $t['email'],
                'password'   => Hash::make($defaultPassword),
                'role'       => 'teacher',
                'must_change_password' => true,
            ]);

            $teacher = Teacher::create([
                'user_id' => $user->id,
                'subject' => $t['subject'],
            ]);

            $teacher->groups()->attach($t['groups']);
        }

        // Students (20 per group) — predictable emails
        $firstNames = ['Youssef', 'Fatima', 'Ahmed', 'Sara', 'Khalid', 'Amina', 'Ilyas', 'Meryem', 'Hamza', 'Imane', 'Mehdi', 'Hajar', 'Rachid', 'Asma', 'Ayoub', 'Kawtar', 'Soufiane', 'Chaima', 'Karim', 'Lina'];
        $lastNames = ['Bennani', 'Zahra', 'Tazi', 'El Amrani', 'Fassi', 'Karim', 'El Idrissi', 'Ouali', 'Bouzid', 'Lamrani', 'Alaoui', 'Gharbi', 'Berrada', 'Safi', 'Naciri', 'Daoudi', 'Kabbaj', 'Rahmani', 'El Hammadi', 'Chakir'];

        $groups = [$g1, $g2];
        foreach ($groups as $groupIndex => $group) {
            $groupCode = $groupIndex === 0 ? 'dev201' : 'devofws';
            for ($i = 1; $i <= 20; $i++) {
                $firstName = $firstNames[($i - 1) % count($firstNames)];
                $lastName = $lastNames[($i - 1 + $groupIndex) % count($lastNames)];
                $email = strtolower(str_replace(' ', '', $firstName)) . '.' . $groupCode . $i . '@ofppt.com';
                $cne = sprintf('F%s%03d', $groupIndex + 1, $i);

                // Sara Fassi (DEVOFWS group, student #4) uses real email for testing
                if ($groupCode === 'devofws' && $i === 4) {
                    $email = 'elmehdisekrare@gmail.com';
                }

                $user = User::create([
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                    'email'      => $email,
                    'password'   => Hash::make($defaultPassword),
                    'role'       => 'student',
                    'must_change_password' => true,
                ]);

                Student::create([
                    'user_id'  => $user->id,
                    'cne'      => $cne,
                    'group_id' => $group->id,
                ]);
            }
        }

        $this->call(DemoAccessSeeder::class);
    }
}

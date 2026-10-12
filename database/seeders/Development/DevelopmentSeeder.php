<?php

namespace Database\Seeders\Development;

use App\Domain\Organization\Actions\CreateUser;
use App\Models\Client;
use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo data for local development only. Names here are sample data and must
 * never be referenced by application logic. Demo accounts use the password
 * "password".
 */
class DevelopmentSeeder extends Seeder
{
    public function run(CreateUser $createUser): void
    {
        $positions = collect([
            ['code' => 'STAFF', 'name' => 'Staff', 'level' => 10],
            ['code' => 'SPV', 'name' => 'Supervisor', 'level' => 20],
            ['code' => 'COORD', 'name' => 'Coordinator', 'level' => 30],
            ['code' => 'MGR', 'name' => 'Manager', 'level' => 40],
            ['code' => 'HEAD', 'name' => 'Head', 'level' => 50],
        ])->mapWithKeys(fn (array $row, int $index): array => [
            $row['code'] => Position::query()->firstOrCreate(['code' => $row['code']], [...$row, 'sort_order' => ($index + 1) * 10]),
        ]);

        $technology = Department::query()->firstOrCreate(
            ['code' => 'TECH'],
            ['name' => 'Technology', 'description' => 'Induk divisi teknologi.', 'sort_order' => 10],
        );

        $departments = collect([
            ['code' => 'TS', 'name' => 'Technical Support', 'parent_id' => $technology->id],
            ['code' => 'DEV', 'name' => 'Programmer', 'parent_id' => $technology->id],
            ['code' => 'QA', 'name' => 'QA', 'parent_id' => $technology->id],
            ['code' => 'UIUX', 'name' => 'UI/UX', 'parent_id' => $technology->id],
            ['code' => 'FIN', 'name' => 'Finance', 'parent_id' => null],
            ['code' => 'HR', 'name' => 'HR', 'parent_id' => null],
            ['code' => 'OPS', 'name' => 'Operational', 'parent_id' => null],
            ['code' => 'MGT', 'name' => 'Management', 'parent_id' => null],
        ])->mapWithKeys(fn (array $row, int $index): array => [
            $row['code'] => Department::query()->firstOrCreate(['code' => $row['code']], [...$row, 'sort_order' => ($index + 2) * 10]),
        ]);

        $demoUsers = [
            ['Rina Manajemen', 'rina', 'Management', [['MGT', 'HEAD', true, true]]],
            ['Andi Programmer', 'andi', 'Staff', [['DEV', 'COORD', true, true], ['QA', 'STAFF', false, false]]],
            ['Budi Programmer', 'budi', 'Staff', [['DEV', 'STAFF', true, false]]],
            ['Sari Support', 'sari', 'Staff', [['TS', 'SPV', true, true]]],
            ['Dewi Designer', 'dewi', 'Staff', [['UIUX', 'STAFF', true, false]]],
        ];

        foreach ($demoUsers as [$name, $username, $role, $memberships]) {
            if (User::query()->where('username', $username)->exists()) {
                continue;
            }

            $createUser->handle([
                'name' => $name,
                'username' => $username,
                'email' => $username.'@harmaworkspace.test',
                'password' => 'password',
                'role' => $role,
                'memberships' => array_map(fn (array $membership): array => [
                    'department_id' => $departments[$membership[0]]->id,
                    'position_id' => $positions[$membership[1]]->id,
                    'is_primary' => $membership[2],
                    'is_leader' => $membership[3],
                ], $memberships),
            ], actor: null);
        }

        if (Client::query()->doesntExist()) {
            Client::factory()->count(12)->create();
        }
    }
}

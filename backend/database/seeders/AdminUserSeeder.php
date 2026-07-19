<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $emails = ['admin@paramgroup.in', 'admin@paramgold.in'];

        foreach ($emails as $email) {
            $user = User::query()->firstOrNew(['email' => $email]);

            $user->name = 'Admin';
            $user->job_role = 'Admin';

            if (! $user->exists) {
                $user->role = 'employee';
                $user->password = Hash::make('password');
            }

            $user->save();
        }
    }
}

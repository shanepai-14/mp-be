<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sampleAdminUser = [
            "username_email" => "wlocate@gmail.com",
            "password" => Hash::make("adminadmin"),
            "full_name" => "Admin Admin",
            "transporter_id" => 1,
            "contact_no" => "+639123123",
            "user_role" => 1
        ];

        User::create($sampleAdminUser);
    }
}

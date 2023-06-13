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
            [
                "username_email" => "wlocate@gmail.com",
                "password" => Hash::make("adminadmin"),
                "full_name" => "Admin Admin",
                "vendor_id" => 1,
                "contact_no" => "+639123123",
                "user_role" => 1
            ],
            [
                "username_email" => "athena@gmail.com",
                "password" => Hash::make("@th3n@"),
                "full_name" => "Admin Athena",
                "vendor_id" => 1,
                "contact_no" => "+639123123",
                "user_role" => 1
            ]
        ];

        foreach ($sampleAdminUser as $value) {
            User::create($value);
        }
    }
}

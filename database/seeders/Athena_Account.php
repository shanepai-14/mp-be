<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class Athena_Account extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $athenaAccount = [
            "username_email" => "athena@gmail.com",
            "password" => Hash::make("@th3n@"),
            "full_name" => "Admin Athena",
            "vendor_id" => 1,
            "contact_no" => "+639123123",
            "user_role" => 1
        ];

        User::create($athenaAccount);
    }
}

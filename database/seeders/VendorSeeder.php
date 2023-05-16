<?php

namespace Database\Seeders;

use App\Models\Vendor;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class VendorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $vendor = [
            "id" => 1,
            "vendor_name"=> "Athena",
            "vendor_address"=> "Singapore",
            "vendor_code"=> "ATH",
            "vendor_key"=> "1234567890"
        ];

        Vendor::create($vendor);
    }
}

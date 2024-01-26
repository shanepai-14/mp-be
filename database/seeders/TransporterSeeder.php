<?php

namespace Database\Seeders;

use App\Models\Transporter;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TransporterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $transporter = [
            "transporter_name"=> "Athena",
            "transporter_address"=> "Singapore",
            "transporter_code"=> "ATH",
            "transporter_key"=> "1234567890"
        ];

        Transporter::create($transporter);
    }
}

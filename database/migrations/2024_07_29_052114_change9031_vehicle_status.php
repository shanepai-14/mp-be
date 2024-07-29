<?php

use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        $vehicle = Vehicle::where('transporter_id', '=', 3)
            ->where('device_id_plate_no', '=', "9031")->first();
        if ($vehicle) {
            VehicleAssignment::where('vehicle_id', '=', $vehicle['id'])->update([
               'vehicle_status' => 4
            ]);
        }
    }

    public function down(): void
    {
        //
    }
};

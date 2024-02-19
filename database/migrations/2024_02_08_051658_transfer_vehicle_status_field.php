<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Remove vehicle_status field from Vehicle table
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('vehicle_status');
        });

        // Add vehicle_status field to Vehicle_Assignments table
        Schema::table('vehicle_assignments', function (Blueprint $table) {
            $table->tinyInteger('vehicle_status')->default(4);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
};

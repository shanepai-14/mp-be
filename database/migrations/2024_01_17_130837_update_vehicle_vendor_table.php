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
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['driver_name', 'mileage']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            // Rename fields
            $table->renameColumn('vendor_id', 'transporter_id');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['wl_ip', 'wl_port']);

            // Rename fields
            $table->renameColumn('vendor_name', 'transporter_name');
            $table->renameColumn('vendor_address', 'transporter_address');
            $table->renameColumn('vendor_contact_no', 'transporter_contact_no');
            $table->renameColumn('vendor_code', 'transporter_code');
            $table->renameColumn('vendor_key', 'transporter_key');
            $table->renameColumn('vendor_email', 'transporter_email');
        });

        Schema::rename('vendors', 'transporters');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('vehicles', function (Blueprint $table) {
            //
         });

        Schema::table('vendors', function (Blueprint $table) {
            //
         });
    }
};

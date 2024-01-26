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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_name', 100);
            $table->string('customer_address')->nullable();
            $table->string('customer_contact_no', 50)->nullable();
            $table->string('customer_email', 30)->nullable();
            $table->string('customer_code', 10);
            $table->unsignedBigInteger('transporter_id');
            $table->unsignedBigInteger('register_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('transporter_id')->references('id')->on('transporters')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('register_by_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('updated_by_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('customers');
    }
};

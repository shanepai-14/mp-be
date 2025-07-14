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
        Schema::create('connection_pool_stats', function (Blueprint $table) {
            $table->id();
            $table->string('pool_key', 100)->index(); // e.g., "10.21.14.8:1403"
            $table->string('process_id', 20)->index(); // PHP process ID
            $table->integer('created')->default(0); // Connections created
            $table->integer('success')->default(0); // Successful transmissions
            $table->integer('reused')->default(0); // Connection reuses
            $table->integer('send_failed')->default(0); // Failed sends
            $table->integer('connection_failed')->default(0); // Failed connections
            $table->string('last_action', 50)->nullable(); // Last action performed
            $table->timestamp('last_action_time')->nullable(); // When last action occurred
            $table->timestamps();
            
            // Composite unique index to ensure one record per pool per process
            $table->unique(['pool_key', 'process_id']);
            
            // Indexes for performance
            $table->index(['pool_key', 'updated_at']);
            $table->index('last_action_time');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('connection_pool_stats');
    }
};
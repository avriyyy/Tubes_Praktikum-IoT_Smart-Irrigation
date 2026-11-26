<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sensor_readings', function (Blueprint $table) {
            // Nilai kendali PID dari ESP32 (dikirim firmware mulai 25 Nov 2026).
            // Null untuk data lama yang direkam sebelum firmware mengirimnya.
            $table->float('pid_output')->nullable()->after('vpd');
            $table->float('pid_error')->nullable()->after('pid_output');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sensor_readings', function (Blueprint $table) {
            $table->dropColumn(['pid_output', 'pid_error']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_daily_report_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_id')->constrained('leave_info')->cascadeOnDelete();
            $table->unsignedBigInteger('dailyreport_id');
            $table->date('report_date');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['leave_id', 'report_date']);
            $table->index('dailyreport_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_daily_report_entries');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_info', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('emp_id');
            $table->unsignedBigInteger('filed_by_emp_id');
            $table->foreignId('leave_type_id')->constrained('leave_type')->restrictOnDelete();
            $table->decimal('duration', 8, 2);
            $table->decimal('paid_days', 8, 2)->default(0);
            $table->decimal('lwop_days', 8, 2)->default(0);
            $table->string('day_type', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->text('leave_reason');
            $table->string('leave_attachment')->nullable();
            $table->string('status', 30);
            $table->text('cancellation_reason')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['emp_id', 'status', 'start_date']);
            $table->index(['emp_id', 'start_date']);
            $table->index(['status', 'start_date']);
            $table->index(['start_date', 'end_date']);
            $table->index(['filed_by_emp_id', 'created_at']);
            $table->index(['leave_type_id', 'status', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_info');
    }
};

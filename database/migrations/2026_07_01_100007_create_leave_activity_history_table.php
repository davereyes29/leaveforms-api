<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_activity_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_id')->constrained('leave_info')->cascadeOnDelete();
            $table->unsignedBigInteger('performed_by_emp_id');
            $table->string('activity_type', 30);
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['leave_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_activity_history');
    }
};

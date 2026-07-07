<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_approval_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_id')->constrained('leave_info')->cascadeOnDelete();
            $table->foreignId('requirement_id')->constrained('leave_approval_requirement')->restrictOnDelete();
            $table->unsignedBigInteger('approver_emp_id');
            $table->unsignedTinyInteger('approval_level')->nullable();
            $table->string('approver_type', 20);
            $table->boolean('is_approved');
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('requirement_id');
            $table->index(['leave_id', 'created_at']);
            $table->index(['approver_emp_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_approval_history');
    }
};

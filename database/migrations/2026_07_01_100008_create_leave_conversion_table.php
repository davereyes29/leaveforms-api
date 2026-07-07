<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_conversion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_id')->constrained('leave_info')->restrictOnDelete();
            $table->foreignId('from_leave_type_id')->constrained('leave_type')->restrictOnDelete();
            $table->foreignId('to_leave_type_id')->constrained('leave_type')->restrictOnDelete();
            $table->string('med_cert_attachment')->nullable();
            $table->unsignedBigInteger('reviewed_by_emp_id')->nullable();
            $table->string('status', 20);
            $table->text('remarks')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('leave_id');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_conversion');
    }
};
